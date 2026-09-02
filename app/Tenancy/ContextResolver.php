<?php

namespace App\Tenancy;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * A qué contextos puede entrar un usuario, y cómo se entra.
 *
 * UN CONTEXTO ES "EMPRESA + ALCANCE"
 * ----------------------------------
 * No basta con la sucursal. Un usuario puede tener acceso a varias
 * empresas, y dentro de cada una a varias sedes; y hay empresas que
 * trabajan todas sus sedes desde un panel único. Eso son tres
 * situaciones distintas que hay que poder representar:
 *
 *   Empresa A (independiente) → Norte        contexto 1
 *   Empresa A (independiente) → Sur          contexto 2
 *   Empresa B (consolidada)   → todas        contexto 3
 *
 * DE DÓNDE SALE LA PERTENENCIA
 * ----------------------------
 * De `user_branch`, que es donde se concede el acceso y el rol. NO hay
 * tabla usuario–empresa: la empresa se deduce de las sucursales. Una
 * tabla aparte sería una segunda verdad que puede divergir de la
 * primera, y entonces habría que decidir cuál manda.
 *
 * POR QUÉ SE CONSULTA SIN LA BARRERA DE EMPRESA
 * ---------------------------------------------
 * Porque aquí se está eligiendo la empresa: filtrar por la activa
 * escondería precisamente las otras. Es de los pocos sitios donde
 * saltarse el scope es lo correcto, y por eso se hace de forma
 * explícita y en un solo lugar.
 */
