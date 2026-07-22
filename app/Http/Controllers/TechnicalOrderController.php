<?php

namespace App\Http\Controllers;

use App\Exports\TechnicalOrdersExport;
use App\Models\Contract;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\TechnicalOrderAssignedTechnician;
use App\Notifications\TechnicalOrderCreatedClient;
use App\Notifications\TechnicalOrderFinishedClient;
use App\Reports\Support\OrderDetailMap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Controlador de Órdenes Técnicas
 *
 * Gestiona el ciclo de vida completo de los trabajos de campo:
 *
 *   Pendiente → Asignada → Prefinalizada → Cerrada
 *                  ↓             ↓
 *              Rechazada     Pendiente (devuelta por supervisor)
 *
 * Flujo:
 * 1. Oficina crea la orden desde el contrato (store) o el sistema la
 *    genera automáticamente (ej: reconexión al registrar un pago).
 * 2. Oficina asigna un técnico (update) → estado "Asignada".
 * 3. El técnico la ve en "Mis órdenes" (myTechnicalOrders), la ejecuta
 *    y la procesa (processOrder): reporta observaciones, solución,
 *    evidencia fotográfica y material usado — que se descuenta de SU
 *    almacén personal (cada técnico tiene un Warehouse con user_id).
 *    El estado pasa a "Prefinalizada". El técnico también puede
 *    rechazarla (orderReject).
 * 4. Un supervisor la verifica (orderVerification →
 *    verificationOrderProcess): la cierra (y actualiza el estado del
 *    contrato según el detalle de la orden) o la devuelve a Pendiente.
 */
