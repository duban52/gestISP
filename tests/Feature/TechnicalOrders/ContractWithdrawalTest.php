<?php

namespace Tests\Feature\TechnicalOrders;

use App\Billing\Enums\ContractStatus;
use App\Billing\Services\InvoiceGenerator;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bajas de contrato: retiro, anulación y órdenes administrativas.
 *
 * LO QUE FALTABA
 * --------------
 * No había forma de decir que un cliente se fue. «Retirado» existía en
 * los datos heredados y en los informes, pero no en el enum ni en ningún
 * flujo: un contrato dado de baja seguía figurando y seguía
 * facturándose todos los meses.
 *
 * Y el cierre de órdenes comparaba LITERALES de texto contra una lista
 * escrita a mano, así que ni siquiera las que sí estaban contempladas
 * funcionaban del todo: «Instalación de servicio» con tilde no activaba
 * nada.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Cerrar un retiro deja el contrato en «Retirado».
 * 2. A un retirado o anulado NO se le genera factura, ni por la corrida
 *    ni desde la ficha del contrato.
 * 3. La orden administrativa cambia el estado dejando constancia, y
 *    exige permiso propio y motivo.
 * 4. El cierre de órdenes reconoce el detalle con tildes y sufijos.
 */
class ContractWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        // `permissions:sync` lo crea a partir del controlador; en
        // pruebas se declara a mano. Va al ROL, que es lo que mira el
        // middleware.
        Permission::firstOrCreate(
            ['name' => 'contracts.status', 'guard_name' => 'web'],
            ['description' => 'Cambiar el estado de contratos'],
        );
        $this->rol->givePermissionTo('contracts.status');

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    private function contrato(string $estado = 'Activo'): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => $estado,
            'user_id' => $this->admin->id,
        ]);
    }

    /** Una orden de campo lista para que el supervisor la cierre. */
    private function ordenPrefinalizada(Contract $contrato, string $detalle): TechnicalOrder
    {
        return TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'created_by' => $this->admin->id,
            'type' => TechnicalOrder::SERVICIO,
            'detail' => $detalle,
            'status' => 'Prefinalizada',
            'initial_comment' => 'Orden de prueba',
        ]);
    }

    private function cerrar(TechnicalOrder $orden)
    {
        return $this->put(route('technical_order.verification_process', $orden), [
            'verification_comment' => 'Verificada',
            'close_order' => '1',
        ]);
    }

    // ==================== 1. El retiro da de baja ====================

    public function test_cerrar_un_retiro_deja_el_contrato_retirado(): void
    {
        $contrato = $this->contrato();
        $orden = $this->ordenPrefinalizada($contrato, 'Retiro de servicio');

        $this->cerrar($orden)->assertRedirect();

        $this->assertSame(ContractStatus::Retirado->value, $contrato->fresh()->status);
    }

    // ==================== 2. Una baja no se factura ====================

    public function test_a_un_retirado_no_se_le_genera_factura(): void
    {
        $contrato = $this->contrato(ContractStatus::Retirado->value);

        $resultado = app(InvoiceGenerator::class)->generateForContract(
            $contrato,
            now(),
            $this->admin->id,
        );

        $this->assertFalse($resultado['generated']);
        $this->assertStringContainsString('not billable', $resultado['reason']);
    }

    public function test_a_un_anulado_tampoco(): void
    {
        $contrato = $this->contrato(ContractStatus::Anulado->value);

        $this->assertFalse(
            app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id)['generated'],
        );
    }

    public function test_la_corrida_mensual_los_deja_fuera(): void
    {
        // `billable()` es el filtro de la corrida; los finales no están.
        $this->assertNotContains(ContractStatus::Retirado->value, ContractStatus::billable());
        $this->assertNotContains(ContractStatus::Anulado->value, ContractStatus::billable());
    }

    public function test_un_cortado_del_vocabulario_viejo_tampoco_se_factura(): void
    {
        // «Cortado» es el nombre antiguo de Suspendido y sigue habiendo
        // filas con él. Antes solo se comprobaba «Suspendido», así que
        // un contrato cortado se facturaba según cómo estuviera escrito
        // su estado.
        $contrato = $this->contrato('Cortado');

        $this->assertFalse(
            app(InvoiceGenerator::class)->generateForContract($contrato, now(), $this->admin->id)['generated'],
        );
    }

    // ==================== 3. La orden administrativa ====================

    public function test_cambia_el_estado_y_deja_la_orden_cerrada(): void
    {
        $contrato = $this->contrato();

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Anulado->value,
            'initial_comment' => 'Nunca tomó el servicio.',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(ContractStatus::Anulado->value, $contrato->fresh()->status);

        $orden = TechnicalOrder::where('contract_id', $contrato->id)->firstOrFail();

        $this->assertSame(TechnicalOrder::ADMINISTRATIVA, $orden->type);
        $this->assertSame('Cerrada', $orden->status);
        // Sin técnico: no hay nadie a quien mandar.
        $this->assertNull($orden->user_assigned);
        // Y con su verificación, igual que las de campo.
        $this->assertCount(1, $orden->verifications);
    }

    public function test_admite_cualquier_estado_no_solo_las_bajas(): void
    {
        // Es lo que la deja abierta a futuras necesidades: corregir un
        // contrato mal puesto sin tocar código.
        $contrato = $this->contrato(ContractStatus::Suspendido->value);

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Activo->value,
            'initial_comment' => 'Estaba mal puesto en suspendido.',
        ])->assertRedirect();

        $this->assertSame(ContractStatus::Activo->value, $contrato->fresh()->status);
    }

    public function test_exige_motivo(): void
    {
        // Es lo único que explicará el cambio dentro de seis meses.
        $contrato = $this->contrato();

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Retirado->value,
        ])->assertSessionHasErrors('initial_comment');

        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_no_admite_un_estado_inventado(): void
    {
        $contrato = $this->contrato();

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => 'Jubilado',
            'initial_comment' => 'Motivo',
        ])->assertSessionHasErrors('target_contract_status');

        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_exige_su_propio_permiso(): void
    {
        // Puede forzar cualquier estado, incluida la baja: no es lo
        // mismo que crear una orden de campo.
        $contrato = $this->contrato();

        $this->rol->revokePermissionTo('contracts.status');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $contrato->id,
            'target_contract_status' => ContractStatus::Retirado->value,
            'initial_comment' => 'Motivo',
        ])->assertForbidden();

        $this->assertSame('Activo', $contrato->fresh()->status);
    }

    public function test_no_se_toca_un_contrato_de_otra_sucursal(): void
    {
        // La ruta acepta cualquier id: sin el corte bastaba con cambiar
        // el número para dar de baja el contrato de otra sede.
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $cliente = Client::factory()->create(['branch_id' => $otra->id, 'user_id' => $this->admin->id]);
        $ajeno = Contract::factory()->create([
            'branch_id' => $otra->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        $this->post(route('technicals_orders.administrative'), [
            'contract_id' => $ajeno->id,
            'target_contract_status' => ContractStatus::Retirado->value,
            'initial_comment' => 'Motivo',
        ])->assertStatus(403);

        $this->assertSame('Activo', $ajeno->fresh()->status);
    }

    // ==================== 4. El detalle, con tildes ====================

    public function test_la_instalacion_con_tilde_tambien_activa(): void
    {
        // EL DEFECTO LATENTE. La lista escrita a mano tenía
        // «Instalacion de servicio» sin tilde; con tilde, la orden se
        // cerraba y el contrato se quedaba en «Por Instalar».
        $contrato = $this->contrato('Por Instalar');
        $orden = $this->ordenPrefinalizada($contrato, 'Instalación de servicio');

        $this->cerrar($orden)->assertRedirect();

        $this->assertSame(ContractStatus::Activo->value, $contrato->fresh()->status);
    }

    public function test_una_averia_resuelta_no_reactiva_un_suspendido(): void
    {
        // Devolver «Activo» por defecto reactivaría contratos cortados
        // cada vez que se les resuelve una incidencia.
        $contrato = $this->contrato(ContractStatus::Suspendido->value);
        $orden = $this->ordenPrefinalizada($contrato, 'Sin servicio de internet');

        $this->cerrar($orden)->assertRedirect();

        $this->assertSame(ContractStatus::Suspendido->value, $contrato->fresh()->status);
    }
}
