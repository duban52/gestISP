<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Controlador de Almacenes (Warehouses)
 *
 * Gestiona el CRUD de los almacenes de la sucursal. Un almacén es el
 * lugar físico donde se guarda el material del ISP (ONTs, routers,
 * cable, conectores, etc.). El stock de cada material por almacén
 * vive en la tabla inventories (relación hasMany), y los materiales
 * se alcanzan a través de hasManyThrough.
 */
class WarehouseController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:warehouses.index')->only('index');
        $this->middleware('check.permission:warehouses.show')->only('show');
        $this->middleware('check.permission:warehouses.create')->only('create', 'store');
        $this->middleware('check.permission:warehouses.edit')->only('edit', 'update');
        $this->middleware('check.permission:warehouses.destroy')->only('destroy');
    }

    /**
     * Lista los almacenes de la sucursal activa.
     *
     * Se precargan el usuario creador y se cuenta el inventario con
     * withCount() para evitar el problema N+1: la vista muestra
     * cuántos materiales distintos tiene cada almacén sin disparar
     * una consulta extra por fila.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente.
     */
    public function index(): View
    {
        $warehouses = Warehouse::where('branch_id', session('branch_id'))
            ->with('user')
            ->withCount('inventories')
            ->get();

        return view('gestisp.warehouses.index', compact('warehouses'));
    }
    /**
     * Muestra el detalle de un almacén con su inventario completo.
     *
     * Los registros de inventario se agrupan por material: los equipos
     * (is_equipment) tienen una fila de inventario por cada número de
     * serie, por lo que al agrupar se suman las cantidades y se
     * recolectan los SNs para mostrarlos en el modal de la vista.
     *
     * Estructura resultante de $inventoriesData (una entrada por material):
     * [
     *   'material'            => Material,   // modelo del material
     *   'quantity'            => int,        // suma de cantidades
     *   'unit_of_measurement' => string,     // unidad de medida
     *   'sns'                 => array,      // seriales (solo equipos)
     * ]
     */
    public function show(Warehouse $warehouse): View
    {
        // Traer todo el inventario del almacén con su material (evita N+1)
        $inventories = $warehouse->inventories()->with('material')->get();

        // Agrupar por material y consolidar cantidades y seriales
        $inventoriesData = $inventories
            ->groupBy('material_id')
            ->map(function ($items) {
                return [
                    'material'            => $items->first()->material,
                    'quantity'            => $items->sum('quantity'),
                    'unit_of_measurement' => $items->first()->unit_of_measurement,
                    'sns'                 => $items->pluck('serial_number')
                        ->filter()   // descarta nulls (material sin serial)
                        ->values()
                        ->toArray(),
                ];
            })
            ->values(); // reindexar la colección

        return view('gestisp.warehouses.show', compact('warehouse', 'inventoriesData'));
    }

    /**
     * Muestra el formulario de creación de almacén.
     */
    public function create(): View
    {
        // Usuarios de la sucursal activa para el desplegable
        // "vincular usuario a almacén" (el dueño del almacén).
        $users = User::whereHas('branches', function ($q) {
            $q->where('branches.id', session('branch_id'));
        })->orderBy('name')->get();

        return view('gestisp.warehouses.create', compact('users'));
    }

    /**
     * Guarda un nuevo almacén.
     *
     * El almacén queda asociado a la sucursal activa y al usuario
     * que se elija en el formulario (su dueño: cada técnico tiene su
     * propio almacén). Si no se elige ninguno, queda a nombre de
     * quien lo crea.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateWarehouse($request);

        Warehouse::create([
            'description' => $validated['description'],
            'branch_id'   => session('branch_id'),
            'user_id'     => $validated['user_id'] ?? Auth::id(),
        ]);

        return redirect()
            ->route('warehouses.index')
            ->with('success-create', 'Almacén creado correctamente.');
    }

    /**
     * Muestra el formulario de edición de un almacén.
     */
    public function edit(Warehouse $warehouse): View
    {
        $users = User::whereHas('branches', function ($q) {
            $q->where('branches.id', session('branch_id'));
        })->orderBy('name')->get();

        return view('gestisp.warehouses.edit', compact('warehouse', 'users'));
    }

    /**
     * Actualiza un almacén existente.
     */
    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $validated = $this->validateWarehouse($request);

        $warehouse->update([
            'description' => $validated['description'],
            // Solo se cambia el dueño si se eligió uno; en blanco se
            // conserva el actual.
            'user_id' => $validated['user_id'] ?? $warehouse->user_id,
        ]);

        return redirect()
            ->route('warehouses.index')
            ->with('success-update', 'Almacén actualizado correctamente.');
    }

    /**
     * Elimina un almacén.
     *
     * Se bloquea si tiene inventario asociado: eliminar un almacén
     * con existencias dejaría el stock huérfano o fallaría por la
     * llave foránea de la base de datos. Primero debe trasladarse
     * o darse de baja el material.
     */
    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        // Bloquear la eliminación si hay inventario registrado
        if ($warehouse->inventories()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el almacén tiene inventario asociado. Traslade o dé de baja el material primero.'
            );
        }

        $warehouse->delete();

        return redirect()
            ->route('warehouses.index')
            ->with('success-delete', 'Almacén eliminado correctamente.');
    }

    //Exportar inventario en PDF

    public function generatePdf(Warehouse $warehouse)

    {

        $inventories = Inventory::where('warehouse_id', $warehouse->id)

            ->with('material')

            ->get()

            ->groupBy('material_id')

            ->map(function ($items) {

                $material = $items->first()->material;

                $quantity = $items->sum('quantity');

                $unit = $items->first()->unit_of_measurement;

                $sns = $items->pluck('serial_number')->filter()->toArray(); // Lista de SN

                return [

                    'material' => $material->name, // Nombre del material

                    'quantity' => $quantity, // Cantidad total

                    'unit_of_measurement' => $unit, // Unidad de medida

                    'sns' => implode(', ', $sns) // Convertir SNs en una lista separada por comas

                ];

            });

        $data = [

            'inventoriesData' => $inventories,

            'warehouse' => $warehouse

        ];

        $pdf = \App\Support\PdfBranding::make('gestisp.warehouses.pdf', $data);

        return $pdf->download('Inventario_'.$warehouse->description.'.pdf');

    }

    /**
     * Reglas de validación compartidas entre store y update.
     */
    private function validateWarehouse(Request $request): array
    {
        return $request->validate([
            'description' => 'required|string|max:255',
            // Opcional: el dueño del almacén. Se valida que exista.
            'user_id' => 'nullable|exists:users,id',
        ]);
    }
}
