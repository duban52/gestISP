<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Tenancy\CurrentContext;
use Illuminate\Validation\Rule;

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
        // Retirar un plan es EDITARLO, no borrarlo: los contratos que
        // lo tienen lo conservan. Por eso lleva plans.edit y no
        // plans.destroy.
        $this->middleware('check.permission:plans.edit')->only('edit', 'update', 'toggle');
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
        // Se traen TODOS, activos y retirados: el listado es donde se
        // administran, y un plan retirado tiene que poder reactivarse.
        // Quien filtra por activos es el formulario de contrato.
        $plans = Plan::disponibles()
            ->with(['services', 'branch'])
            ->withCount('contracts')
            ->orderBy('name')
            ->get();

        return view('gestisp.plans.index', compact('plans'));
    }

    /**
     * Muestra el formulario de creación de plan
     * con los servicios de la sucursal disponibles para asociar.
     */
    public function create(): View
    {
        $services = Service::disponibles()->orderBy('name')->get();

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
            'active'    => $request->boolean('active', true),
            'branch_id' => $this->ambitoDe($request),
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
        $services = Service::disponibles()->orderBy('name')->get();

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

        $ambito = $this->ambitoDe($request);

        if ($aviso = $this->motivoParaNoEncerrar($plan, $ambito)) {
            return back()->withInput()->with('error', $aviso);
        }

        $plan->update([
            'name' => $validated['name'],
            'active' => $request->boolean('active', true),
            'branch_id' => $ambito,
        ]);

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
    /**
     * Retira un plan del catálogo, o lo devuelve.
     *
     * ES LO QUE SUSTITUYE AL BORRADO
     * ------------------------------
     * Un plan con contratos vivos ya no se puede borrar —la clave
     * foránea lo impide desde la migración de la fase 13—, así que
     * dejar de venderlo es esto. Los contratos que ya lo tienen lo
     * conservan y se siguen facturando igual; lo único que desaparece
     * es la opción de elegirlo al dar de alta.
     */
    public function toggle(Plan $plan): RedirectResponse
    {
        $plan->update(['active' => !$plan->active]);

        return back()->with('success-update', $plan->active
            ? sprintf('El plan «%s» vuelve a ofrecerse.', $plan->name)
            : sprintf('El plan «%s» queda retirado: no se ofrecerá en contratos nuevos.', $plan->name));
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        // Bloquear la eliminación si hay contratos usando este plan.
        //
        // Esto es la red de ARRIBA: da un mensaje legible. La de abajo
        // es la clave foránea, que desde la fase 13 es `restrictOnDelete`
        // y ataja también los borrados que no pasen por aquí.
        if ($plan->contracts()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el plan tiene contratos asociados. Retírelo en vez de borrarlo.'
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
        // La tabla tiene UNIQUE (company_id, branch_key, name), donde
        // branch_key es COALESCE(branch_id, 0). O sea: dos sucursales
        // pueden tener cada una su "Plan 100M", y la empresa puede
        // tener el suyo, pero no dos iguales en el mismo ambito.
        //
        // Sin esta regla el duplicado no se avisaba en el formulario —
        // lo rechazaba la base y salia un error 500.
        //
        // La sucursal se resuelve igual que en store(), pero SIN
        // lanzar: si en consolidado no se eligio, es branchParaEscritura
        // quien lo dira con su mensaje, y aqui no toca reventar.
        $branchId = $this->ambitoDe($request);

        $empresaId = app(CurrentContext::class)->companyId();

        return $request->validate([
            'name'       => [
                'required', 'string', 'max:255',
                Rule::unique('plans', 'name')
                    ->where(fn ($q) => $q
                        ->where('company_id', $empresaId)
                        // Se compara contra branch_key y no contra
                        // branch_id: con el nulo, `where(null)` no
                        // encuentra nada y el duplicado pasaria.
                        ->where('branch_key', $branchId ?? 0))
                    ->ignore($request->route('plan')?->id),
            ],
            'de_la_empresa' => 'nullable|boolean',
            'active'     => 'nullable|boolean',
            'services'   => 'nullable|array',
            // UN PLAN NO PUEDE LLEVAR UN SERVICIO QUE NO ALCANZA.
            //
            // Era `exists:services,id` a secas: valía CUALQUIER fila de
            // la tabla. Dos cosas se colaban por ahí. La primera, un
            // servicio de otra EMPRESA —el formulario no lo ofrece,
            // pero un POST a mano sí—. La segunda, más probable y más
            // silenciosa: un plan de la empresa con un servicio
            // exclusivo de una sede, que en las demás sucursales
            // factura un plan al que le falta un renglón.
            'services.*' => [
                Rule::exists('services', 'id')
                    ->where('company_id', $empresaId)
                    ->where(fn ($q) => $q->where(
                        fn ($w) => $branchId === null
                            ? $w->whereNull('branch_id')
                            : $w->whereNull('branch_id')->orWhere('branch_id', $branchId),
                    )),
            ],
        ], [
            'name.unique' => 'Ya existe un plan con ese nombre en este ámbito.',
            'services.*.exists' => $branchId === null
                ? 'Un plan de la empresa solo puede llevar servicios de la empresa: hay uno '
                    . 'que es exclusivo de una sucursal y en las demás dejaría el plan incompleto.'
                : 'Hay un servicio que no está disponible en esta sucursal.',
        ]);
    }

    /**
     * ¿Se puede encerrar este plan en una sola sucursal?
     *
     * Pasarlo de la empresa a una sede se lo quita a las demás, y ahí
     * sí se nota: la validación del contrato exige que su plan sea de
     * la empresa o de SU sucursal (ContractController), así que un
     * contrato de otra sede con ese plan deja de poder guardarse — ni
     * para cambiarle la dirección. Las facturas ya emitidas no se
     * tocan, pero el contrato queda trabado.
     *
     * Ampliar el ámbito —de una sede a la empresa— no se comprueba:
     * no le quita el plan a nadie.
     *
     * @return string|null El motivo, o null si se puede
     */
    private function motivoParaNoEncerrar(Plan $plan, ?int $destino): ?string
    {
        if ($destino === null || (int) $plan->branch_id === $destino) {
            return null;
        }

        $contratos = $plan->contracts()
            ->where(fn ($q) => $q->whereNull('contracts.branch_id')
                ->orWhere('contracts.branch_id', '!=', $destino))
            ->count();

        if ($contratos === 0) {
            return null;
        }

        return sprintf(
            'No se puede dejar «%s» solo para esta sucursal: lo tienen %d contrato(s) de otras sedes, '
            . 'que quedarían sin poder guardarse. Páselos antes a otro plan.',
            $plan->name,
            $contratos,
        );
    }

    /**
     * En que ambito vive el plan: la empresa, o una sucursal.
     *
     * Devuelve null cuando es DE LA EMPRESA —disponible en todas sus
     * sedes— y el id de la sucursal cuando es exclusivo de ella.
     *
     * Por defecto, de la empresa. Es lo que quiere la mayoria: el
     * catalogo suele ser el mismo en todas las sedes, y el plan local
     * es la excepcion. Que el caso comun sea el que NO duplica es
     * justamente el objetivo de la fase 13.
     */
    private function ambitoDe(Request $request): ?int
    {
        if ($request->boolean('de_la_empresa', true)) {
            return null;
        }

        return rescue(
            fn () => app(CurrentContext::class)->branchParaEscritura($request->input('branch_id')),
            null,
            report: false,
        );
    }
}
