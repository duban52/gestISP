<?php

namespace App\Http\Controllers;

use App\Billing\Enums\ProrationMode;
use App\Models\Branch;
use App\Models\BranchBillingSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Controlador de Sucursales (Branches)
 *
 * Gestiona el CRUD completo de las sucursales del sistema.
 * Cada sucursal agrupa clientes, contratos, OLTs, routers, cajas y almacenes,
 * funcionando como unidad de negocio independiente dentro de GestISP.
 */
class BranchController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     *
     * Cada acción del CRUD requiere el permiso correspondiente,
     * validado por el middleware check.permission.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:branches.index')->only('index');
        $this->middleware('check.permission:branches.create')->only('create', 'store');
        $this->middleware('check.permission:branches.edit')->only('edit', 'update');
        $this->middleware('check.permission:branches.destroy')->only('destroy');
    }

    /**
     * Lista todas las sucursales.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente, que se encarga de la
     * paginación, búsqueda y ordenamiento en el navegador.
     */
    public function index(): View
    {
        $branches = Branch::all();

        return view('gestisp.branches.index', compact('branches'));
    }

    /**
     * Muestra el formulario de creación de sucursal.
     */
    public function create(): View
    {
        return view('gestisp.branches.create');
    }

    /**
     * Guarda una nueva sucursal en la base de datos.
     *
     * Si el request incluye una imagen (logo de la sucursal),
     * se almacena en storage/app/public/branches y se guarda la ruta.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBranch($request);

        // Almacenar la imagen si fue enviada
        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('branches', 'public');
        }

        Branch::create($validated);

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal creada exitosamente.');
    }

    /**
     * Muestra el detalle de una sucursal específica.
     */
    public function show(Branch $branch): View
    {
        return view('gestisp.branches.show', compact('branch'));
    }

    /**
     * Muestra el formulario de edición de una sucursal.
     */
    public function edit(Branch $branch): View
    {
        // Configuración de facturación (se crea con los defaults
        // históricos si la sucursal aún no tiene)
        $billingSettings = BranchBillingSetting::forBranch($branch->id);
        $prorationModes = ProrationMode::cases();

        return view('gestisp.branches.edit', compact('branch', 'billingSettings', 'prorationModes'));
    }

    /**
     * Actualiza los datos de una sucursal existente.
     *
     * Si el usuario sube una nueva imagen, la anterior se elimina
     * del disco antes de guardar la nueva, evitando archivos huérfanos.
     */
    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $this->validateBranch($request, $branch->id);

        // Configuración de facturación de la sucursal (modo de
        // prorrateo, plazos y umbral de suspensión) — las reglas
        // que consumen los servicios de app/Billing/Services
        $billingValidated = $request->validate([
            'proration_mode' => ['required', Rule::enum(ProrationMode::class)],
            'due_days' => 'required|integer|min:1|max:90',
            'suspension_threshold' => 'required|integer|min:1|max:12',
            'suspension_days' => 'required|integer|min:1|max:90',
        ], [
            'proration_mode.required' => 'Debe elegir el modo de facturación del primer mes.',
            'due_days.*' => 'Los días de plazo deben estar entre 1 y 90.',
            'suspension_threshold.*' => 'El umbral de suspensión debe estar entre 1 y 12 facturas.',
            'suspension_days.*' => 'Los días hasta el corte deben estar entre 1 y 90.',
        ]);

        // Reemplazar la imagen si se envió una nueva
        if ($request->hasFile('image')) {
            // Eliminar la imagen anterior del disco
            File::delete(public_path('storage/' . $branch->image));

            // Almacenar la nueva imagen
            $validated['image'] = $request->file('image')->store('branches', 'public');
        }

        $branch->update($validated);

        BranchBillingSetting::forBranch($branch->id)->update($billingValidated);

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal actualizada exitosamente.');
    }

    /**
     * Elimina una sucursal.
     *
     * NOTA: si la sucursal tiene registros relacionados (clientes,
     * contratos, OLTs, etc.) la eliminación fallará por las llaves
     * foráneas de la base de datos.
     */
    public function destroy(Branch $branch): RedirectResponse
    {
        $branch->delete();

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal eliminada exitosamente.');
    }

    /**
     * Reglas de validación compartidas entre store y update.
     *
     * @param Request  $request  Datos del formulario
     * @param int|null $ignoreId ID de la sucursal a excluir de la regla
     *                           unique (solo en update, para permitir
     *                           conservar el mismo nombre)
     */
    private function validateBranch(Request $request, ?int $ignoreId = null): array
    {
        // La regla unique excluye el registro actual cuando se está editando
        $uniqueName = 'unique:branches,name' . ($ignoreId ? ',' . $ignoreId : '');

        return $request->validate([
            'nit'                    => 'required|string|max:20',
            'name'                   => "required|string|max:40|{$uniqueName}",
            'country'                => 'required|string|max:60',
            'department'             => 'required|string|max:60',
            'municipality'           => 'required|string|max:60',
            'address'                => 'required|string|max:255',
            'number_phone'           => 'required|string|max:20',
            'additional_number'      => 'nullable|string|max:20',
            'image'                  => 'nullable|image',
            'moving_price'           => 'nullable|numeric',
            'reconnection_price'     => 'nullable|numeric',
            'message_custom_invoice' => 'nullable|string',
            'observation'            => 'nullable|string',
        ]);
    }
}
