<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Models\Inventory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\InventoryCosting;
use App\Tenancy\CurrentContext;

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
        $warehouses = Warehouse::whereIn('branch_id', app(CurrentContext::class)->branchIds())
            ->with(['user', 'creator', 'branch'])
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
     *   'valor'               => array,      // total/unitario/sin valorar
     * ]
     *
     * EL INVENTARIO VALORADO
     * ----------------------
     * Además de CUÁNTO hay, se calcula cuánto vale. La cifra se arma
     * aquí y no en la vista porque tiene una trampa: lo que no tiene
     * precio no puede contarse como cero. Un almacén con 500 m de cable
     * sin costo registrado no vale cero pesos, y un total que se lo
     * traga como gratis es peor que no tener total, porque nadie duda
     * de un número. Por eso `valor['total']` puede venir null y el
     * resumen dice cuántos materiales quedaron sin valorar.
     *
     * Quién puede VER esas cifras lo decide la vista con el permiso
     * `materials.costs`; aquí se calculan siempre, que es barato y
     * evita dos caminos distintos según quién mire.
     */
    public function show(Warehouse $warehouse): View
    {
        // Traer todo el inventario del almacén con su material (evita N+1)
        $inventories = $warehouse->inventories()->with('material')->get();

        $costos = app(InventoryCosting::class);

        // Agrupar por material y consolidar cantidades y seriales
        $inventoriesData = $inventories
            ->groupBy('material_id')
            ->map(function ($items) use ($costos) {
                return [
                    'material'            => $items->first()->material,
                    'quantity'            => $items->sum('quantity'),
                    'unit_of_measurement' => $items->first()->unit_of_measurement,
                    'sns'                 => $items->pluck('serial_number')
                        ->filter()   // descarta nulls (material sin serial)
                        ->values()
                        ->toArray(),
                    // En los equipos cada serial lleva su propio costo,
                    // así que se valora fila a fila y no por la suma.
                    'valor'               => $costos->valorarMaterial($items),
                ];
            })
            ->values(); // reindexar la colección

        $resumenValor = $this->resumirValor($inventoriesData);

        return view('gestisp.warehouses.show', compact('warehouse', 'inventoriesData', 'resumenValor'));
    }

    /**
     * Lo que vale el almacén entero, y qué parte no se pudo valorar.
     *
     * `materiales_sin_valorar` no es un detalle: es lo que permite leer
     * el total como «al menos esto» en vez de como «esto exactamente».
     *
     * @return array{total: float, materiales_sin_valorar: int, completo: bool}
     */
    private function resumirValor($inventoriesData): array
    {
        $total = 0.0;
        $sinValorar = 0;

        foreach ($inventoriesData as $fila) {
            if ($fila['valor']['total'] === null) {
                $sinValorar++;
                continue;
            }

            $total += $fila['valor']['total'];

            // Un material puede estar valorado A MEDIAS: cinco ONT con
            // precio y dos sin él. Cuenta como incompleto igual.
            if ($fila['valor']['unidades_sin_valorar'] > 0) {
                $sinValorar++;
            }
        }

        return [
            'total' => round($total, 2),
            'materiales_sin_valorar' => $sinValorar,
            'completo' => $sinValorar === 0,
        ];
    }

    /**
     * Muestra el formulario de creación de almacén.
     */
    public function create(): View
    {
        // Usuarios de la sucursal activa para el desplegable
        // "vincular usuario a almacén" (el dueño del almacén).
        $users = User::whereHas('branches', function ($q) {
            $q->whereIn('branches.id', app(CurrentContext::class)->branchIds());
        })->orderBy('name')->get();

        return view('gestisp.warehouses.create', compact('users'));
    }

    /**
     * Guarda un nuevo almacén.
     *
     * QUIEN LO CREA NO ES QUIEN LO POSEE.
     *
     * Antes, dejar el dueño en blanco lo ponía a nombre de quien lo
     * creaba. Eso tenía dos consecuencias malas: un almacén creado por
     * la oficina para un técnico quedaba a nombre de la oficina, y no
     * había forma de dejar un almacén SIN dueño — que es justo lo que es
     * una bodega o el de cabecera.
     *
     * Ahora en blanco significa GENERAL, y quien lo creó se guarda
     * aparte.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateWarehouse($request);

        Warehouse::create([
            'description' => $validated['description'],
            'branch_id'   => app(CurrentContext::class)->branchParaEscritura($request->input('branch_id')),
            'user_id'     => $validated['user_id'] ?? null,
            'created_by'  => Auth::id(),
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
        $this->exigirMismaSucursal($warehouse);

        $warehouse->loadMissing('creator');

        $users = User::whereHas('branches', function ($q) {
            $q->whereIn('branches.id', app(CurrentContext::class)->branchIds());
        })->orderBy('name')->get();

        return view('gestisp.warehouses.edit', compact('warehouse', 'users'));
    }

    /**
     * Actualiza un almacén existente: su nombre y su dueño.
     *
     * EN BLANCO SÍ QUITA EL DUEÑO. Antes se conservaba el que hubiera,
     * así que un almacén asignado por error no se podía devolver a
     * «general» desde la pantalla — había que ir a la base de datos.
     */
    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->exigirMismaSucursal($warehouse);

        $validated = $this->validateWarehouse($request);

        $warehouse->update([
            'description' => $validated['description'],
            'user_id' => $validated['user_id'] ?? null,
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

        $costos = app(InventoryCosting::class);

        $inventories = Inventory::where('warehouse_id', $warehouse->id)
            ->with('material')
            ->get()
            ->groupBy('material_id')
            ->map(function ($items) use ($costos) {
                $material = $items->first()->material;
                $sns = $items->pluck('serial_number')->filter()->toArray();

                return [
                    'material' => $material->name,
                    'quantity' => $items->sum('quantity'),
                    'unit_of_measurement' => $items->first()->unit_of_measurement,
                    'sns' => implode(', ', $sns),
                    'valor' => $costos->valorarMaterial($items),
                ];
            });

        // EL PDF LLEVA COSTOS SOLO SI QUIEN LO DESCARGA PUEDE VERLOS.
        // Un PDF se guarda, se reenvía y se imprime: si el permiso solo
        // se comprobara en la pantalla, bastaría con descargar el
        // inventario para saltárselo.
        $data = [
            'inventoriesData' => $inventories,
            'warehouse' => $warehouse,
            'verCostos' => Gate::allows('materials.costs'),
            'resumenValor' => $this->resumirValor($inventories),
        ];

        $pdf = \App\Support\PdfBranding::make('gestisp.warehouses.pdf', $data);

        return $pdf->download('Inventario_'.$warehouse->description.'.pdf');

    }

    /**
     * Corta el paso a almacenes de otra sucursal.
     *
     * El enlace de edición nunca los ofrece, pero la ruta acepta
     * cualquier id: sin esto bastaba con cambiar el número en la URL
     * para reasignarle el dueño al almacén de otra sede.
     */
    private function exigirMismaSucursal(Warehouse $warehouse): void
    {
        abort_unless(
            app(CurrentContext::class)->permiteSucursal($warehouse->branch_id),
            403,
            'Ese almacén pertenece a otra sucursal.',
        );
    }

    /**
     * Reglas de validación compartidas entre store y update.
     */
    private function validateWarehouse(Request $request): array
    {
        return $request->validate([
            'description' => 'required|string|max:255',
            // Opcional: el DUEÑO. En blanco significa almacén general
            // —bodega, cabecera—, no «a nombre de quien lo crea».
            'user_id' => 'nullable|exists:users,id',
        ]);
    }
}
