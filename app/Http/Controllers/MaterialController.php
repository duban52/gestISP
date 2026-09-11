<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Tenancy\CurrentContext;

/**
 * Controlador de Materiales
 *
 * Gestiona el CRUD del catálogo de materiales del ISP: equipos
 * (ONTs, routers — con número de serie) y consumibles (cable,
 * conectores, grapas — sin serial). El flag is_equipment determina
 * si el material se rastrea por serial en el inventario.
 *
 * El stock por almacén vive en la tabla inventories; este catálogo
 * solo define QUÉ materiales existen y su categoría.
 *
 * TODO ES POR SUCURSAL. Cada sede lleva su propio catálogo: antes
 * era global y un material creado en una sucursal aparecía en todas,
 * aunque su inventario estuviera solo en la primera.
 */
class MaterialController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:materials.index')->only('index');
        $this->middleware('check.permission:materials.create')->only('create', 'store');
        $this->middleware('check.permission:materials.edit')->only('edit', 'update');
        $this->middleware('check.permission:materials.destroy')->only('destroy');
    }

    /**
     * Lista el catálogo de materiales.
     *
     * Se precarga la categoría con with() para evitar el problema
     * N+1 (la vista muestra la categoría de cada material), y se
     * cuenta el inventario con withCount() para mostrar cuántos
     * registros de stock tiene cada material.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente.
     */
    public function index(): View
    {
        $materials = Material::deSucursal()
            ->with(['category', 'branch'])
            ->withCount('inventories')
            ->get();

        return view('gestisp.materials.index', compact('materials'));
    }

    /**
     * Muestra el formulario de creación con las categorías disponibles.
     */
    public function create(): View
    {
        $categories = Category::deSucursal()->orderBy('name')->get();
        $unidades = Material::UNIDADES;

        return view('gestisp.materials.create', compact('categories', 'unidades'));
    }

    /**
     * Guarda un nuevo material en el catálogo.
     *
     * is_equipment llega como checkbox: presente = equipo con
     * serial, ausente = consumible. Se normaliza a booleano con
     * $request->boolean().
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateMaterial($request);

        Material::create([
            'branch_id'    => app(CurrentContext::class)->branchParaEscritura($request->input('branch_id')),
            'name'         => $validated['name'],
            'category_id'  => $validated['category_id'],
            'is_equipment' => $request->boolean('is_equipment'),
            'unit_of_measurement' => $validated['unit_of_measurement'],
            'purchase_unit_value' => $this->valorDeCompra($request, $validated),
        ]);

        return redirect()
            ->route('materials.index')
            ->with('success-create', 'Material creado correctamente.');
    }

    /**
     * Muestra el formulario de edición de un material.
     */
    public function edit(Material $material): View
    {
        $this->exigirMismaSucursal($material);

        $categories = Category::deSucursal()->orderBy('name')->get();
        $unidades = Material::UNIDADES;

        return view('gestisp.materials.edit', compact('material', 'categories', 'unidades'));
    }

    /**
     * Actualiza un material del catálogo.
     *
     * PRECAUCIÓN: cambiar is_equipment en un material que ya tiene
     * inventario altera cómo se interpreta su stock (con o sin
     * seriales); idealmente solo debe cambiarse en materiales nuevos.
     */
    public function update(Request $request, Material $material): RedirectResponse
    {
        $this->exigirMismaSucursal($material);

        $validated = $this->validateMaterial($request);

        $material->update([
            'name'         => $validated['name'],
            'category_id'  => $validated['category_id'],
            'is_equipment' => $request->boolean('is_equipment'),
            // CAMBIARLA NO REESCRIBE EL HISTÓRICO. Los movimientos ya
            // registrados conservan la unidad con la que se hicieron:
            // decir hoy que la fibra son metros no convierte en metros
            // las «unidades» que alguien ingresó el año pasado.
            'unit_of_measurement' => $validated['unit_of_measurement'],
            // Si el formulario llegó SIN el campo —porque quien edita no
            // tiene permiso para ver costos— se conserva el que había.
            // Guardar null ahí borraría un dato que esa persona ni
            // siquiera podía ver.
            'purchase_unit_value' => $request->has('purchase_unit_value')
                ? $this->valorDeCompra($request, $validated)
                : $material->purchase_unit_value,
        ]);

        return redirect()
            ->route('materials.index')
            ->with('success-update', 'Material editado correctamente.');
    }

    /**
     * Elimina un material del catálogo.
     *
     * Se bloquea si tiene inventario u órdenes técnicas asociadas:
     * eliminarlo rompería la trazabilidad del stock y del material
     * usado en instalaciones.
     */
    public function destroy(Material $material): RedirectResponse
    {
        $this->exigirMismaSucursal($material);

        // Bloquear si hay existencias registradas en algún almacén
        if ($material->inventories()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el material tiene inventario registrado.'
            );
        }

        // Bloquear si fue usado en órdenes técnicas (trazabilidad)
        if ($material->technicalOrders()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el material está referenciado en órdenes técnicas.'
            );
        }

        $material->delete();

        return redirect()
            ->route('materials.index')
            ->with('success-delete', 'Material eliminado correctamente.');
    }

    /**
     * Reglas de validación compartidas entre store y update.
     */
    private function validateMaterial(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:255',
            // La categoría debe ser de ESTA sucursal: con un simple
            // exists: bastaba con enviar el id de otra sede para
            // colar un material bajo una categoría ajena.
            'category_id'  => [
                'required',
                Rule::exists('categories', 'id')->whereIn('branch_id', app(CurrentContext::class)->branchIds()),
            ],
            // OBLIGATORIA: es lo que da sentido a las existencias del
            // material. Antes se preguntaba en cada movimiento, y nada
            // impedía que el mismo material entrara en «Unidades» y
            // saliera en «Metros».
            'unit_of_measurement' => ['required', Rule::in(Material::UNIDADES)],
            'is_equipment' => 'nullable|boolean',
            // OPCIONAL, y el vacío se guarda como NULL, no como cero.
            // Un cero se suma en los totales y hace creer que el
            // material no costó nada; un nulo se distingue y la
            // pantalla puede decir que falta el dato.
            'purchase_unit_value' => 'nullable|numeric|min:0|max:99999999999.99',
        ], [
            'category_id.exists' => 'La categoría elegida no existe en esta sucursal.',
            'unit_of_measurement.required' => 'Indique en qué unidad se mide este material.',
            'unit_of_measurement.in' => 'Esa unidad de medida no está permitida.',
            'purchase_unit_value.numeric' => 'El valor unitario de compra debe ser un número.',
            'purchase_unit_value.min' => 'El valor unitario de compra no puede ser negativo.',
        ]);
    }

    /**
     * El valor unitario de compra tal como debe guardarse.
     *
     * Cadena vacía y null son lo mismo aquí: «no se sabe». Se
     * normalizan a NULL para que los totales puedan distinguir un
     * material sin precio de uno que costó cero.
     */
    private function valorDeCompra(Request $request, array $validated): ?float
    {
        $valor = $validated['purchase_unit_value'] ?? null;

        return ($valor === null || $valor === '') ? null : (float) $valor;
    }

    /**
     * Corta el paso a materiales de otra sucursal.
     *
     * El enlace de edición nunca los ofrece, pero la ruta acepta
     * cualquier id: sin esto bastaba con cambiar el número en la URL
     * para editar o borrar el catálogo de otra sede.
     */
    private function exigirMismaSucursal(Material $material): void
    {
        abort_unless(
            app(CurrentContext::class)->permiteSucursal($material->branch_id),
            403,
            'Ese material pertenece a otra sucursal.',
        );
    }
}
