<?php

namespace Tests\Feature\Contracts;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\AffinityGroup;
use App\Models\Plan;
use App\Models\User;
use App\Services\ContractQuery;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El grupo dentro del módulo de contratos.
 *
 * QUÉ CUBRE
 * ---------
 * Todo el recorrido del grupo por el módulo: cómo se asigna al dar de
 * alta, cómo se cambia después, cómo se filtra y cómo sale en el
 * listado y en el Excel.
 *
 * LOS FILTROS
 * -----------
 * Cómo se factura un contrato lo decide su GRUPO, así que para eso
 * está el filtro de grupo y no hace falta otro por modalidad: sería la
 * misma pregunta por otro camino, y dos filtros que dicen lo mismo se
 * acaban contradiciendo.
 *
 * Lo único que el filtro de grupo no puede responder es cuáles se
 * quedaron **sin** grupo, porque no hay ninguna opción que marcar para
 * eso. De ahí la casilla de clasificación: sin ella, un contrato sin
 * clasificar no se descubre hasta el día de facturar.
 */
class AffinityGroupAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;
    private Company $empresa;
    private Branch $sucursal;
    private Plan $plan;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $this->empresa->id,
            [$this->sucursal->id],
            $this->sucursal->id,
        );

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);
    }

    private function cliente(): Client
    {
        return Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function datos(Client $cliente, array $extra = []): array
    {
        return array_merge([
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'neighborhood' => 'Centro',
            'address' => 'Calle 20 # 19-30',
            'home_type' => 'Casa',
            'status' => 'Pendiente',
        ], $extra);
    }

    private function contrato(?AffinityGroup $grupo = null, array $extra = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'branch_id' => $this->sucursal->id,
            'client_id' => $this->cliente()->id,
            'plan_id' => $this->plan->id,
            'affinity_group_id' => $grupo?->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
        ], $extra));
    }

    // ==================== Al dar de alta ====================

    public function test_sin_elegir_grupo_se_asume_el_predeterminado(): void
    {
        // Preguntarlo cuando hay una sola respuesta posible sería un
        // campo de más en cada alta.
        $porDefecto = AffinityGroup::factory()->porDefecto()
            ->create(['company_id' => $this->empresa->id]);

        $cliente = $this->cliente();

        $this->post(route('contracts.store'), $this->datos($cliente))
            ->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertSame($porDefecto->id, (int) $contrato->affinity_group_id);
    }

    public function test_se_puede_elegir_otro_grupo(): void
    {
        AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);
        $corporativo = AffinityGroup::factory()->electronico()
            ->create(['company_id' => $this->empresa->id]);

        $cliente = $this->cliente();

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'affinity_group_id' => $corporativo->id,
        ]))->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertSame($corporativo->id, (int) $contrato->affinity_group_id);
        $this->assertTrue($contrato->affinityGroup->requires_electronic_invoicing);
    }

    public function test_no_vale_un_grupo_de_otra_empresa(): void
    {
        // La pantalla ya solo ofrece los suyos, pero lo que llega de un
        // formulario no es de fiar: el grupo decide si la factura se
        // reporta a la DIAN, y con el NIT de quién.
        $ajeno = AffinityGroup::factory()->create();

        $cliente = $this->cliente();

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'affinity_group_id' => $ajeno->id,
        ]))->assertSessionHasErrors('affinity_group_id');

        $this->assertSame(0, Contract::where('client_id', $cliente->id)->count());
    }

    public function test_no_vale_un_grupo_inactivo(): void
    {
        $inactivo = AffinityGroup::factory()->inactivo()
            ->create(['company_id' => $this->empresa->id]);

        $cliente = $this->cliente();

        $this->post(route('contracts.store'), $this->datos($cliente, [
            'affinity_group_id' => $inactivo->id,
        ]))->assertSessionHasErrors('affinity_group_id');
    }

    public function test_sin_grupo_predeterminado_el_alta_no_se_bloquea(): void
    {
        // Bloquear el alta por una configuración que falta sería peor
        // que el problema que evita. El contrato nace sin clasificar y
        // el listado tiene un filtro para encontrarlo después.
        $cliente = $this->cliente();

        $this->post(route('contracts.store'), $this->datos($cliente))
            ->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $cliente->id)->firstOrFail();

        $this->assertNull($contrato->affinity_group_id);
    }

    public function test_la_pantalla_de_alta_ofrece_los_grupos_activos(): void
    {
        AffinityGroup::factory()->porDefecto()
            ->create(['company_id' => $this->empresa->id, 'name' => 'General']);
        AffinityGroup::factory()->inactivo()
            ->create(['company_id' => $this->empresa->id, 'name' => 'Descatalogado']);
        AffinityGroup::factory()->create(['name' => 'De otra empresa']);

        $this->get(route('contracts.create', $this->cliente()))
            ->assertOk()
            ->assertSee('General', escape: false)
            ->assertDontSee('Descatalogado', escape: false)
            ->assertDontSee('De otra empresa', escape: false);
    }

    // ==================== Al cambiarlo después ====================

    public function test_se_puede_cambiar_el_grupo_desde_la_ficha(): void
    {
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);
        $corporativo = AffinityGroup::factory()->electronico()->create(['company_id' => $this->empresa->id]);

        $contrato = $this->contrato($general);

        $this->put(route('contracts.update', $contrato->id), [
            'plan_id' => $this->plan->id,
            'permanence_clause' => 12,
            'affinity_group_id' => $corporativo->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($corporativo->id, (int) $contrato->fresh()->affinity_group_id);
    }

    public function test_el_cambio_de_grupo_queda_en_la_trazabilidad(): void
    {
        // Es un requisito del análisis fiscal: si un contrato deja de
        // facturar electrónicamente, tiene que poder responderse quién
        // lo decidió y cuándo.
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);
        $corporativo = AffinityGroup::factory()->electronico()->create(['company_id' => $this->empresa->id]);

        $contrato = $this->contrato($general);

        $this->put(route('contracts.update', $contrato->id), [
            'plan_id' => $this->plan->id,
            'permanence_clause' => 12,
            'affinity_group_id' => $corporativo->id,
        ]);

        $registro = Audit::where('auditable_type', Contract::class)
            ->where('auditable_id', $contrato->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($registro, 'El cambio de grupo no quedó auditado.');
        $this->assertSame($general->id, (int) $registro->old_values['affinity_group_id']);
        $this->assertSame($corporativo->id, (int) $registro->new_values['affinity_group_id']);
    }

    public function test_no_se_puede_cambiar_a_un_grupo_de_otra_empresa(): void
    {
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);
        $ajeno = AffinityGroup::factory()->create();

        $contrato = $this->contrato($general);

        $this->put(route('contracts.update', $contrato->id), [
            'plan_id' => $this->plan->id,
            'affinity_group_id' => $ajeno->id,
        ])->assertSessionHasErrors('affinity_group_id');

        $this->assertSame($general->id, (int) $contrato->fresh()->affinity_group_id);
    }

    public function test_guardar_sin_tocar_el_grupo_no_lo_borra(): void
    {
        // Otros formularios de la ficha comparten la misma rama de
        // update() y no mandan el grupo. Sin la comprobación de
        // `has()`, le pondrían null y el contrato quedaría sin
        // clasificar sin que nadie lo pidiera.
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);

        $contrato = $this->contrato($general);

        $this->put(route('contracts.update', $contrato->id), [
            'plan_id' => $this->plan->id,
            'permanence_clause' => 6,
        ])->assertSessionHasNoErrors();

        $this->assertSame($general->id, (int) $contrato->fresh()->affinity_group_id);
    }

    public function test_un_grupo_desactivado_despues_sigue_valiendo_para_su_contrato(): void
    {
        // Guardar el modal sin tocar ese campo no puede fallar por algo
        // que se desactivó después de asignarlo.
        $viejo = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $contrato = $this->contrato($viejo);

        $viejo->update(['active' => false]);

        $this->put(route('contracts.update', $contrato->id), [
            'plan_id' => $this->plan->id,
            'affinity_group_id' => $viejo->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($viejo->id, (int) $contrato->fresh()->affinity_group_id);
    }

    // ==================== Los filtros ====================

    public function test_se_filtra_por_grupo_concreto(): void
    {
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);
        $corporativo = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $enGeneral = $this->contrato($general);
        $enCorporativo = $this->contrato($corporativo);

        $ids = app(ContractQuery::class)
            ->construir(['affinity_group_id' => [$corporativo->id]])
            ->pluck('contracts.id');

        $this->assertTrue($ids->contains($enCorporativo->id));
        $this->assertFalse($ids->contains($enGeneral->id));
    }

    public function test_se_encuentran_los_contratos_sin_clasificar(): void
    {
        // Lo unico que el filtro de grupo no puede responder: no hay
        // ninguna opcion que marcar para "los que no tienen ninguno".
        // Sin esta casilla no se descubren hasta el dia de facturar.
        $general = AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);

        $clasificado = $this->contrato($general);
        $huerfano = $this->contrato(null);

        $ids = app(ContractQuery::class)
            ->construir(['sin_grupo' => 'si'])
            ->pluck('contracts.id');

        $this->assertTrue($ids->contains($huerfano->id));
        $this->assertFalse($ids->contains($clasificado->id));
    }

    public function test_el_grupo_se_combina_con_los_demas_filtros(): void
    {
        $uno = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $otro = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);

        $activo = $this->contrato($uno, ['status' => 'Activo']);
        $suspendido = $this->contrato($uno, ['status' => 'Suspendido']);
        $delOtroGrupo = $this->contrato($otro, ['status' => 'Activo']);

        $ids = app(ContractQuery::class)
            ->construir([
                'affinity_group_id' => [$uno->id],
                'status' => ['Activo'],
            ])
            ->pluck('contracts.id');

        $this->assertTrue($ids->contains($activo->id));
        $this->assertFalse($ids->contains($suspendido->id));
        $this->assertFalse($ids->contains($delOtroGrupo->id));
    }

    public function test_el_listado_no_ofrece_un_filtro_de_modalidad(): void
    {
        // Como se factura lo decide el grupo. Un filtro aparte seria la
        // misma pregunta por otro camino.
        AffinityGroup::factory()->porDefecto()->create(['company_id' => $this->empresa->id]);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('name="facturacion"', escape: false);
    }

    public function test_el_listado_ofrece_el_filtro_de_grupo(): void
    {
        AffinityGroup::factory()->porDefecto()
            ->create(['company_id' => $this->empresa->id, 'name' => 'General']);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('name="affinity_group_id[]"', escape: false)
            ->assertSee('name="sin_grupo"', escape: false)
            ->assertSee('General', escape: false);
    }

    public function test_el_filtro_ofrece_tambien_los_inactivos(): void
    {
        // Hay contratos que siguen en un grupo que dejo de ofrecerse, y
        // hay que poder encontrarlos. Es un filtro de busqueda, no un
        // desplegable de alta.
        AffinityGroup::factory()->inactivo()
            ->create(['company_id' => $this->empresa->id, 'name' => 'Descatalogado']);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('Descatalogado', escape: false);
    }

    // ==================== La columna y el Excel ====================

    public function test_el_grupo_se_ofrece_como_columna_del_listado(): void
    {
        $columnas = ContractQuery::columnas();

        $this->assertArrayHasKey('affinity_group', $columnas);
        $this->assertArrayHasKey('facturacion', $columnas);
        // No vienen marcadas: mientras la empresa tenga un solo grupo
        // no aportan.
        $this->assertFalse($columnas['affinity_group']['defecto']);
        $this->assertFalse($columnas['facturacion']['defecto']);
    }

    public function test_el_valor_de_la_columna_dice_grupo_y_modalidad(): void
    {
        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $this->empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $contrato = $this->contrato($grupo)->fresh();

        $this->assertSame('CORP — Corporativo', ContractQuery::valor($contrato, 'affinity_group'));
        $this->assertSame('Electrónica', ContractQuery::valor($contrato, 'facturacion'));
    }

    public function test_un_contrato_sin_grupo_lo_dice_en_vez_de_salir_vacio(): void
    {
        // Una celda en blanco en el Excel se confunde con un error de
        // exportación, y un contrato sin clasificar es justo lo que hay
        // que poder detectar.
        $contrato = $this->contrato(null)->fresh();

        $this->assertSame('Sin grupo', ContractQuery::valor($contrato, 'facturacion'));
    }
}
