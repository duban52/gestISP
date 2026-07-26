<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\OntMetric;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trazabilidad de todo lo que ocurre en el sistema.
 *
 * Se comprueban las tres fuentes de registro (cambios de datos,
 * acciones sin cambio de datos y accesos), que los datos sensibles
 * nunca se guarden, que el ruido de los sondeos automáticos quede
 * fuera, y que la pantalla sea exclusiva del superadministrador.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Role $superRole;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->superRole = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create([
            'password' => bcrypt('clave-correcta'),
            'selected_branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole($this->superRole);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->superRole->id]);
    }

    private function comoAdmin(): self
    {
        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
        ]);

        return $this;
    }

    // ============ Cambios de datos (cualquier modelo) ============

    public function test_registra_la_creacion_de_cualquier_registro(): void
    {
        $this->comoAdmin();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'name' => 'Ana',
        ]);

        $auditoria = Audit::where('auditable_type', Client::class)
            ->where('auditable_id', $cliente->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($auditoria, 'La creación del cliente no quedó registrada.');
        $this->assertSame('clientes', $auditoria->category);
        $this->assertStringContainsString('Creó', $auditoria->description);
    }

    public function test_registra_que_cambio_con_el_valor_anterior_y_el_nuevo(): void
    {
        $this->comoAdmin();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'name' => 'Ana',
        ]);

        $cliente->update(['name' => 'Ana María']);

        $auditoria = Audit::where('auditable_id', $cliente->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($auditoria);
        $this->assertSame('Ana', $auditoria->old_values['name']);
        $this->assertSame('Ana María', $auditoria->new_values['name']);
    }

    public function test_registra_las_eliminaciones(): void
    {
        $this->comoAdmin();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        $id = $cliente->id;
        $cliente->delete();

        $this->assertDatabaseHas('audits', [
            'auditable_type' => Client::class,
            'auditable_id' => $id,
            'action' => 'deleted',
        ]);
    }

    public function test_guarda_el_contexto_de_quien_actuo(): void
    {
        $this->comoAdmin();

        Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $auditoria = Audit::where('action', 'created')->latest('id')->first();

        $this->assertSame($this->admin->id, $auditoria->user_id);
        $this->assertSame($this->branch->id, $auditoria->branch_id);
        $this->assertSame('superadministrador', $auditoria->role_name);
        $this->assertNotNull($auditoria->user_name);
    }

    // ==================== Datos sensibles ====================

    public function test_nunca_guarda_las_contrasenas(): void
    {
        $this->comoAdmin();

        $usuario = User::factory()->create(['password' => bcrypt('secreto-original')]);
        $usuario->update(['password' => bcrypt('secreto-nuevo')]);

        $registros = Audit::where('auditable_type', User::class)->get();

        $this->assertNotEmpty($registros);

        foreach ($registros as $registro) {
            $todos = json_encode([$registro->old_values, $registro->new_values, $registro->context]);

            $this->assertStringNotContainsString('secreto-original', $todos);
            $this->assertStringNotContainsString('secreto-nuevo', $todos);

            if (isset($registro->new_values['password'])) {
                $this->assertSame('********', $registro->new_values['password']);
            }
        }
    }

    // ============ Ruido de los sondeos automáticos ============

    public function test_no_registra_la_telemetria_de_los_sondeos(): void
    {
        $this->comoAdmin();

        $olt = $this->crearOlt();
        $ont = Ont::create([
            'olt_id' => $olt->id,
            'branch_id' => $this->branch->id,
            'sn' => 'HWTC-11112222',
            'slot' => 1, 'port' => 1, 'onu_id' => 1,
        ]);

        $antes = Audit::count();

        // Lo que hace onts:poll cada 5 minutos con miles de ONTs
        OntMetric::create([
            'ont_id' => $ont->id,
            'rx_power' => -21.5,
            'measured_at' => now(),
        ]);
        $ont->update(['rx_power' => -21.5, 'status' => 1]);

        $this->assertSame(
            $antes,
            Audit::count(),
            'La telemetría del sondeo no debe generar registros de auditoría.'
        );
    }

    public function test_si_cambia_algo_real_de_la_ont_si_lo_registra(): void
    {
        $this->comoAdmin();

        $olt = $this->crearOlt();
        $ont = Ont::create([
            'olt_id' => $olt->id,
            'branch_id' => $this->branch->id,
            'sn' => 'HWTC-33334444',
            'slot' => 1, 'port' => 1, 'onu_id' => 2,
        ]);

        $antes = Audit::where('action', 'updated')->count();

        // Deshabilitar una ONT sí es una decisión de una persona
        $ont->update(['admin_enabled' => false]);

        $this->assertSame($antes + 1, Audit::where('action', 'updated')->count());
    }

    // ============ Acciones sin cambio de datos ============

    public function test_registra_las_acciones_que_no_cambian_datos(): void
    {
        $this->comoAdmin();

        // Exportar no modifica nada, pero extrae información: para una
        // auditoría es de lo más relevante.
        $this->get(route('clients.export'));

        $registro = Audit::where('action', 'clients.export')->first();

        $this->assertNotNull($registro, 'La exportación no quedó registrada.');
        $this->assertSame('clientes', $registro->category);
        $this->assertStringContainsString('Exportó', $registro->description);
    }

    public function test_no_registra_los_sondeos_del_navegador(): void
    {
        $this->comoAdmin();

        $this->get(route('notifications.poll'));

        $this->assertSame(0, Audit::where('action', 'notifications.poll')->count());
    }

    // ==================== Accesos ====================

    public function test_registra_el_inicio_de_sesion(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'clave-correcta',
            'branch_id' => $this->branch->id,
        ]);

        $this->assertDatabaseHas('audits', [
            'action' => 'auth.login',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_registra_los_intentos_fallidos(): void
    {
        $this->post('/login', [
            'email' => $this->admin->email,
            'password' => 'clave-equivocada',
            'branch_id' => $this->branch->id,
        ]);

        $registro = Audit::where('action', 'auth.failed')->first();

        $this->assertNotNull($registro);
        $this->assertSame($this->admin->email, $registro->context['correo']);
    }

    // ==================== Acceso a la pantalla ====================

    public function test_solo_el_superadministrador_ve_la_trazabilidad(): void
    {
        $this->comoAdmin();

        $this->get(route('audits.index'))->assertOk();
    }

    public function test_otro_rol_no_puede_entrar_aunque_tenga_permisos(): void
    {
        $rol = Role::where('name', '!=', 'superadministrador')->firstOrFail();

        $usuario = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        // Aunque se le concedan TODOS los permisos del sistema
        $rol->syncPermissions(\Spatie\Permission\Models\Permission::all());

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('audits.index'))->assertForbidden();
    }

    public function test_el_detalle_muestra_lo_ocurrido_en_la_misma_operacion(): void
    {
        $this->comoAdmin();

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $registro = Audit::where('auditable_id', $cliente->id)->latest('id')->firstOrFail();

        $this->get(route('audits.show', $registro))
            ->assertOk()
            ->assertSee('Quién lo hizo');
    }

    private function crearOlt(): Olt
    {
        return Olt::create([
            'name' => 'OLT Auditoría',
            'ip_address' => '10.0.0.50',
            'branch_id' => $this->branch->id,
            'username' => 'admin',
            'password' => 'secreto',
            'ssh_port' => 22,
            'brand' => 'huawei',
            'model' => 'MA5608T',
            'uptime' => '0',
        ]);
    }
}