class ContextResolver
{
    public function __construct(
        private readonly CurrentContext $contexto,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Contextos a los que el usuario puede entrar.
     *
     * @return Collection<int, array{
     *     empresa: Company,
     *     sucursales: Collection<int, Branch>,
     *     consolidada: bool
     * }>
     */
    public function disponiblesPara(User $usuario): Collection
    {
        $sucursales = $usuario->branches()
            ->withoutGlobalScope('empresa')
            ->with('company')
            ->orderBy('branches.name')
            ->get();

        return $sucursales
            ->filter(fn (Branch $s) => $s->company !== null)
            ->groupBy('company_id')
            ->map(fn (Collection $delGrupo) => [
                'empresa' => $delGrupo->first()->company,
                'sucursales' => $delGrupo->values(),
                'consolidada' => $delGrupo->first()->company->esConsolidada(),
            ])
            ->sortBy(fn (array $c) => $c['empresa']->nombreVisible())
            ->values();
    }

    /**
     * El único contexto posible, o null si hay que preguntar.
     *
     * Se entra directo cuando no hay nada que elegir: una sola empresa
     * y, dentro de ella, o una sola sucursal o el modo consolidado
     * —que ya abarca todas—. Preguntar cuando no hay alternativa es
     * una pantalla de más en cada inicio de sesión.
     *
     * @return array{company_id: int, branch_id: int|null}|null
     */
    public function unicoPara(User $usuario): ?array
    {
        $disponibles = $this->disponiblesPara($usuario);

        if ($disponibles->count() !== 1) {
            return null;
        }

        $unico = $disponibles->first();

        if ($unico['consolidada']) {
            return ['company_id' => $unico['empresa']->id, 'branch_id' => null];
        }

        if ($unico['sucursales']->count() !== 1) {
            return null;
        }

        return [
            'company_id' => $unico['empresa']->id,
            'branch_id' => $unico['sucursales']->first()->id,
        ];
    }

    /**
     * Entra en un contexto: lo valida, lo guarda y lo activa.
     *
     * LA VALIDACIÓN NO ES OPCIONAL
     * ----------------------------
     * La empresa y la sucursal llegan de un formulario, así que se
     * comprueban contra lo que el usuario tiene concedido de verdad.
     * Que la pantalla solo ofrezca sus opciones no significa nada: la
     * petición se puede escribir a mano.
     *
     * @param  int|null  $branchId  null pide el panel consolidado
     *
     * @throws RuntimeException si el usuario no tiene ese acceso
     */
    public function aplicar(User $usuario, int $companyId, ?int $branchId): void
    {
        $disponible = $this->disponiblesPara($usuario)
            ->firstWhere('empresa.id', $companyId);

        if (!$disponible) {
            throw new RuntimeException('No tiene acceso a esa empresa.');
        }

        $sucursales = $disponible['sucursales'];

        if ($branchId === null) {
            // El panel consolidado solo existe si la empresa lo tiene
            // activado. Sin esto, cualquiera podría pedirlo omitiendo
            // la sucursal en la petición.
            if (!$disponible['consolidada']) {
                throw new RuntimeException('Esta empresa no trabaja en panel consolidado.');
            }

            $this->establecer($usuario, $companyId, $sucursales->pluck('id')->all(), null);

            return;
        }

        $elegida = $sucursales->firstWhere('id', $branchId);

        if (!$elegida) {
            throw new RuntimeException('No tiene acceso a esa sucursal.');
        }

        $this->establecer($usuario, $companyId, [$elegida->id], $elegida->id);
    }

    /**
     * Escribe el contexto en la sesión y lo activa.
     *
     * El rol sale de user_branch y NUNCA de la petición: es lo que
     * decide qué puede hacer el usuario, y aceptarlo de fuera sería
     * dejar que se lo eligiera él.
     *
     * @param  array<int, int>  $branchIds
     */
    private function establecer(User $usuario, int $companyId, array $branchIds, ?int $branchId): void
    {
        $rolId = $branchId !== null
            ? $this->rolEnLaSucursal($usuario, $branchId)
            : $this->rolMasRestringido($usuario, $branchIds);

        session([
            'company_id' => $companyId,
            'branch_id' => $branchId ? (string) $branchId : null,
            'branch_ids' => $branchIds,
            'current_role_id' => $rolId ? (string) $rolId : null,
        ]);

        // La última sucursal elegida se recuerda para la próxima vez.
        // En consolidado no se toca: no hay una sola que recordar.
        if ($branchId) {
            $usuario->update(['selected_branch_id' => $branchId]);
        }

        $this->contexto->establecer($companyId, $branchIds, $branchId);
    }

    /** El rol que el usuario tiene en una sucursal concreta. */
    private function rolEnLaSucursal(User $usuario, int $branchId): ?int
    {
        return $usuario->branches()
            ->withoutGlobalScope('empresa')
            ->where('branches.id', $branchId)
            ->first()?->pivot?->role_id;
    }

    /**
     * Con qué rol se trabaja en panel consolidado.
     *
     * EL PROBLEMA
     * -----------
     * Un usuario puede tener un rol distinto en cada sucursal: eso es
     * justo lo que guarda user_branch.role_id. En modo independiente no
     * hay duda —manda el de la sucursal en la que entró—, pero el panel
     * consolidado abarca varias a la vez y hay que elegir uno solo,
     * porque toda la autorización se resuelve contra
     * session('current_role_id').
     *
     * Antes se tomaba el de la PRIMERA sucursal de la lista. Eso no era
     * una simplificación inocente: quien fuera administrador en la sede
     * A y solo consulta en la B pasaba a administrar TAMBIÉN la B en
     * cuanto entraba en consolidado. Una escalada de privilegios que
     * dependía del orden de una lista.
     *
     * LA REGLA
     * --------
     * Se trabaja con el MENOS privilegiado de sus roles. Consolidado
     * amplía lo que se ve, nunca lo que se puede hacer: si en alguna de
     * las sedes que está mirando solo puede consultar, consulta en
     * todas. Para actuar con más permisos, entra en esa sucursal
     * concreta — que es donde de verdad los tiene.
     *
     * El privilegio se mide por número de permisos. Es una
     * aproximación, pero en este sistema los roles son acumulativos
     * (técnico ⊂ auxiliar ⊂ administrador ⊂ superadministrador), así
     * que ordena bien. Con empate se toma el de id menor, para que la
     * respuesta no dependa del orden en que vengan.
     *
     * El usuario ve con qué rol está trabajando en el menú superior
     * (User::adminlte_desc), así que no se le aplica en silencio.
     *
     * @param  array<int, int>  $branchIds
     */
    private function rolMasRestringido(User $usuario, array $branchIds): ?int
    {
        $rolIds = $usuario->branches()
            ->withoutGlobalScope('empresa')
            ->whereIn('branches.id', $branchIds)
            ->get()
            ->pluck('pivot.role_id')
            ->filter()
            ->unique()
            ->values();

        // Lo normal: el mismo rol en todas. No hay nada que decidir.
        if ($rolIds->count() <= 1) {
            return $rolIds->first();
        }

        return Role::whereIn('id', $rolIds)
            ->withCount('permissions')
            ->orderBy('permissions_count')
            ->orderBy('id')
            ->first()?->id;
    }

    /**
     * Deja constancia del cambio de contexto.
     *
     * Responde "¿desde qué empresa hizo esto?" cuando alguien con
     * acceso a varias revisa una acción meses después.
     */
    public function auditarCambio(Company $empresa, ?Branch $sucursal): void
    {
        $this->auditLogger->action(
            'context.switched',
            sprintf(
                'Cambió al contexto %s / %s',
                $empresa->nombreVisible(),
                $sucursal?->name ?? 'todas las sucursales',
            ),
            [
                'empresa' => $empresa->nombreVisible(),
                'empresa_id' => $empresa->id,
                'sucursal' => $sucursal?->name,
                'sucursal_id' => $sucursal?->id,
                'consolidado' => $sucursal === null,
            ],
            null,
            'auth',
        );
    }
}
