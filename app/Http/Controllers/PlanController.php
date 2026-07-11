<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Controlador de Planes
 *
 * Gestiona el CRUD de los planes comerciales del ISP. Un plan es una
 * combinación de uno o más servicios (relación muchos a muchos vía
 * tabla pivote plan_service): por ejemplo, el plan "Combo Hogar"
 * puede agrupar los servicios "Internet 100M" y "Televisión".
 * Los contratos de los clientes se asocian a un plan.
 */
class PlanController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:plans.index')->only('index');
        $this->middleware('check.permission:plans.create')->only('create', 'store');
        $this->middleware('check.permission:plans.edit')->only('edit', 'update');
        $this->middleware('check.permission:plans.destroy')->only('destroy');
    }

    /**
     * Lista los planes de la sucursal activa.
     *
     * Se cargan con with('services') para evitar el problema N+1:
     * la vista muestra los servicios de cada plan y su precio total,
     * y sin eager loading cada fila dispararía una consulta extra.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente.
     */
    public function index(): View
    {
        $plans = Plan::where('branch_id', session('branch_id'))
            ->with('services')
            ->get();

        return view('gestisp.plans.index', compact('plans'));
    }

    /**
     * Muestra el formulario de creación de plan
     * con los servicios de la sucursal disponibles para asociar.
     */
    public function create(): View
    {
        $services = Service::where('branch_id', session('branch_id'))->get();

        return view('gestisp.plans.create', compact('services'));
    }

    /**
     * Guarda un nuevo plan y asocia sus servicios.
     *
     * La asociación usa attach() sobre la relación muchos a muchos,
     * insertando los registros en la tabla pivote.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        $plan = Plan::create([
            'name'      => $validated['name'],
            'branch_id' => session('branch_id'),
        ]);

        // Asociar los servicios seleccionados al plan (tabla pivote)
        $plan->services()->attach($validated['services'] ?? []);

        return redirect()
            ->route('plans.index')
            ->with('success-create', 'Plan creado con éxito.');
    }

    /**
     * Muestra el formulario de edición de un plan.
     *
     * Los servicios se filtran por la sucursal activa (la versión
     * anterior usaba Service::all(), que mostraba servicios de
     * otras sucursales).
     */
    public function edit(Plan $plan): View
    {
        $services = Service::where('branch_id', session('branch_id'))->get();

        return view('gestisp.plans.edit', compact('plan', 'services'));
    }

    /**
     * Actualiza un plan y sincroniza sus servicios.
     *
     * sync() reemplaza las asociaciones de la tabla pivote por las
     * nuevas: agrega las que faltan y elimina las deseleccionadas.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        $plan->update(['name' => $validated['name']]);

        // Sincronizar servicios: refleja exactamente la selección del formulario
        $plan->services()->sync($validated['services'] ?? []);

        return redirect()
            ->route('plans.index')
            ->with('success-update', 'Plan actualizado con éxito.');
    }

    /**
     * Elimina un plan.
     *
     * Se bloquea si tiene contratos asociados: eliminar un plan en
     * uso dejaría contratos sin plan o fallaría por llave foránea.
     * Las asociaciones de la tabla pivote se desvinculan antes
     * de eliminar para no dejar registros huérfanos.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        // Bloquear la eliminación si hay contratos usando este plan
        if ($plan->contracts()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el plan tiene contratos asociados.'
            );
        }

        // Desvincular los servicios de la tabla pivote
        $plan->services()->detach();

        $plan->delete();

        return redirect()
            ->route('plans.index')
            ->with('success-delete', 'Plan eliminado con éxito.');
    }

    /**
     * Reglas de validación compartidas entre store y update.
     *
     * - services: array opcional de IDs
     * - services.*: cada ID debe existir en la tabla services
     */
    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'name'       => 'required|string|max:255',
            'services'   => 'nullable|array',
            'services.*' => 'exists:services,id',
        ]);
    }
}
