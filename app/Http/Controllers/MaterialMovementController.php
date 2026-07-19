<?php

namespace App\Http\Controllers;

use App\Exports\MaterialsMovementsExport;
use App\Models\Inventory;
use App\Models\Material;
use App\Models\MaterialMovement;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

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
        $materials  = Material::orderBy('name')->get();
        $warehouses = Warehouse::where('branch_id', session('branch_id'))->get();

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
                'materials.*.material_id'         => 'required|exists:materials,id',
                'materials.*.quantity'            => 'required|numeric|min:1',
                'materials.*.unit_of_measurement' => 'required|string',
                'materials.*.serial_numbers'      => 'nullable|array',
                'materials.*.serial_numbers.*'    => 'string',
                'warehouse_origin_id'             => 'nullable|exists:warehouses,id|required_if:type,Salida,Transferencia',
                'warehouse_destination_id'        => 'nullable|exists:warehouses,id|required_if:type,Entrada,Transferencia',
                'reason'                          => 'required|string|max:100',
            ]);

            $movements = [];

            DB::transaction(function () use ($request, &$movements) {
                foreach ($request->materials as $materialData) {
                    $material    = Material::findOrFail($materialData['material_id']);
                    $quantity    = $materialData['quantity'];
                    $isEquipment = $material->is_equipment;

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
                    if ($isEquipment && isset($materialData['serial_numbers'])) {
                        // Equipos: un movimiento por cada serial
                        foreach ($materialData['serial_numbers'] as $serialNumber) {
                            $movements[] = MaterialMovement::create([
                                'type'                     => $request->type,
                                'material_id'              => $material->id,
                                'quantity'                 => 1,
                                'unit_of_measurement'      => $materialData['unit_of_measurement'],
                                'warehouse_origin_id'      => $request->warehouse_origin_id,
                                'warehouse_destination_id' => $request->warehouse_destination_id,
                                'serial_number'            => $serialNumber,
                                'user_id'                  => auth()->id(),
                                'reason'                   => $request->reason,
                            ]);

                            $this->updateInventory(
                                $request->type,
                                $request->warehouse_origin_id,
                                $request->warehouse_destination_id,
                                $material->id,
                                1,
                                $materialData['unit_of_measurement'],
                                $serialNumber
                            );
                        }
                    } else {
                        // Consumibles: un movimiento con la cantidad total
                        $movements[] = MaterialMovement::create([
                            'type'                     => $request->type,
                            'material_id'              => $material->id,
                            'quantity'                 => $quantity,
                            'unit_of_measurement'      => $materialData['unit_of_measurement'],
                            'warehouse_origin_id'      => $request->warehouse_origin_id,
                            'warehouse_destination_id' => $request->warehouse_destination_id,
                            'user_id'                  => auth()->id(),
                            'reason'                   => $request->reason,
                        ]);

                        $this->updateInventory(
                            $request->type,
                            $request->warehouse_origin_id,
                            $request->warehouse_destination_id,
                            $material->id,
                            $quantity,
                            $materialData['unit_of_measurement']
                        );
                    }
                }
            });

            // ---- PDF de resumen del movimiento ----
            $pdf     = \App\Support\PdfBranding::make('gestisp.materials.movements.pdf_summary', compact('movements'));
            $pdfPath = storage_path('app/public/movimiento_' . time() . '.pdf');
            $pdf->save($pdfPath);

            return redirect()->route('movements.index')->with([
                'success-create' => 'Movimiento registrado exitosamente.',
                'pdfPath'        => $pdfPath,
            ]);

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
        ?string $serialNumber = null
    ): void {
        if ($type === 'Entrada') {
            if ($serialNumber) {
                Inventory::create([
                    'warehouse_id'        => $warehouseDestinationId,
                    'material_id'         => $materialId,
                    'quantity'            => 1,
                    'unit_of_measurement' => $unitOfMeasurement,
                    'serial_number'       => $serialNumber,
                ]);
            } else {
                Inventory::updateOrCreate(
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

                $originInventory?->update([
                    'quantity' => $originInventory->quantity - $quantity,
                ]);

                Inventory::updateOrCreate(
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
            }
        }
    }

    /**
     * API JSON: seriales disponibles de un material en un almacén.
     * Alimenta el select de seriales del modal de registro.
     */
    public function getAvailableSerialNumbers($warehouseId, $materialId): JsonResponse
    {
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
            ->with(['warehouseOrigin', 'warehouseDestination', 'material', 'user'])
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
            ->with(['warehouseOrigin', 'warehouseDestination', 'material', 'user'])
            ->orderByDesc('created_at')
            ->get();

        // El PDF informa el período consultado en su encabezado
        $from = $request->start_date;
        $to = $request->end_date;

        // Horizontal: el detalle tiene 9 columnas
        $pdf = \App\Support\PdfBranding::make(
            'gestisp.materials.movements.pdf',
            compact('movements', 'from', 'to'),
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
        $branchId = session('branch_id');

        $query = MaterialMovement::query();

        // Alcance por sucursal: el movimiento pertenece a la sucursal
        // si su almacén de origen O el de destino son de ella.
        // El agrupamiento con where(closure) es imprescindible para
        // que el OR no rompa los demás filtros.
        $query->where(function ($q) use ($branchId) {
            $q->whereHas('warehouseOrigin', function ($w) use ($branchId) {
                $w->where('branch_id', $branchId);
            })->orWhereHas('warehouseDestination', function ($w) use ($branchId) {
                $w->where('branch_id', $branchId);
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
