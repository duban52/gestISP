<?php

namespace App\Http\Controllers;

use App\Models\AffinityGroup;
use App\Tenancy\CurrentContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración de los grupos de afinidad.
 *
 * QUÉ SE ADMINISTRA AQUÍ
 * ----------------------
 * La clasificación de los contratos de la empresa, y con ella la
 * decisión de qué documento emite cada uno: factura electrónica —con
 * firma digital y numeración autorizada— o documento interno.
 *
 * NO HAY `destroy` LIBRE
 * ----------------------
 * Un grupo con contratos no se borra: se perdería la clasificación de
 * todos ellos, y con ella la razón por la que cada uno facturaba como
 * facturaba. Tampoco se borra el grupo por defecto, porque la empresa
 * se quedaría sin uno y los contratos nuevos nacerían sin grupo. En
 * los dos casos lo correcto es DESACTIVARLO: deja de ofrecerse al dar
 * de alta y no toca nada de lo ya clasificado.
 *
 * El borrado existe, pero solo para el caso en que de verdad no ha
 * pasado nada: un grupo recién creado, sin contratos y que no es el
 * de por defecto.
 *
 * TODO QUEDA AUDITADO
 * -------------------
 * Crear, editar, activar, desactivar y cambiar el grupo por defecto se
 * registran en la trazabilidad por la auditoría global, sin que aquí
 * haya que hacer nada. Si un contrato cambia de modalidad de
 * facturación, tiene que poder responderse quién lo decidió y cuándo.
 */
