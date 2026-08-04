<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Material;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
            ->with('category')
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

        return view('gestisp.materials.create', compact('categories'));
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
            'branch_id'    => session('branch_id'),
            'name'         => $validated['name'],
            'category_id'  => $validated['category_id'],
            'is_equipment' => $request->boolean('is_equipment'),
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

        return view('gestisp.materials.edit', compact('material', 'categories'));
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
                Rule::exists('categories', 'id')->where('branch_id', session('branch_id')),
            ],
            'is_equipment' => 'nullable|boolean',
        ], [
            'category_id.exists' => 'La categoría elegida no existe en esta sucursal.',
        ]);
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
            (int) $material->branch_id === (int) session('branch_id'),
            403,
            'Ese material pertenece a otra sucursal.',
        );
    }
}