class TechnicalOrderController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:technicals_orders.index')->only('index');
        $this->middleware('check.permission:technicals_orders.create')->only('create');
        $this->middleware('check.permission:technicals_orders.store')->only('store');
        $this->middleware('check.permission:technicals_orders.edit')->only('edit');
        $this->middleware('check.permission:technicals_orders.update')->only('update');
        $this->middleware('check.permission:technicals_orders.destroy')->only('destroy');
        $this->middleware('check.permission:technicals_orders.my_technical_orders')->only('myTechnicalOrders');
        $this->middleware('check.permission:technicals_orders.getSerialNumbers')->only('getSerialNumbers');
        $this->middleware('check.permission:technicals_orders.verification')->only('orderVerification');
        $this->middleware('check.permission:technicals_orders.process')->only('processOrder');
        $this->middleware('check.permission:technical_order.verification_process')->only('verificationOrderProcess');
        $this->middleware('check.permission:technical_orders.reject')->only('orderReject');
    }

    /**
     * Listado de órdenes técnicas de la sucursal con filtros.
     *
     * COMPORTAMIENTO POR DEFECTO: sin filtros se muestran solo las
     * órdenes ACTIVAS (todo excepto "Cerrada"). Las cerradas crecen
     * sin límite con los años; para consultarlas se usa el filtro de
     * estado o el rango de fechas. Así el tablero operativo siempre
     * muestra el trabajo en curso sin ocultar órdenes viejas pendientes.
     *
     * La tabla usa DataTables del lado del cliente, por eso se retorna
     * la colección completa filtrada (no paginada). Las relaciones se
     * precargan con with() para evitar consultas N+1.
     */
    public function index(Request $request): View
    {
        $branchId = session('branch_id');

        // Técnicos de la sucursal (para el modal de asignación)
        $users = User::whereHas('branches', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })->get();

        $query = TechnicalOrder::where('branch_id', $branchId);

        // Búsqueda por campo (lista blanca — evita usar columnas
        // arbitrarias del request como nombre de columna SQL)
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            switch ($field) {
                // Columnas directas de la tabla
                case 'type':
                case 'detail':
                case 'status':
                    $query->where($field, 'like', "%{$value}%");
                    break;

                // Nombre del técnico asignado (vía relación)
                case 'assigned_user':
                    $query->whereHas('assignedUser', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%");
                    });
                    break;

                // Cliente del contrato (vía relación anidada)
                case 'client':
                    $query->whereHas('contract.client', function ($q) use ($value) {
                        $q->where('name', 'like', "%{$value}%")
                            ->orWhere('last_name', 'like', "%{$value}%")
                            ->orWhere('identity_number', 'like', "%{$value}%");
                    });
                    break;
            }
        }

        // Rango de fechas de creación
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        // Sin filtros explícitos → solo órdenes activas (no cerradas)
        $showingActiveOnly = !$request->filled('filter_field') && !$request->filled('start_date');

        if ($showingActiveOnly) {
            $query->where('status', '!=', 'Cerrada');
        }

        $technical_orders = $query
            ->with(['contract.client', 'assignedUser', 'createdBy'])
            ->orderByDesc('created_at')
            ->get();

        return view('gestisp.technicals_orders.index', compact(
            'technical_orders', 'users', 'showingActiveOnly'
        ));
    }

    /**
     * Listado de órdenes Prefinalizadas pendientes de verificación
     * por un supervisor.
     */
    public function orderVerification(): View
    {
        $branchId = session('branch_id');

        $users = User::whereHas('branches', function ($query) use ($branchId) {
            $query->where('branch_id', $branchId);
        })->get();

        $technical_orders = TechnicalOrder::where('branch_id', $branchId)
            ->where('status', 'Prefinalizada')
            ->with(['contract.client', 'assignedUser', 'materials.material'])
            ->orderByDesc('created_at')
            ->get();

        return view('gestisp.technicals_orders.verification_orders', compact('technical_orders', 'users'));
    }

    /**
     * Procesa la verificación de una orden Prefinalizada.
     *
     * Dos acciones posibles según el botón pulsado:
     * - close_order: cierra la orden, registra la verificación y
     *   actualiza el estado del contrato según el detalle:
     *     · Instalación/Reconexión → contrato "Activo"
     *     · Corte/Suspensión temporal → contrato "Suspendido"
     * - reject_order: devuelve la orden a "Pendiente" para corrección,
     *   registrando el comentario del supervisor.
     */
    public function verificationOrderProcess(Request $request, TechnicalOrder $technicalOrder): RedirectResponse
    {
        $request->validate([
            'verification_comment' => 'required|string',
        ]);

        $user = auth()->user();

        if ($request->has('close_order')) {
            $technicalOrder->update(['status' => 'Cerrada']);

            // Registrar la verificación (trazabilidad del cierre)
            $technicalOrder->verifications()->create([
                'verified_by' => $user->id,
                'status'      => 'Cerrada',
                'comments'    => $request->input('verification_comment'),
            ]);

            // Actualizar el estado del contrato según el tipo de trabajo
            $contract = Contract::find($technicalOrder->contract_id);

            if ($contract) {
                $activationDetails = [
                    'Instalacion de servicio',
                    'Reconexión',
                    'Instalación de servicio (creación automática)',
                ];

                $suspensionDetails = [
                    'Corte de servicio',
                    'Suspensión temporal',
                ];

                if (in_array($technicalOrder->detail, $activationDetails)) {
                    $contract->update([
                        'status'          => 'Activo',
                        'activation_date' => now(),
                    ]);
                } elseif (in_array($technicalOrder->detail, $suspensionDetails)) {
                    $contract->update(['status' => 'Suspendido']);
                }
            }

            // Avisar al cliente que su servicio quedó resuelto
            $technicalOrder->loadMissing('contract.client', 'branch');
            optional($technicalOrder->contract?->client)
                ->notify(new TechnicalOrderFinishedClient($technicalOrder));

            return redirect()->route('technicals_orders.verification')
                ->with('success', 'La orden ha sido cerrada exitosamente.');
        }

        if ($request->has('reject_order')) {
            // Devolver a Pendiente para que se corrija el trabajo
            $technicalOrder->update(['status' => 'Pendiente']);

            $technicalOrder->verifications()->create([
                'verified_by' => $user->id,
                'status'      => 'Pendiente',
                'comments'    => $request->input('verification_comment'),
            ]);

            return redirect()->route('technicals_orders.verification')
                ->with('warning', 'La orden ha sido rechazada y está pendiente de corrección.');
        }

        return redirect()->route('technicals_orders.verification')
            ->with('error', 'No se seleccionó ninguna acción.');
    }

    /**
     * El técnico rechaza una orden asignada (no puede ejecutarla).
     * Queda en estado "Rechazada" con el motivo, visible para que
     * oficina la reasigne o gestione.
     */
    public function orderReject(TechnicalOrder $technicalOrder, Request $request): RedirectResponse
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $technicalOrder->update([
            'status'           => 'Rechazada',
            'rejection_reason' => $request->input('reason'),
        ]);

        return redirect()->route('technicals_orders.my_technical_orders')
            ->with('success', 'La orden ha sido rechazada.');
    }

    /**
     * Formulario de creación de orden desde un contrato.
     */
    public function create(Contract $contract): View
    {
        return view('gestisp.technicals_orders.create', compact('contract'));
    }

    /**
     * Crea una orden técnica para un contrato.
     *
     * Regla de negocio: un contrato solo puede tener UNA orden en
     * curso (estado distinto de "Cerrada") a la vez.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'contract_id'     => 'required|exists:contracts,id',
            'order_type'      => 'required|string|max:100',
            'order_detail'    => 'required|string|max:255',
            'initial_comment' => 'nullable|string',
        ]);

        try {
            // Bloquear si ya hay una orden en curso para el contrato
            $existingOrder = TechnicalOrder::where('contract_id', $validated['contract_id'])
                ->where('status', '!=', 'Cerrada')
                ->exists();

            if ($existingOrder) {
                return redirect()->route('contracts.show', $validated['contract_id'])
                    ->with('error', 'Ya existe una orden técnica en curso para este contrato.');
            }

            $order = TechnicalOrder::create([
                'contract_id'     => $validated['contract_id'],
                'branch_id'       => session('branch_id'),
                'created_by'      => Auth::id(),
                'type'            => $validated['order_type'],
                'detail'          => $validated['order_detail'],
                'initial_comment' => $validated['initial_comment'] ?? null,
                'status'          => 'Pendiente',
            ]);

            // Avisar al cliente que recibimos su solicitud de servicio
            $order->loadMissing('contract.client', 'branch');
            optional($order->contract?->client)->notify(new TechnicalOrderCreatedClient($order));

            return redirect()->route('contracts.show', $validated['contract_id'])
                ->with('success', 'La orden técnica se ha creado correctamente.');

        } catch (\Exception $e) {
            Log::error('Error al crear orden técnica: ' . $e->getMessage());

            return redirect()->route('contracts.show', $validated['contract_id'])
                ->with('error', 'Hubo un error al crear la orden técnica: ' . $e->getMessage());
        }
    }

    /**
     * "Mis órdenes": listado de órdenes asignadas al técnico
     * autenticado, junto con los materiales disponibles en su
     * almacén personal para reportar al procesar.
     */
    public function myTechnicalOrders(): View
    {
        $materials = $this->getTechnicianMaterials();

        // Abrir esta bandeja cuenta como "ver" las órdenes: se
        // marcan leídas las notificaciones de asignación, con lo
        // que el contador rojo del menú se pone en cero.
        Auth::user()->unreadNotifications()
            ->where('type', TechnicalOrderAssignedTechnician::class)
            ->update(['read_at' => now()]);

        $technical_orders = TechnicalOrder::where('branch_id', session('branch_id'))
            ->where('user_assigned', Auth::id())
            ->where('status', 'Asignada')
            ->with('contract.client')
            ->orderByDesc('created_at')
            ->get();

        return view('gestisp.technicals_orders.my_technical_orders', compact('technical_orders', 'materials'));
    }

    /**
     * API JSON: seriales disponibles de un material en el almacén
     * personal del técnico autenticado.
     */
    public function getSerialNumbers($materialId): JsonResponse
    {
        $warehouse = Warehouse::where('user_id', Auth::id())->first();

        if (!$warehouse) {
            return response()->json([]);
        }

        $serialNumbers = Inventory::where('warehouse_id', $warehouse->id)
            ->where('material_id', $materialId)
            ->whereNotNull('serial_number')
            ->pluck('serial_number');

        return response()->json($serialNumbers);
    }

    /**
     * El técnico procesa (ejecuta) una orden asignada.
     *
     * Corre en transacción: si algo falla (stock insuficiente,
     * inventario inexistente) se revierte todo.
     *
     * Flujo:
     * 1. Guardar el reporte del técnico (observaciones, solución,
     *    evidencia fotográfica) y pasar a "Prefinalizada".
     * 2. Descontar cada material reportado del almacén personal:
     *    - Equipos: cantidad obligatoria 1, se elimina la fila del
     *      serial del inventario.
     *    - Consumibles: se decrementa la cantidad.
     * 3. Si se instaló un equipo con serial, actualizar el cpe_sn
     *    del contrato con ESE serial.
     */
    public function processOrder(Request $request, $id): RedirectResponse
    {
        // La validación va FUERA del try/catch: así los errores de
        // campo se muestran en el formulario (errors bag) en vez de
        // acabar convertidos en un mensaje genérico por el catch.
        $request->validate([
            'observations_technical' => 'required|string',
            'client_observation'     => 'required|string',
            'solution'               => 'required|string',
            'material_id'            => 'nullable|array',
            'quantity'               => 'nullable|array',
            'serial_number'          => 'nullable|array',
            'images'                 => 'nullable|array',
            'client_signature'       => 'required|string',
        ], [
            'client_signature.required' => 'Falta la firma del cliente. Pídale que firme en pantalla antes de cerrar la orden.',
        ]);

        $technicalOrder = TechnicalOrder::findOrFail($id);

        // Materiales realmente reportados (se descartan las filas
        // vacías que pueda mandar el formulario).
        $reportedMaterials = array_filter(
            $request->input('material_id', []),
            fn ($materialId) => !empty($materialId)
        );

        // Regla de negocio: una instalación SIEMPRE consume material
        // (se instalan equipos, cable, conectores...). Sin material
        // reportado la orden no puede cerrarse: obliga al técnico a
        // registrar lo que dejó en sitio y mantiene el inventario al
        // día. Se valida en el servidor para que no dependa del JS.
        if ($this->requiresMaterial($technicalOrder) && count($reportedMaterials) === 0) {
            return redirect()->back()
                ->with('error', 'Esta orden de instalación requiere registrar el material y los equipos instalados. Agregue al menos un material antes de procesarla.')
                ->withInput();
        }

        try {
            DB::beginTransaction();

            // Almacén personal del técnico (de ahí sale el material)
            $warehouse = Warehouse::where('user_id', Auth::id())->first();

            if (!$warehouse) {
                throw new \Exception('No se encontró un almacén asociado al usuario.');
            }

            // ---- Reporte del técnico + evidencia fotográfica ----
            $orderData = [
                'observations_technical' => $request->input('observations_technical'),
                'client_observation'     => $request->input('client_observation'),
                'solution'               => $request->input('solution'),
                'status'                 => 'Prefinalizada',
            ];

            if ($request->hasFile('images')) {
                $imagePaths = [];

                foreach ($request->file('images') as $image) {
                    $path         = $image->store('technical_orders/images', 'public');
                    $imagePaths[] = 'storage/' . $path;
                }

                $orderData['images'] = json_encode($imagePaths);
            }

            // ---- Firma del cliente ----
            // Llega como Data URL PNG (base64) desde el pad táctil. Se
            // decodifica y se guarda como imagen, igual que la evidencia.
            $signaturePath = $this->storeSignature($request->input('client_signature'), $technicalOrder->id);

            if ($signaturePath) {
                $orderData['client_signature'] = $signaturePath;
            }

            $technicalOrder->update($orderData);

            // ---- Materiales usados ----
            // Rastrear el serial del equipo instalado (si lo hay)
            // para actualizar el cpe_sn del contrato al final.
            $installedEquipmentSn = null;

            if ($request->has('material_id')) {
                foreach ($request->input('material_id') as $index => $materialId) {
                    if (empty($materialId)) {
                        continue; // Saltar filas vacías del formulario
                    }

                    $quantity     = $request->input('quantity')[$index];
                    $serialNumber = $request->input('serial_number')[$index] ?? null;

                    // Los equipos con serial se reportan de a una unidad
                    // (validar ANTES de tocar el inventario)
                    if ($serialNumber && $quantity != 1) {
                        throw new \Exception('Los equipos con número de serie solo pueden tener cantidad 1.');
                    }

                    // Buscar el inventario en el almacén del técnico
                    // (por serial exacto si es equipo)
                    $inventory = Inventory::where('warehouse_id', $warehouse->id)
                        ->where('material_id', $materialId)
                        ->when($serialNumber, function ($query) use ($serialNumber) {
                            return $query->where('serial_number', $serialNumber);
                        })
                        ->first();

                    if (!$inventory) {
                        throw new \Exception('No se encontró inventario para el material seleccionado.');
                    }

                    if ($inventory->quantity < $quantity) {
                        throw new \Exception("No hay suficiente stock para el material ID: {$materialId}");
                    }

                    // Registrar el material en la orden (trazabilidad)
                    $technicalOrder->materials()->create([
                        'material_id'   => $materialId,
                        'quantity'      => $quantity,
                        'serial_number' => $serialNumber,
                    ]);

                    // Descontar del inventario del técnico
                    if ($serialNumber) {
                        // Equipo: la fila del serial se elimina completa
                        $inventory->delete();
                        $installedEquipmentSn = $serialNumber;
                    } else {
                        // Consumible: decrementar la cantidad
                        $inventory->update([
                            'quantity' => $inventory->quantity - $quantity,
                        ]);
                    }
                }

                // Vincular el serial del equipo instalado al contrato.
                // CORRECCIÓN: antes se usaba la variable de la última
                // iteración del foreach — si el último material era un
                // consumible, el cpe_sn quedaba en null aunque sí se
                // hubiera instalado un equipo.
                if ($installedEquipmentSn) {
                    Contract::where('id', $technicalOrder->contract_id)
                        ->update(['cpe_sn' => $installedEquipmentSn]);
                }
            }

            DB::commit();

            return redirect()->route('technicals_orders.my_technical_orders')
                ->with('success', 'Orden procesada correctamente.');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error al procesar orden técnica', [
                'order_id' => $id,
                'error'    => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Error al procesar la orden: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Detalle de una orden para su procesamiento por el técnico.
     * Incluye los materiales disponibles en su almacén personal.
     */
    public function show(TechnicalOrder $technicalOrder): View
    {
        $materials = $this->getTechnicianMaterials();
        $warehouse = Warehouse::where('user_id', Auth::id())->first();

        // Si es una instalación, la vista exige registrar material
        // antes de permitir procesar la orden.
        $requiresMaterial = $this->requiresMaterial($technicalOrder);

        return view('gestisp.technicals_orders.show_and_process_order', compact(
            'technicalOrder', 'materials', 'warehouse', 'requiresMaterial'
        ));
    }

    /**
     * ¿La orden obliga a reportar material para poder procesarse?
     *
     * Las instalaciones sí: siempre se instala equipo y cable. Se
     * detecta con OrderDetailMap para unificar las variantes del
     * detalle ("Instalacion de servicio" y "Instalación de servicio
     * (creación automática)" son la misma categoría).
     */
    private function requiresMaterial(TechnicalOrder $technicalOrder): bool
    {
        return OrderDetailMap::clave($technicalOrder->detail) === 'instalacion de servicio';
    }

    /**
     * Guarda la firma del cliente (Data URL PNG) como archivo y
     * devuelve su ruta pública, o null si el dato no es una firma
     * válida.
     */
    private function storeSignature(?string $dataUrl, int $orderId): ?string
    {
        if (!$dataUrl || !preg_match('/^data:image\/(png|jpeg);base64,/', $dataUrl, $m)) {
            return null;
        }

        $base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        $binary = base64_decode($base64, true);

        if ($binary === false) {
            return null;
        }

        $extension = $m[1] === 'jpeg' ? 'jpg' : 'png';
        $path = 'technical_orders/signatures/' . $orderId . '_' . time() . '.' . $extension;

        Storage::disk('public')->put($path, $binary);

        return 'storage/' . $path;
    }

    /**
     * Asigna (o reasigna) un técnico a la orden → estado "Asignada".
     */
    public function update(Request $request, TechnicalOrder $technicalOrder): RedirectResponse
    {
        $request->validate([
            'assigned_user_id' => 'required|exists:users,id',
        ]);

        $technicalOrder->update([
            'user_assigned' => $request->input('assigned_user_id'),
            'status'        => 'Asignada',
        ]);

        // Avisar al técnico: correo, WhatsApp y notificación en el
        // sistema (que alimenta el contador rojo de "Mis Órdenes").
        $tecnico = User::find($request->input('assigned_user_id'));
        $technicalOrder->loadMissing('contract');
        optional($tecnico)->notify(new TechnicalOrderAssignedTechnician($technicalOrder));

        return redirect()->route('technicals_orders.index')
            ->with('success', 'Orden asignada correctamente.');
    }

    /**
     * Exporta las órdenes a Excel (respeta los filtros del request,
     * manejados dentro del export).
     */
    public function export(Request $request)
    {
        return Excel::download(new TechnicalOrdersExport($request), 'ordenes_tecnicas.xlsx');
    }

    /**
     * Materiales con stock disponible en el almacén personal del
     * técnico autenticado, con la cantidad total calculada.
     *
     * Lógica extraída aquí porque myTechnicalOrders y show la
     * duplicaban línea por línea.
     *
     * - Equipos: total = suma de filas (una por serial)
     * - Consumibles: total = cantidad de su fila única
     */
    private function getTechnicianMaterials(): Collection
    {
        $warehouse = Warehouse::where('user_id', Auth::id())->first();

        if (!$warehouse) {
            return new Collection();
        }

        $materials = Material::whereHas('inventories', function ($query) use ($warehouse) {
            $query->where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0);
        })->with(['inventories' => function ($query) use ($warehouse) {
            $query->where('warehouse_id', $warehouse->id);
        }])->get();

        foreach ($materials as $material) {
            $material->total_quantity = $material->is_equipment
                ? $material->inventories->sum('quantity')
                : ($material->inventories->first()->quantity ?? 0);

            // Seriales disponibles (solo equipos). Se incrustan en la
            // vista para que el modal no dependa de una llamada AJAX
            // aparte: así carga al instante y sin problemas de rutas
            // ni de permisos.
            $material->serial_numbers = $material->is_equipment
                ? $material->inventories
                    ->pluck('serial_number')
                    ->filter()
                    ->values()
                    ->all()
                : [];
        }

        return $materials;
    }
}
