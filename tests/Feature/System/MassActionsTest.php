<?php

namespace Tests\Feature\System;

use App\Billing\Enums\ContractStatus;
use App\MassActions\Enums\MassActionItemStatus;
use App\MassActions\Enums\MassActionStatus;
use App\MassActions\Enums\MassActionType;
use App\MassActions\MassActionRecorder;
use App\MassActions\MassActionReverter;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MassAction;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Acciones masivas: historial y reversión segura.
 *
 * LO QUE SE DEFIENDE AQUÍ
 * -----------------------
 * 1. Que la reversión NO sobrescriba lo que alguien cambió después.
 *    Es la razón de ser de todo esto: un corte que se revierte no
 *    puede resucitar un contrato que mientras tanto se retiró.
 * 2. Que no se revierta dos veces, ni recargando ni escribiendo la URL.
 * 3. Que solo entre el superadministrador, y no por esconder el menú.
 * 4. Que la reversión quede registrada como otra acción, enlazada con
 *    la original en los dos sentidos.
 */
class MassActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Branch $branch;
    private Contract $contrato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();

        $rol = Role::where('name', 'superadministrador')->firstOrFail();
        $this->superadmin = User::factory()->create(['number_phone' => '3000000001']);
        $this->superadmin->assignRole($rol);
        $this->superadmin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'number_phone' => '3111111111',
            'aditional_phone' => '3111111112',
            'user_id' => $this->superadmin->id,
        ]);

        $plan = Plan::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->superadmin->id,
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->superadmin->id,
            'status' => ContractStatus::Suspendido->value,
        ]);

        $this->comoSuperadmin();
    }

    private function comoSuperadmin(): void
    {
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->actingAs($this->superadmin)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);
    }

    /** Una acción de corte ya ejecutada, con su ítem. */
    private function accionDeCorte(string $antes = 'Activo', string $despues = 'Suspendido'): MassAction
    {
        $recorder = app(MassActionRecorder::class);

        $accion = $recorder->abrir(
            MassActionType::CorteDeContratos,
            'Corte masivo de prueba',
            branchId: $this->branch->id,
            userId: $this->superadmin->id,
        );

        $recorder->registrar(
            $accion,
            $this->contrato,
            $this->contrato->numero_visible,
            antes: ['status' => $antes],
            despues: ['status' => $despues],
        );

        $recorder->cerrar($accion);

        return $accion->refresh();
    }

    // ==================== El registro ====================

    public function test_una_accion_masiva_queda_registrada_con_sus_contadores(): void
    {
        $accion = $this->accionDeCorte();

        $this->assertSame(MassActionStatus::Completada, $accion->status);
        $this->assertSame(1, $accion->total_items);
        $this->assertSame(1, $accion->ok_items);
        $this->assertNotNull($accion->started_at);
        $this->assertNotNull($accion->finished_at);
    }

    public function test_el_item_guarda_el_antes_y_el_despues(): void
    {
        // Solo los campos que cambiaron: con cincuenta mil ítems,
        // copiar el contrato entero en cada uno no cabe.
        $item = $this->accionDeCorte()->items()->firstOrFail();

        $this->assertSame(['status' => 'Activo'], $item->before);
        $this->assertSame(['status' => 'Suspendido'], $item->after);
    }

    // ==================== La reversión segura ====================

    public function test_revertir_devuelve_el_contrato_a_su_estado_anterior(): void
    {
        $accion = $this->accionDeCorte();

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame(ContractStatus::Activo->value, $this->contrato->fresh()->status);
        $this->assertSame(1, $reversion->reverted_items);
        $this->assertSame(MassActionStatus::Revertida, $accion->fresh()->status);
    }

    /**
     * LA PRUEBA QUE JUSTIFICA TODO ESTO.
     *
     * El corte dejó el contrato Suspendido. Después alguien lo retiró.
     * Revertir NO puede devolverlo a Activo: sería deshacer, con una
     * decisión de hace tres días, lo que alguien decidió hoy.
     */
    public function test_no_revierte_lo_que_alguien_cambio_despues(): void
    {
        $accion = $this->accionDeCorte();

        $this->contrato->update(['status' => ContractStatus::Retirado->value]);

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame(ContractStatus::Retirado->value, $this->contrato->fresh()->status);
        $this->assertSame(0, $reversion->reverted_items);
        $this->assertSame(1, $reversion->conflict_items);
        $this->assertSame(MassActionStatus::ReversionParcial, $accion->fresh()->status);
    }

    public function test_el_conflicto_explica_el_motivo(): void
    {
        // «Conflicto» a secas no le sirve a nadie: tiene que decir qué
        // se esperaba y qué se encontró.
        $accion = $this->accionDeCorte();
        $this->contrato->update(['status' => ContractStatus::Retirado->value]);

        app(MassActionReverter::class)->revertir($accion);

        $motivo = $accion->items()->firstOrFail()->fresh()->conflict_reason;

        $this->assertStringContainsString('Suspendido', $motivo);
        $this->assertStringContainsString('Retirado', $motivo);
    }

    public function test_revisar_no_toca_nada(): void
    {
        // Es lo que alimenta la pantalla de confirmación: tiene que
        // poder consultarse sin consecuencias.
        $accion = $this->accionDeCorte();

        $revision = app(MassActionReverter::class)->revisar($accion);

        $this->assertSame(1, $revision['reversibles']);
        $this->assertSame(0, $revision['conflictos']);
        $this->assertSame(ContractStatus::Suspendido->value, $this->contrato->fresh()->status);
    }

    // ==================== Doble reversión ====================

    public function test_una_accion_no_se_revierte_dos_veces(): void
    {
        $accion = $this->accionDeCorte();

        app(MassActionReverter::class)->revertir($accion);

        $this->expectException(\RuntimeException::class);

        app(MassActionReverter::class)->revertir($accion->fresh());
    }

    public function test_el_item_revertido_no_se_vuelve_a_tocar(): void
    {
        // El candado de la idempotencia: un trabajo que se reintenta
        // no puede revertir dos veces el mismo registro.
        $accion = $this->accionDeCorte();

        app(MassActionReverter::class)->revertir($accion);

        $item = $accion->items()->firstOrFail()->fresh();

        $this->assertNotNull($item->reverted_at);
        $this->assertSame(MassActionItemStatus::Revertido, $item->status);
        $this->assertFalse($item->pendienteDeRevertir());
    }

    public function test_la_reversion_queda_enlazada_con_la_original(): void
    {
        $accion = $this->accionDeCorte();

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame($accion->id, $reversion->reverses_mass_action_id);
        $this->assertSame($reversion->id, $accion->fresh()->reverted_by_mass_action_id);
        $this->assertSame(MassActionType::Reversion, $reversion->type);
    }

    // ==================== Seguridad ====================

    public function test_el_superadministrador_ve_el_historial(): void
    {
        $this->accionDeCorte();

        $this->get(route('mass_actions.index'))
            ->assertOk()
            ->assertSee('Corte masivo de prueba');
    }

    public function test_un_administrador_no_entra_ni_escribiendo_la_url(): void
    {
        // No basta con esconder el menú: quien conozca la URL tiene
        // que chocar igual.
        $rol = Role::where('name', 'administrador')->firstOrFail();

        $otro = User::factory()->create(['number_phone' => '3000000002']);
        $otro->assignRole($rol);
        $otro->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($otro)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->get(route('mass_actions.index'))->assertForbidden();
    }

    public function test_un_administrador_no_puede_revertir_por_la_url(): void
    {
        $accion = $this->accionDeCorte();

        $rol = Role::where('name', 'administrador')->firstOrFail();
        $otro = User::factory()->create(['number_phone' => '3000000003']);
        $otro->assignRole($rol);
        $otro->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($otro)->withSession([
            'branch_id' => $this->branch->id,
            'current_role_id' => $rol->id,
        ]);

        $this->post(route('mass_actions.revert', $accion), ['confirmacion' => 'REVERTIR'])
            ->assertForbidden();

        $this->assertSame(ContractStatus::Suspendido->value, $this->contrato->fresh()->status);
    }

    // ==================== La confirmación ====================

    public function test_sin_escribir_la_palabra_no_se_revierte(): void
    {
        $accion = $this->accionDeCorte();

        $this->post(route('mass_actions.revert', $accion), ['confirmacion' => 'si'])
            ->assertSessionHasErrors('confirmacion');

        $this->assertSame(ContractStatus::Suspendido->value, $this->contrato->fresh()->status);
    }

    public function test_la_pantalla_de_confirmacion_avisa_de_los_conflictos(): void
    {
        $accion = $this->accionDeCorte();
        $this->contrato->update(['status' => ContractStatus::Retirado->value]);

        $this->get(route('mass_actions.confirm', $accion))
            ->assertOk()
            ->assertSee('en conflicto');
    }
    // ==================== El detalle ====================

    /**
     * La vista individual se abre para TODOS los tipos.
     *
     * En producción daba un 500 y nadie sabía por qué: la culpa no
     * era de esta pantalla sino de un `User::can()` propio que
     * resolvía las habilidades de política con `hasPermissionTo()`,
     * y ese método LANZA cuando el nombre no es una fila de la tabla
     * `permissions` —que es justo lo que nunca es «revert»—.
     *
     * Se recorren todos los tipos porque la línea que reventaba está
     * detrás de `sePuedeRevertir()`: con un tipo no reversible la
     * pantalla abría igual y el fallo pasaba desapercibido.
     *
     * @dataProvider todosLosTipos
     */
    public function test_el_detalle_se_abre_para_cualquier_tipo(string $tipo): void
    {
        $recorder = app(MassActionRecorder::class);

        $accion = $recorder->abrir(
            MassActionType::from($tipo),
            'Prueba del detalle',
            branchId: $this->branch->id,
            userId: $this->superadmin->id,
        );

        $recorder->registrar($accion, null, 'REGISTRO-1',
            antes: ['status' => 'Activo'],
            despues: ['status' => 'Suspendido'],
        );

        $recorder->cerrar($accion);

        $this->get(route('mass_actions.show', $accion))
            ->assertOk()
            ->assertSee('REGISTRO-1', false);
    }

    public static function todosLosTipos(): array
    {
        return collect(MassActionType::cases())
            ->mapWithKeys(fn ($tipo) => [$tipo->value => [$tipo->value]])
            ->all();
    }

}
