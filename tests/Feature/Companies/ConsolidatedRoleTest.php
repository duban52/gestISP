<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\ContextResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Con qué rol se trabaja en panel consolidado.
 *
 * LA ESCALADA QUE CIERRA
 * ----------------------
 * Un usuario puede tener un rol distinto en cada sucursal: es lo que
 * guarda `user_branch.role_id`. En modo independiente no hay duda —
 * manda el de la sucursal en la que entró. El panel consolidado abarca
 * varias a la vez y hay que quedarse con uno solo, porque toda la
 * autorización se resuelve contra `session('current_role_id')`.
 *
 * Se tomaba el de la **primera sucursal de la lista**. Eso significaba
 * que quien fuera administrador en la sede A y solo consulta en la B
 * pasaba a administrar TAMBIÉN la B en cuanto entraba en consolidado.
 * Una escalada de privilegios que dependía del orden de una lista.
 *
 * LA REGLA
 * --------
 * Se trabaja con el **menos privilegiado** de sus roles. Consolidado
 * amplía lo que se ve, nunca lo que se puede hacer. Para actuar con
 * más permisos hay que entrar en la sucursal concreta donde se tienen.
 */
class ConsolidatedRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);
    }

    private function rol(string $nombre): Role
    {
        return Role::where('name', $nombre)->firstOrFail();
    }

    /**
     * Un usuario con un rol por sucursal.
     *
     * @param  array<int, array{0: Branch, 1: Role}>  $asignaciones
     */
    private function usuarioCon(array $asignaciones): User
    {
        $usuario = User::factory()->create();

        foreach ($asignaciones as [$sucursal, $rol]) {
            $usuario->branches()->attach($sucursal->id, ['role_id' => $rol->id]);
            $usuario->assignRole($rol);
        }

        return $usuario;
    }

    // ==================== Lo normal: el mismo rol ====================

    public function test_con_el_mismo_rol_en_todas_no_hay_nada_que_decidir(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $admin = $this->rol('administrador');
        $usuario = $this->usuarioCon([[$norte, $admin], [$sur, $admin]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);

        $this->assertSame((string) $admin->id, session('current_role_id'));
    }

    // ==================== Roles distintos: manda el menor ====================

    public function test_con_roles_distintos_manda_el_menos_privilegiado(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $tecnico = $this->rol('tecnico');

        // Superadministrador en la PRIMERA sucursal: con la regla
        // anterior se habría quedado con ese rol en las dos.
        $usuario = $this->usuarioCon([[$norte, $super], [$sur, $tecnico]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);

        $this->assertSame((string) $tecnico->id, session('current_role_id'));
    }

    public function test_el_orden_de_las_sucursales_no_cambia_el_resultado(): void
    {
        // La regla anterior dependía del orden de la lista. Esta no.
        $empresa = Company::factory()->consolidada()->create();
        $primera = Branch::factory()->create(['company_id' => $empresa->id]);
        $segunda = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $tecnico = $this->rol('tecnico');

        // El rol bajo va ahora en la primera sucursal.
        $usuario = $this->usuarioCon([[$primera, $tecnico], [$segunda, $super]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);

        $this->assertSame((string) $tecnico->id, session('current_role_id'));
    }

    public function test_con_tres_sedes_se_queda_con_el_mas_bajo_de_los_tres(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $a = Branch::factory()->create(['company_id' => $empresa->id]);
        $b = Branch::factory()->create(['company_id' => $empresa->id]);
        $c = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $admin = $this->rol('administrador');
        $tecnico = $this->rol('tecnico');

        $usuario = $this->usuarioCon([[$a, $super], [$b, $admin], [$c, $tecnico]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);

        $this->assertSame((string) $tecnico->id, session('current_role_id'));
    }

    // ==================== En una sucursal manda la suya ====================

    public function test_al_entrar_en_una_sucursal_manda_el_rol_de_esa(): void
    {
        // El recorte es SOLO del panel consolidado. Entrando en la sede
        // donde de verdad es superadministrador, lo es.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $tecnico = $this->rol('tecnico');

        $usuario = $this->usuarioCon([[$norte, $super], [$sur, $tecnico]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, $norte->id);

        $this->assertSame((string) $super->id, session('current_role_id'));
    }

    public function test_en_la_otra_sucursal_manda_el_rol_bajo(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $tecnico = $this->rol('tecnico');

        $usuario = $this->usuarioCon([[$norte, $super], [$sur, $tecnico]]);

        app(ContextResolver::class)->aplicar($usuario, $empresa->id, $sur->id);

        $this->assertSame((string) $tecnico->id, session('current_role_id'));
    }

    // ==================== El efecto real sobre los permisos ====================

    public function test_en_consolidado_no_puede_lo_que_solo_puede_en_una_sede(): void
    {
        // La prueba que de verdad importa: que el recorte del rol se
        // traduzca en permisos recortados, no solo en un id distinto
        // guardado en la sesión.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $super = $this->rol('superadministrador');
        $tecnico = $this->rol('tecnico');

        $usuario = $this->usuarioCon([[$norte, $super], [$sur, $tecnico]]);

        // Entrando en la sede donde es superadministrador: puede crear planes.
        app(ContextResolver::class)->aplicar($usuario, $empresa->id, $norte->id);
        $this->actingAs($usuario)->get(route('plans.create'))->assertOk();

        // En consolidado, con el rol de técnico: no puede.
        app(ContextResolver::class)->aplicar($usuario, $empresa->id, null);
        $this->actingAs($usuario)->get(route('plans.create'))->assertForbidden();
    }
}
