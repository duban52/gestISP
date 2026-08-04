<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Controlador de Categorías de material.
 *
 * TODO ES POR SUCURSAL. Cada sede lleva su propio catálogo: antes las
 * categorías eran globales y una creada en Gómez Plata aparecía
 * también en Yarumal, llenando los selectores de cosas que en esa
 * bodega no existen.
 *
 * Las rutas reciben el id por la URL, así que además de FILTRAR los
 * listados hay que BLOQUEAR el acceso directo a una categoría de otra
 * sucursal: sin eso bastaba con cambiar el número en la barra de
 * direcciones para editar o borrar el catálogo ajeno.
 */
class CategoryController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:categories.index')->only('index');
        $this->middleware('check.permission:categories.create')->only('create', 'store');
        $this->middleware('check.permission:categories.edit')->only('edit', 'update');
        $this->middleware('check.permission:categories.destroy')->only('destroy');
    }

    /** Categorías de la sucursal activa. */
    public function index(): View
    {
        $categories = Category::deSucursal()
            ->withCount('materials')
            ->orderBy('name')
            ->simplePaginate(8);

        return view('gestisp.materials.categories.index', compact('categories'));
    }

    public function create(): View
    {
        return view('gestisp.materials.categories.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validar($request);

        Category::create([
            'branch_id' => session('branch_id'),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
        ]);

        return redirect()->route('categories.index')
            ->with('success-create', 'Categoría creada correctamente');
    }

    public function edit(Category $category): View
    {
        $this->exigirMismaSucursal($category);

        return view('gestisp.materials.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->exigirMismaSucursal($category);

        $validated = $this->validar($request);

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? '',
        ]);

        return redirect()->route('categories.index')
            ->with('success-update', 'Categoría actualizada correctamente');
    }

    /**
     * Elimina una categoría.
     *
     * Se bloquea si tiene materiales: al borrarla, la clave foránea
     * los dejaría con category_id nulo (SET NULL) y el catálogo
     * quedaría sin clasificar sin que nadie se enterara.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->exigirMismaSucursal($category);

        if ($category->materials()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: la categoría tiene materiales asociados.',
            );
        }

        $category->delete();

        return redirect()->route('categories.index')
            ->with('success-delete', 'Categoría eliminada correctamente');
    }

    /**
     * Reglas compartidas por store y update.
     *
     * El nombre es único DENTRO de la sucursal: dos categorías
     * "Cables" en la misma bodega no se distinguen al elegirlas, pero
     * dos sedes sí pueden tener cada una la suya.
     */
    private function validar(Request $request): array
    {
        $branchId = session('branch_id');
        $categoria = $request->route('category');

        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                \Illuminate\Validation\Rule::unique('categories', 'name')
                    ->where(fn ($q) => $q->where('branch_id', $branchId))
                    ->ignore($categoria?->id),
            ],
            'description' => 'nullable|string|max:255',
        ], [
            'name.unique' => 'Ya existe una categoría con ese nombre en esta sucursal.',
        ]);
    }

    /** Corta el paso a categorías de otra sucursal. */
    private function exigirMismaSucursal(Category $category): void
    {
        abort_unless(
            (int) $category->branch_id === (int) session('branch_id'),
            403,
            'Esa categoría pertenece a otra sucursal.',
        );
    }
}
