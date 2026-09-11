<?php

namespace App\Http\Controllers;

use App\Exports\MaterialsMovementsExport;
use App\Models\Inventory;
use App\Services\InventoryCosting;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Tenancy\CurrentContext;

/**
 * Controlador de Movimientos de Material
 *
 * Gestiona el registro de movimientos de inventario (Entrada, Salida,
 * Transferencia) y su historial. Cada movimiento actualiza el stock
 * en la tabla inventories de forma atómica (transacción DB):
 *
 * - Entrada:        crea/incrementa stock en el almacén destino
 * - Salida:         elimina/decrementa stock en el almacén origen
 * - Transferencia:  mueve stock del origen al destino
 *
 * Los EQUIPOS (material con is_equipment) se mueven por número de
 * serie: cada serial genera su propio registro de movimiento y su
 * propia fila de inventario. Los CONSUMIBLES se mueven por cantidad.
 *
 * Al registrar un movimiento se genera un PDF de resumen que la
 * vista muestra en un modal.
 */
class MaterialMovementController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:movements.index')->only('index');
        $this->middleware('check.permission:movements.create')->only('create', 'store');
        $this->middleware('check.permission:movements.query_sn')->only('getAvailableSerialNumbers');
        $this->middleware('check.permission:movements.material_quantity')->only('getAvailableQuantity');
        $this->middleware('check.permission:movements.history')->only('history');
        $this->middleware('check.permission:movements.pdf')->only('exportMovementsPDF');
        $this->middleware('check.permission:movements.excel')->only('export');
    }

    /**
     * Formulario de registro de movimientos.
     *
     * Envía el catálogo de materiales (ordenado para el select2)
     * y los almacenes de la sucursal activa.
     */
    public function index(): View
    {
        // Catálogo de ESTA sucursal: mostrar el de todas llenaba el
        // buscador de materiales que en esta bodega no existen.
        $materials  = Material::deSucursal()->with('category')->orderBy('name')->get();
        // Con el DUEÑO: un usuario puede tener varios almacenes, y dos
        // «Furgoneta» sin más son indistinguibles en el desplegable.
        $warehouses = Warehouse::whereIn('branch_id', app(CurrentContext::class)->branchIds())
            ->with('user')
            ->orderBy('description')
            ->get();

        return view('gestisp.materials.movements.index', compact('materials', 'warehouses'));
    }

    /**
     * Registra un movimiento de material (uno o varios materiales).
     *
     * Todo el proceso corre en una transacción: si un material falla
     * (stock insuficiente, seriales incompletos), se revierte todo.
     *
     * Flujo por material:
     * 1. Salidas/Transferencias: validar stock disponible en origen
     *    - Equipos: contar unidades y exigir tantos seriales como
     *      cantidad solicitada
     *    - Consumibles: comparar contra la cantidad en inventario
     * 2. Crear el/los registros de movimiento
     *    - Equipos: un movimiento por serial (quantity = 1)
     *    - Consumibles: un movimiento con la cantidad total
     * 3. Actualizar el inventario según el tipo (updateInventory)
     *
     * Al final genera el PDF de resumen y lo pasa a la vista por
     * sesión para mostrarlo en el modal.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'type'                            => 'required|in:Entrada,Salida,Transferencia',
                'materials'                       => 'required|array|min:1',
                // De ESTA sucursal: con un simple exists bastaba con
                // enviar el id de otra sede para mover material ajeno.
                'materials.*.material_id'         => [
                    'required',
                    Rule::exists('materials', 'id')->whereIn('branch_id', app(CurrentContext::class)->branchIds()),
                ],
                'materials.*.quantity'            => 'required|numeric|min:1',
                // LA UNIDAD YA NO SE PIDE: es del material. Si llega en
                // la petición se ignora — aceptarla permitiría ingresar
                // 200 «Unidades» de fibra y sacar 50 «Metros» de la
                // misma fibra, y las existencias quedarían en una unidad
                // que no significa nada.
                'materials.*.serial_numbers'      => 'nullable|array',
                'materials.*.serial_numbers.*'    => 'string',
                // OPCIONAL, y solo se lee en las ENTRADAS: es lo que se
                // paga al ingresar material. En un traslado el costo ya
                // viaja con la existencia desde el almacén de origen, y
                // en una salida no hay nada que costear.
                'materials.*.purchase_unit_value' => 'nullable|numeric|min:0|max:99999999999.99',
                'warehouse_origin_id'             => [
                    'nullable', 'required_if:type,Salida,Transferencia',
                    Rule::exists('warehouses', 'id')->whereIn('branch_id', app(CurrentContext::class)->branchIds()),
                ],
                'warehouse_destination_id'        => [
                    'nullable', 'required_if:type,Entrada,Transferencia',
                    Rule::exists('warehouses', 'id')->whereIn('branch_id', app(CurrentContext::class)->branchIds()),
                ],
                'reason'                          => 'required|string|max:100',
            ], [
                'materials.*.material_id.exists' => 'Uno de los materiales no pertenece a esta sucursal.',
                'warehouse_origin_id.exists' => 'El almacén de origen no pertenece a esta sucursal.',
                'warehouse_destination_id.exists' => 'El almacén de destino no pertenece a esta sucursal.',
            ]);

            $movements = [];

            DB::transaction(function () use ($request, &$movements) {
                foreach ($request->materials as $materialData) {
                    $material    = Material::findOrFail($materialData['material_id']);
                    $quantity    = $materialData['quantity'];
                    $isEquipment = $material->is_equipment;

                    // Del MATERIAL, no del formulario. Se sigue copiando
                    // a la fila del movimiento y a la del inventario a
                    // propósito: son la foto de con qué unidad se
                    // registró aquello, y deben seguir diciendo lo mismo
                    // aunque mañana se corrija la del catálogo.
                    $unidad = $material->unit_of_measurement;

                    // ---- Validar stock en origen (salidas y transferencias) ----
                    if (in_array($request->type, ['Salida', 'Transferencia'])) {
                        if ($isEquipment) {
                            // Equipos: una fila de inventario por serial → contar
                            $availableQuantity = Inventory::where('warehouse_id', $request->warehouse_origin_id)
                                ->where('material_id', $material->id)
                                ->count();

                            if ($availableQuantity < $quantity) {
                                throw new \Exception(
                                    "Cantidad insuficiente de equipos en el almacén de origen. " .
                                    "Disponibles: {$availableQuantity}, Solicitados: {$quantity}"
                                );
                            }

                            // Los seriales seleccionados deben coincidir con la cantidad
                            if (!isset($materialData['serial_numbers']) || count($materialData['serial_numbers']) != $quantity) {
                                throw new \Exception(
                                    'La cantidad de números de serie seleccionados debe ser igual a la cantidad solicitada.'
                                );
                            }
                        } else {
                            // Consumibles: comparar contra la cantidad acumulada
                            $inventory = Inventory::where('warehouse_id', $request->warehouse_origin_id)
                                ->where('material_id', $material->id)
                                ->first();

                            if (!$inventory || $inventory->quantity < $quantity) {
                                $available = $inventory->quantity ?? 0;
                                throw new \Exception(
                                    "Cantidad insuficiente en el almacén de origen. " .
                                    "Disponible: {$available}, Solicitado: {$quantity}"
                                );
                            }
                        }
                    }

                    // ---- Crear movimientos y actualizar inventario ----
                    // Lo que se pagó por cada unidad de ESTA entrada.
                    // No cae al valor del catálogo si viene vacío: el
                    // formulario ya lo propone, así que un campo en
                    // blanco es un «no se sabe» deliberado, y
                    // rellenarlo por detrás inventaría un precio.
                    $valorUnitario = $this->valorDeCompraDeLaEntrada($request->type, $materialData);

                    if ($isEquipment && isset($materialData['serial_numbers'])) {
                        // Equipos: un movimiento por cada serial
                        foreach ($materialData['serial_numbers'] as $serialNumber) {
                            $movements[] = MaterialMovement::create([
                                'type'                     => $request->type,
                                'material_id'              => $material->id,
                                'quantity'                 => 1,
                                'unit_of_measurement'      => $unidad,
                                'warehouse_origin_id'      => $request->warehouse_origin_id,
                                'warehouse_destination_id' => $request->warehouse_destination_id,
                                'serial_number'            => $serialNumber,
                                'user_id'                  => auth()->id(),
                                'reason'                   => $request->reason,
                                'purchase_unit_value'      => $valorUnitario,
                            ]);

                            $this->updateInventory(
                                $request->type,
                                $request->warehouse_origin_id,
                                $request->warehouse_destination_id,
                                $material->id,
                                1,
                                $unidad,
                                $serialNumber,
                                $valorUnitario
                            );
                        }
                    } else {
                        // Consumibles: un movimiento con la cantidad total
                        $movements[] = MaterialMovement::create([
                            'type'                     => $request->type,
                            'material_id'              => $material->id,
                            'quantity'                 => $quantity,
                            'unit_of_measurement'      => $unidad,
                            'warehouse_origin_id'      => $request->warehouse_origin_id,
                            'warehouse_destination_id' => $request->warehouse_destination_id,
                            'user_id'                  => auth()->id(),
                            'reason'                   => $request->reason,
                            'purchase_unit_value'      => $valorUnitario,
                        ]);

                        $this->updateInventory(
                            $request->type,
                            $request->warehouse_origin_id,
                            $request->warehouse_destination_id,
                            $material->id,
                            $quantity,
                            $unidad,
                            null,
                            $valorUnitario
                        );
                    }
                }
            });

            $this->auditarMovimiento($request, $movements);

            // ---- PDF de resumen del movimiento ----
            // El comprobante lleva los costos solo si quien registro el
            // movimiento puede verlos: es un PDF y se guarda.
            $verCostos = \Illuminate\Support\Facades\Gate::allows('materials.costs');
            $pdf     = \App\Support\PdfBranding::make('gestisp.materials.movements.pdf_summary', compact('movements', 'verCostos'));
            $pdfPath = storage_path('app/public/movimiento_' . time() . '.pdf');
            $pdf->save($pdfPath);

            return redirect()->route('movements.index')->with([
                'success-create' => 'Movimiento registrado exitosamente.',
                'pdfPath'        => $pdfPath,
            ]);

        } catch (ValidationException $e) {
            // Debe salir con el detalle POR CAMPO: si se tragara aquí,
            // el formulario mostraría un "error" genérico y el usuario
            // no sabría qué línea corregir.
            throw $e;

        } catch (\Exception $e) {
            Log::error('Error al procesar movimiento de material', [
                'error'   => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()
                ->withErrors(['error' => $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Actualiza el inventario según el tipo de movimiento.
     *
     * Entrada:
     *   - Equipo: crea una fila nueva con el serial
     *   - Consumible: incrementa (o crea) la fila acumulada del material
     * Salida:
     *   - Equipo: elimina la fila del serial
     *   - Consumible: decrementa la cantidad
     * Transferencia:
     *   - Equipo: cambia el warehouse_id de la fila del serial
     *   - Consumible: decrementa en origen e incrementa en destino
     *
     * Se ejecuta dentro de la transacción del store, por lo que
     * cualquier excepción revierte también los movimientos creados.
     */
    protected function updateInventory(
        string $type,
        ?int $warehouseOriginId,
        ?int $warehouseDestinationId,
        int $materialId,
        int $quantity,
        string $unitOfMeasurement,
        ?string $serialNumber = null,
        ?float $purchaseUnitValue = null
    ): void {
        if ($type === 'Entrada') {
            if ($serialNumber) {
                // EQUIPO: la fila es una unidad, así que lleva su costo
                // exacto. Dos ONT compradas a precios distintos valen
                // cada una lo suyo.
                Inventory::create([
                    'warehouse_id'        => $warehouseDestinationId,
                    'material_id'         => $materialId,
                    'quantity'            => 1,
                    'unit_of_measurement' => $unitOfMeasurement,
                    'serial_number'       => $serialNumber,
                    'purchase_unit_value' => $purchaseUnitValue,
                ]);
            } else {
                // CONSUMIBLE: todas las compras se acumulan en una sola
                // fila, así que el costo se lleva por promedio
                // ponderado. Ver App\Services\InventoryCosting.
                $inventory = Inventory::updateOrCreate(
                    [
                        'warehouse_id'  => $warehouseDestinationId,
                        'material_id'   => $materialId,
                        'serial_number' => null,
                    ],
                    [
                        'quantity'            => DB::raw("COALESCE(quantity, 0) + $quantity"),
                        'unit_of_measurement' => $unitOfMeasurement,
                    ]
                );

                // `refresh()` obligatorio: con DB::raw la cantidad que
                // queda en memoria es la EXPRESIÓN, no el número, y el
                // promedio saldría de una cantidad inventada.
                app(InventoryCosting::class)->registrarEntrada(
                    $inventory->refresh(),
                    $purchaseUnitValue,
                    (float) $quantity,
                );
            }
        } elseif ($type === 'Salida') {
            if ($serialNumber) {
                Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->where('serial_number', $serialNumber)
                    ->first()?->delete();
            } else {
                $inventory = Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->first();

                $inventory?->update([
                    'quantity' => $inventory->quantity - $quantity,
                ]);
            }
        } elseif ($type === 'Transferencia') {
            if ($serialNumber) {
                // El equipo conserva su fila, solo cambia de almacén
                Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->where('serial_number', $serialNumber)
                    ->first()?->update(['warehouse_id' => $warehouseDestinationId]);
            } else {
                // Consumible: restar en origen, sumar en destino
                $originInventory = Inventory::where('warehouse_id', $warehouseOriginId)
                    ->where('material_id', $materialId)
                    ->first();

                // EL COSTO VIAJA CON EL MATERIAL. Se lee ANTES de
                // descontar: trasladar 200 m de cable no los abarata, y
                // si el destino los valorara a cero, mover material de
                // un almacén a otro haría desaparecer dinero del
                // inventario total sin que nadie comprara ni gastara
                // nada.
                $costoDeOrigen = $originInventory?->purchase_unit_value !== null
                    ? (float) $originInventory->purchase_unit_value
                    : null;

                $originInventory?->update([
                    'quantity' => $originInventory->quantity - $quantity,
                ]);

                $destino = Inventory::updateOrCreate(
                    [
                        'warehouse_id'  => $warehouseDestinationId,
                        'material_id'   => $materialId,
                        'serial_number' => null,
                    ],
                    [
                        'quantity'            => DB::raw("COALESCE(quantity, 0) + $quantity"),
                        'unit_of_measurement' => $unitOfMeasurement,
                    ]
                );

                app(InventoryCosting::class)->registrarEntrada(
                    $destino->refresh(),
                    $costoDeOrigen,
                    (float) $quantity,
                );
            }
        }
    }

    /**
     * El valor unitario de compra que se registra en una entrada.
     *
     * SOLO EN LAS ENTRADAS. En un traslado el costo ya viene con la
     * existencia del almacén de origen —dejar que se reescriba desde el
     * formulario permitiría revaluar inventario moviéndolo de sitio— y
     * en una salida no hay nada que costear.
     *
     * Vacío y ausente son lo mismo: NULL. El formulario propone el
     * valor del catálogo, así que un campo en blanco es un «no se sabe»
     * deliberado; rellenarlo por detrás inventaría un precio, y un cero
     * se sumaría en los totales como si el material fuera gratis.
     */
    private function valorDeCompraDeLaEntrada(string $type, array $materialData): ?float
    {
        if ($type !== 'Entrada') {
            return null;
        }

        $valor = $materialData['purchase_unit_value'] ?? null;

        return ($valor === null || $valor === '') ? null : (float) $valor;
    }

    /**
     * API JSON: seriales disponibles de un material en un almacén.
     * Alimenta el select de seriales del modal de registro.
     */
    public function getAvailableSerialNumbers($warehouseId, $materialId): JsonResponse
    {
        $this->exigirAlmacenDeLaSucursal((int) $warehouseId);

        $serialNumbers = Inventory::where('warehouse_id', $warehouseId)
            ->where('material_id', $materialId)
            ->whereNotNull('serial_number')
            ->pluck('serial_number');

        return response()->json($serialNumbers);
    }

    /**
     * API JSON: cantidad disponible de un material en un almacén.
     * Equipos: número de filas (una por serial).
     * Consumibles: suma de la columna quantity.
     */
    public function getAvailableQuantity($warehouseId, $materialId): JsonResponse
    {
        $this->exigirAlmacenDeLaSucursal((int) $warehouseId);

        $material = Material::findOrFail($materialId);

        $quantity = $material->is_equipment
            ? Inventory::where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->count()
            : Inventory::where('warehouse_id', $warehouseId)
                ->where('material_id', $materialId)
                ->sum('quantity');

        return response()->json(['quantity' => $quantity]);
    }

    /**
     * Impide consultar el stock de un almacén de otra sucursal.
     *
     * Son endpoints JSON con el id en la URL: sin esto, cambiar el
     * número bastaba para leer las existencias y los seriales de otra
     * sede desde la consola del navegador.
     */
    private function exigirAlmacenDeLaSucursal(int $warehouseId): void
    {
        abort_unless(
            Warehouse::where('id', $warehouseId)
                ->whereIn('branch_id', app(CurrentContext::class)->branchIds())
                ->exists(),
            403,
            'Ese almacén pertenece a otra sucursal.',
        );
    }

    /**
     * Deja el movimiento completo en la trazabilidad.
     *
     * Los cambios de cada fila (inventario, movimientos) ya los
     * registra el oyente global de modelos, pero esas filas sueltas no
     * responden la pregunta que se hace un jefe de almacén: QUIÉN sacó
     * QUÉ, de DÓNDE y POR QUÉ. Esta única entrada sí.
     *
     * @param  array<int, MaterialMovement>  $movements
     */
    private function auditarMovimiento(Request $request, array $movements): void
    {
        $resumen = collect($movements)
            ->groupBy('material_id')
            ->map(fn ($filas) => [
                'material' => $filas->first()->material?->name,
                'cantidad' => $filas->sum('quantity'),
                'unidad' => $filas->first()->unit_of_measurement,
                'seriales' => $filas->pluck('serial_number')->filter()->values()->all(),
            ])
            ->values()
            ->all();

        $origen = $request->warehouse_origin_id
            ? Warehouse::find($request->warehouse_origin_id)?->description
            : null;

        $destino = $request->warehouse_destination_id
            ? Warehouse::find($request->warehouse_destination_id)?->description
            : null;

        app(\App\Services\Audit\AuditLogger::class)->action(
            'movements.registered',
            sprintf(
                'Registró una %s de %d material(es)%s%s',
                mb_strtolower($request->type),
                count($resumen),
                $origen ? ' desde ' . $origen : '',
                $destino ? ' hacia ' . $destino : '',
            ),
            [
                'tipo' => $request->type,
                'motivo' => $request->reason,
                'almacen_origen' => $origen,
                'almacen_destino' => $destino,
                'materiales' => $resumen,
            ],
            null,
            'inventario',
        );
    }

    /**
     * Historial de movimientos con filtros.
     *
     * La tabla usa DataTables del lado del cliente. Como los
     * movimientos crecen sin límite, si el usuario no especifica
     * un rango de fechas se muestra solo el mes actual.
     */
    public function history(Request $request): View
    {
        $query = $this->applyFilters($request);

        // Sin rango de fechas explícito → limitar al mes actual
        $usingDefaultRange = !($request->filled('start_date') || $request->filled('end_date'));

        if ($usingDefaultRange) {
            $query->whereBetween('created_at', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ]);
        }

        $movements = $query
            ->with([
                // Los almacenes traen su sucursal: el listado la
                // muestra en panel consolidado.
                'warehouseOrigin.branch', 'warehouseDestination.branch',
                'material', 'user',
            ])
            ->orderByDesc('created_at')
            ->get();

        return view('gestisp.materials.movements.history', compact('movements', 'usingDefaultRange'));
    }

    /**
     * Exporta el historial filtrado a PDF.
     * Usa exactamente los mismos filtros que history() vía
     * applyFilters(), sin el límite del mes actual.
     */
    public function exportMovementsPDF(Request $request)
    {
        $movements = $this->applyFilters($request)
            ->with([
                // Los almacenes traen su sucursal: el listado la
                // muestra en panel consolidado.
                'warehouseOrigin.branch', 'warehouseDestination.branch',
                'material', 'user',
            ])
            ->orderByDesc('created_at')
            ->get();

        // El PDF informa el período consultado en su encabezado
        $from = $request->start_date;
        $to = $request->end_date;

        // EL PDF SE GUARDA, SE REENVÍA Y SE IMPRIME. Por eso el permiso
        // se resuelve aquí y no dentro de la plantilla: si solo se
        // comprobara en la pantalla, bastaría con descargar el historial
        // para saltárselo.
        $verCostos = \Illuminate\Support\Facades\Gate::allows('materials.costs');

        // Horizontal: el detalle tiene 9 columnas (10 con el costo)
        $pdf = \App\Support\PdfBranding::make(
            'gestisp.materials.movements.pdf',
            compact('movements', 'from', 'to', 'verCostos'),
            landscape: true
        );

        return $pdf->download('historial_movimientos.pdf');
    }

    /**
     * Exporta todos los movimientos a Excel.
     */
    public function export()
    {
        return (new MaterialsMovementsExport)->download('listado_de_movimientos_de_almacen.xlsx');
    }

    /**
     * Construye la consulta del historial con los filtros del request.
     *
     * Única fuente de verdad de los filtros: history y
     * exportMovementsPDF la comparten, garantizando que el PDF
     * exporta lo mismo que se ve en pantalla.
     *
     * CORRECCIONES respecto a la versión anterior:
     * 1. El alcance por sucursal (origen O destino en la sucursal)
     *    va agrupado en un where(function(...)) — antes el
     *    orWhereHas sin agrupar anulaba el resto de condiciones.
     * 2. filter_field pasa por una lista blanca: antes se usaba
     *    directo como columna, y las opciones warehouse_origin /
     *    warehouse_destination de la vista no existen como columnas
     *    (causaban error SQL); ahora buscan por la DESCRIPCIÓN del
     *    almacén a través de la relación.
     */
    private function applyFilters(Request $request): Builder
    {
        $branchIds = app(CurrentContext::class)->branchIds();

        $query = MaterialMovement::query();

        // Alcance por sucursal: el movimiento pertenece a la sucursal
        // si su almacén de origen O el de destino son de ella.
        // El agrupamiento con where(closure) es imprescindible para
        // que el OR no rompa los demás filtros.
        $query->where(function ($q) use ($branchIds) {
            $q->whereHas('warehouseOrigin', function ($w) use ($branchIds) {
                $w->whereIn('branch_id', $branchIds);
            })->orWhereHas('warehouseDestination', function ($w) use ($branchIds) {
                $w->whereIn('branch_id', $branchIds);
            });
        });

        // Búsqueda por campo (lista blanca)
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            switch ($field) {
                // Columnas directas de la tabla
                case 'type':
                case 'serial_number':
                case 'reason':
                    $query->where($field, 'like', "%{$value}%");
                    break;

                // Búsqueda por descripción del almacén (vía relación)
                case 'warehouse_origin':
                    $query->whereHas('warehouseOrigin', function ($w) use ($value) {
                        $w->where('description', 'like', "%{$value}%");
                    });
                    break;

                case 'warehouse_destination':
                    $query->whereHas('warehouseDestination', function ($w) use ($value) {
                        $w->where('description', 'like', "%{$value}%");
                    });
                    break;

                // Búsqueda por nombre del material (vía relación)
                case 'material':
                    $query->whereHas('material', function ($m) use ($value) {
                        $m->where('name', 'like', "%{$value}%");
                    });
                    break;
            }
        }

        // Rango de fechas
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        return $query;
    }
}