class AffinityGroupController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:affinity_groups.index')->only('index');
        $this->middleware('check.permission:affinity_groups.create')->only('create', 'store');
        $this->middleware('check.permission:affinity_groups.edit')
            ->only('edit', 'update', 'marcarPorDefecto');
        $this->middleware('check.permission:affinity_groups.destroy')->only('destroy');
    }

    public function index(): View
    {
        // El global scope de empresa ya limita a la del contexto: no
        // hace falta —ni debe— filtrar a mano por company_id.
        $grupos = AffinityGroup::ordenados()
            ->withCount('contracts')
            ->get();

        return view('gestisp.affinity_groups.index', compact('grupos'));
    }

    public function create(): View
    {
        return view('gestisp.affinity_groups.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $companyId = app(CurrentContext::class)->companyId();

        // Marcar uno por defecto tiene que quitárselo al anterior, y
        // las dos cosas van juntas o no van: si fallara entre medias,
        // la empresa se quedaría sin ninguno (o con dos, que la base
        // rechaza y dejaría la creación a medias).
        $grupo = DB::transaction(function () use ($datos, $companyId) {
            // PRIMERO se le quita al anterior y DESPUES se crea este.
            // Al reves, el UNIQUE de la base rechaza el segundo
            // predeterminado antes de que se quite el primero, y lo que
            // deberia ser una operacion normal sale como un error 500.
            if ($datos['is_default']) {
                $this->quitarPorDefectoA($companyId);
            }

            return AffinityGroup::create($datos + ['company_id' => $companyId]);
        });

        return redirect()->route('affinity_groups.index')
            ->with('success-create', "Grupo «{$grupo->name}» creado correctamente.");
    }

    public function edit(AffinityGroup $affinity_group): View
    {
        return view('gestisp.affinity_groups.edit', ['grupo' => $affinity_group]);
    }

    public function update(Request $request, AffinityGroup $affinity_group): RedirectResponse
    {
        $datos = $this->validar($request, $affinity_group);

        // Dos cosas que no se pueden hacer sobre el grupo por defecto,
        // y las dos por el mismo motivo: dejarían a la empresa sin uno,
        // y los contratos nuevos nacerían sin grupo — que es justo lo
        // que este módulo existe para evitar.
        //
        // No se resuelve marcando otro automáticamente: cuál debe ser
        // el predeterminado es una decisión de negocio, y elegirlo por
        // el usuario sin decírselo es peor que pedirle que lo elija.
        if ($affinity_group->is_default) {
            if (!$datos['active']) {
                return back()->withInput()->withErrors([
                    'active' => 'No se puede desactivar el grupo predeterminado. '
                        . 'Marque otro como predeterminado y vuelva a intentarlo.',
                ]);
            }

            if (!$datos['is_default']) {
                return back()->withInput()->withErrors([
                    'is_default' => 'La empresa tiene que tener un grupo predeterminado. '
                        . 'Para cambiarlo, marque otro: este dejará de serlo solo.',
                ]);
            }
        }

        DB::transaction(function () use ($affinity_group, $datos) {
            // Mismo orden que en store(), y por el mismo motivo.
            if ($datos['is_default']) {
                $this->quitarPorDefectoA($affinity_group->company_id, $affinity_group->id);
            }

            $affinity_group->update($datos);
        });

        return redirect()->route('affinity_groups.index')
            ->with('success-update', "Grupo «{$affinity_group->name}» actualizado.");
    }

    /**
     * Marca un grupo como el predeterminado de la empresa.
     *
     * Va en su propia acción —y no solo en la casilla del formulario—
     * porque es la operación que más se hace desde el listado y no
     * tiene sentido obligar a entrar a editar para eso.
     */
    public function marcarPorDefecto(AffinityGroup $affinity_group): RedirectResponse
    {
        if (!$affinity_group->active) {
            return back()->with(
                'error',
                'Un grupo inactivo no puede ser el predeterminado: no se ofrece al dar de alta contratos.',
            );
        }

        DB::transaction(function () use ($affinity_group) {
            // Primero se le quita a los demás y después se le pone a
            // este. Al revés, el UNIQUE de la base rechazaría el
            // segundo por defecto antes de que se quitara el primero.
            $this->quitarPorDefectoA($affinity_group->company_id, $affinity_group->id);

            $affinity_group->update(['is_default' => true]);
        });

        return redirect()->route('affinity_groups.index')->with(
            'success-update',
            "«{$affinity_group->name}» es ahora el grupo predeterminado.",
        );
    }

    public function destroy(AffinityGroup $affinity_group): RedirectResponse
    {
        if (!$affinity_group->sePuedeEliminar()) {
            $motivo = $affinity_group->is_default
                ? 'es el grupo predeterminado de la empresa'
                : 'tiene contratos asignados';

            return back()->with(
                'error',
                "No se puede eliminar «{$affinity_group->name}»: {$motivo}. "
                . 'Desactívelo en su lugar: deja de ofrecerse al dar de alta y no toca lo ya clasificado.',
            );
        }

        $nombre = $affinity_group->name;
        $affinity_group->delete();

        return redirect()->route('affinity_groups.index')
            ->with('success-delete', "Grupo «{$nombre}» eliminado.");
    }

    // ==================== Apoyo ====================

    /**
     * Le quita la marca de predeterminado a los grupos de la empresa.
     *
     * El UNIQUE de la base sobre la columna generada ya impide que
     * haya dos, pero se limpia igualmente aquí: dejar que lo resuelva
     * un error de base de datos convertiría una operación normal en un
     * 500 delante del usuario.
     */
    private function quitarPorDefectoA(int $companyId, ?int $exceptoId = null): void
    {
        AffinityGroup::where('company_id', $companyId)
            ->where('is_default', true)
            ->when($exceptoId, fn ($q) => $q->where('id', '!=', $exceptoId))
            ->update(['is_default' => false]);
    }

    /**
     * Reglas compartidas por store y update.
     *
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?AffinityGroup $grupo = null): array
    {
        $companyId = app(CurrentContext::class)->companyId();

        $datos = $request->validate([
            // El código es único DENTRO de la empresa: dos empresas
            // pueden tener cada una su «GEN».
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-_]+$/',
                Rule::unique('affinity_groups', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($grupo?->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'requires_electronic_invoicing' => ['nullable', 'boolean'],
            'requires_client_tax_data' => ['nullable', 'boolean'],
            // Códigos de los catálogos DIAN. Se guardan tal cual y se
            // validarán contra el catálogo cuando este exista (fase 8).
            'dian_operation_type_code' => ['nullable', 'string', 'max:10'],
            'default_payment_means_code' => ['nullable', 'string', 'max:10'],
            'default_payment_method_code' => ['nullable', 'string', 'max:10'],
            'active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'code.regex' => 'El código solo admite letras, números, guiones y guiones bajos.',
            'code.unique' => 'Ya existe un grupo con ese código en esta empresa.',
        ]);

        // Las casillas no envían nada cuando están desmarcadas, así que
        // hay que fijarlas a false explícitamente o el update no las
        // apagaría nunca.
        foreach (['requires_electronic_invoicing', 'requires_client_tax_data', 'active', 'is_default'] as $casilla) {
            $datos[$casilla] = $request->boolean($casilla);
        }

        $datos['sort_order'] = (int) ($datos['sort_order'] ?? 0);
        $datos['code'] = strtoupper($datos['code']);

        return $datos;
    }
}
