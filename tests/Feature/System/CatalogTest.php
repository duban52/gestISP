<?php

namespace Tests\Feature\System;

use App\Billing\Enums\ContractStatus;
use App\Models\Branch;
use App\Models\ContractStatusOption;
use App\Models\TechnicalOrderDetail;
use App\Models\TechnicalOrderType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El catálogo de estados de contrato y tipos de orden.
 *
 * LO QUE SE DEFIENDE AQUÍ
 * -----------------------
 * 1. Que lo que ya existía sigue existiendo y significando lo mismo.
 *    Antes vivía en un enum y en dos constantes; si al pasarlo a tabla
 *    cambiara una sola respuesta, cambiaría a quién se le factura.
 *
 * 2. Que lo del sistema no se puede renombrar ni borrar. El código
 *    nombra «Activo» y «corte de servicio» por su valor.
 *
 * 3. Que está reservado al superadministrador. Lo que se toca aquí
 *    decide a quién se le cobra.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    /** Un contrato con su cliente y su plan, que es lo que exige la base. */
    private function contrato(array $extra = []): \App\Models\Contract
    {
        $usuario = auth()->user() ?? User::factory()->create();

        $cliente = \App\Models\Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $usuario->id,
        ]);

        $plan = \App\Models\Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $usuario->id,
        ]);

        return \App\Models\Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $usuario->id,
        ], $extra));
    }

    private function entrarComo(string $rol): User
    {
        $role = Role::where('name', $rol)->firstOrFail();

        $usuario = User::factory()->create();
        $usuario->assignRole($role);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        return $usuario;
    }

    // ==================== Lo que ya existía ====================

    public function test_el_catalogo_trae_los_estados_que_ya_existian(): void
    {
        foreach (ContractStatus::cases() as $estado) {
            $this->assertNotNull(
                ContractStatusOption::porNombre($estado->value),
                "Falta el estado {$estado->value} en el catálogo.",
            );
        }

        // Y el nombre viejo, que sigue habiendo contratos con él.
        $this->assertNotNull(ContractStatusOption::porNombre('Cortado'));
    }

    public function test_las_listas_del_enum_siguen_diciendo_lo_mismo(): void
    {
        // Son las que deciden a quién factura la corrida mensual y a
        // quién no se le factura nunca.
        $this->assertEqualsCanonicalizing(
            ['Activo', 'Pre-suspensión'],
            ContractStatus::billable(),
        );

        $this->assertEqualsCanonicalizing(
            ['Suspendido', 'Cortado', 'Retirado', 'Anulado'],
            ContractStatus::noFacturables(),
        );

        $this->assertEqualsCanonicalizing(['Retirado', 'Anulado'], ContractStatus::finales());
    }

    public function test_el_catalogo_trae_los_detalles_de_orden_con_su_efecto(): void
    {
        $corte = TechnicalOrderDetail::paraDetalle('Corte de servicio');

        $this->assertSame('Suspendido', $corte->target_contract_status);
        $this->assertSame(TechnicalOrderDetail::DESHABILITAR, $corte->pppoe_action);
        $this->assertSame(TechnicalOrderDetail::DESHABILITAR, $corte->ont_action);

        $reconexion = TechnicalOrderDetail::paraDetalle('Reconexión');

        $this->assertSame('Activo', $reconexion->target_contract_status);
        $this->assertSame(TechnicalOrderDetail::HABILITAR, $reconexion->pppoe_action);
        $this->assertSame(TechnicalOrderDetail::HABILITAR, $reconexion->ont_action);
    }

    public function test_el_detalle_se_encuentra_escrito_de_cualquier_forma(): void
    {
        // En la base conviven las tres formas: sin tilde, con ella y
        // con el sufijo que pone la creación automática.
        foreach ([
            'Instalacion de servicio',
            'Instalación de servicio',
            'Instalación de servicio (creación automática)',
        ] as $escrito) {
            $this->assertSame(
                'instalacion de servicio',
                TechnicalOrderDetail::paraDetalle($escrito)?->key,
                "No se reconoció «{$escrito}».",
            );
        }
    }

    // ==================== Quién entra ====================

    public function test_solo_el_superadministrador_entra(): void
    {
        $this->entrarComo('administrador');

        $this->get(route('system.catalog'))->assertForbidden();
    }

    public function test_el_superadministrador_ve_el_catalogo(): void
    {
        $this->entrarComo('superadministrador');

        $this->get(route('system.catalog'))
            ->assertOk()
            ->assertSee('Estados de contrato')
            ->assertSee('Activo')
            ->assertSee('Corte de servicio');
    }

    // ==================== Crear y modificar ====================

    public function test_se_crea_un_estado_nuevo_y_queda_disponible(): void
    {
        // El caso del usuario: exonerado tiene servicio pero no se le
        // cobra.
        $this->entrarComo('superadministrador');

        $this->post(route('system.status.store'), [
            'name' => 'Exonerado',
            'description' => 'Tiene servicio pero no se le cobra.',
            'has_service' => 1,
            'active' => 1,
            'color' => 'info',
        ])->assertSessionHasNoErrors();

        $estado = ContractStatusOption::porNombre('Exonerado');

        $this->assertNotNull($estado);
        $this->assertFalse($estado->bills);
        $this->assertFalse($estado->auto_bills);
        $this->assertTrue($estado->has_service);

        // Y lo que importa: la facturación se entera.
        $this->assertContains('Exonerado', ContractStatus::noFacturables());
        $this->assertFalse(ContractStatus::facturable('Exonerado'));
        $this->assertNotContains('Exonerado', ContractStatus::billable());
    }

    public function test_un_estado_del_sistema_no_se_renombra(): void
    {
        $this->entrarComo('superadministrador');

        $activo = ContractStatusOption::porNombre('Activo');

        $this->put(route('system.status.update', $activo), [
            'name' => 'Activado',
            'description' => 'Otra descripción',
            'bills' => 1,
            'auto_bills' => 1,
            'has_service' => 1,
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $activo->refresh();

        // El nombre se ignora; el resto sí se guarda.
        $this->assertSame('Activo', $activo->name);
        $this->assertSame('Otra descripción', $activo->description);
    }

    public function test_un_estado_del_sistema_no_se_borra(): void
    {
        $this->entrarComo('superadministrador');

        $this->delete(route('system.status.destroy', ContractStatusOption::porNombre('Activo')))
            ->assertSessionHas('error');

        $this->assertNotNull(ContractStatusOption::porNombre('Activo'));
    }

    public function test_un_estado_con_contratos_dentro_no_se_borra(): void
    {
        $this->entrarComo('superadministrador');

        $estado = ContractStatusOption::create([
            'name' => 'Exonerado',
            'bills' => false,
            'has_service' => true,
            'active' => true,
        ]);

        $this->contrato(['status' => 'Exonerado']);

        $this->delete(route('system.status.destroy', $estado))->assertSessionHas('error');

        $this->assertNotNull(ContractStatusOption::porNombre('Exonerado'));
    }

    public function test_se_crea_un_detalle_de_orden_con_su_efecto(): void
    {
        $this->entrarComo('superadministrador');

        $servicio = TechnicalOrderType::where('name', 'Servicio')->firstOrFail();

        $this->post(route('system.order_detail.store'), [
            'technical_order_type_id' => $servicio->id,
            'name' => 'Corte por fraude',
            'target_contract_status' => 'Suspendido',
            'pppoe_action' => TechnicalOrderDetail::DESHABILITAR,
            'ont_action' => TechnicalOrderDetail::DESHABILITAR,
            'active' => 1,
        ])->assertSessionHasNoErrors();

        $detalle = TechnicalOrderDetail::paraDetalle('Corte por fraude');

        $this->assertNotNull($detalle);
        // La clave se deriva del nombre: es con la que se agrupan los
        // informes y con la que el cierre lo encuentra.
        $this->assertSame('corte por fraude', $detalle->key);
        $this->assertTrue($detalle->tocaEquipos());
    }

    public function test_el_detalle_nuevo_aparece_al_crear_una_orden(): void
    {
        $this->entrarComo('superadministrador');

        $servicio = TechnicalOrderType::where('name', 'Servicio')->firstOrFail();

        TechnicalOrderDetail::create([
            'technical_order_type_id' => $servicio->id,
            'name' => 'Corte por fraude',
            'key' => 'corte por fraude',
            'target_contract_status' => 'Suspendido',
            'pppoe_action' => TechnicalOrderDetail::DESHABILITAR,
            'ont_action' => TechnicalOrderDetail::DESHABILITAR,
            'active' => true,
        ]);

        $contrato = $this->contrato();

        $this->get(route('technicals_orders.create', $contrato))
            ->assertOk()
            ->assertSee('Corte por fraude');
    }

    public function test_un_detalle_desactivado_deja_de_ofrecerse(): void
    {
        $this->entrarComo('superadministrador');

        TechnicalOrderDetail::paraDetalle('Traslado de servicio')->update(['active' => false]);

        $contrato = $this->contrato();

        $this->get(route('technicals_orders.create', $contrato))
            ->assertOk()
            ->assertDontSee('Traslado de servicio');
    }
}
