<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lo que un contrato no puede hacer por la puerta de atrás.
 *
 * CUATRO AGUJEROS, TODOS EN EL MISMO CONTROLADOR
 * ----------------------------------------------
 *  1. **El alta aceptaba cualquier campo.** Guardaba todo lo que
 *     llegara en la petición salvo las coordenadas, y el `$fillable`
 *     del contrato incluye el estado, el número, el descuento y la
 *     fecha de inicio de facturación. Con un POST manipulado nacía un
 *     contrato «Activo» sin instalar, o con un descuento del 500 %
 *     que la acción de descuentos rechaza porque deja la factura en
 *     negativo.
 *
 *  2. **La orden de instalación iba fuera de la transacción.** Si
 *     fallaba, el contrato quedaba creado y numerado pero sin orden:
 *     nadie lo tenía en su lista y el cliente esperaba a un técnico
 *     que no sabía que existía.
 *
 *  3. **Un formulario borraba los datos de otro.** Los tres modales de
 *     la ficha comparten una acción que decidía cuál era con
 *     `isset()`, falso cuando el campo llega vacío. Guardar «Datos del
 *     servicio» con el plan en blanco caía en la rama de datos
 *     técnicos y ponía en NULO el serial, la cuenta PPPoE y el wifi.
 *
 *  4. **El cambio de plan no validaba el plan.** Vacío dejaba el
 *     contrato sin plan —y sin plan no factura—; inexistente era un
 *     error 500 de clave foránea.
 */
class ContractIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private Branch $sucursal;
    private User $usuario;
    private Client $cliente;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($rol);
        $this->usuario->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $rol->id,
        ]);

        app(CurrentContext::class)->establecer($this->empresa->id, [$this->sucursal->id], $this->sucursal->id);

        $this->cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
            'user_id' => $this->usuario->id,
        ]);

        $this->plan = $this->plan($this->sucursal, 'Plan 100M');
    }

    private function plan(Branch $sucursal, string $nombre): Plan
    {
        return Plan::create([
            'name' => $nombre,
            'branch_id' => $sucursal->id,
            'user_id' => $this->usuario->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'client_id' => $this->cliente->id,
            'plan_id' => $this->plan->id,
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'neighborhood' => 'Centro',
            'address' => 'Calle 30 # 23-23',
            'home_type' => 'Propia',
            'social_stratum' => '2',
        ], $extra);
    }

    private function contrato(array $extra = []): Contract
    {
        $this->post(route('contracts.store'), $this->datos($extra))->assertSessionHasNoErrors();

        return Contract::where('client_id', $this->cliente->id)->latest('id')->firstOrFail();
    }

    // ==================== 1. El alta no acepta cualquier campo ====================

    public function test_el_alta_no_deja_elegir_el_estado(): void
    {
        // Un contrato que nace «Activo» sin instalarse empieza a
        // facturar un servicio que nadie ha conectado.
        $contrato = $this->contrato(['status' => 'Activo']);

        $this->assertSame('Por Instalar', $contrato->fresh()->status);
    }

    public function test_el_alta_no_deja_colar_un_descuento(): void
    {
        // El descuento tiene su propia acción, que impide porcentajes
        // mayores que 100 porque dejan la factura en negativo. Por el
        // alta se saltaba esa comprobación entera.
        $contrato = $this->contrato([
            'discount_type' => 'porcentaje',
            'discount_value' => 500,
            'discount_months' => 60,
        ]);

        $contrato->refresh();

        $this->assertNull($contrato->discount_type);
        $this->assertNull($contrato->discount_value);
    }

    public function test_el_alta_no_deja_elegir_el_numero_ni_las_fechas(): void
    {
        $contrato = $this->contrato([
            'contract_number' => 'INVENTADO-1',
            'billing_start_date' => '2099-01-01',
            'activation_date' => '2020-01-01',
        ]);

        $contrato->refresh();

        $this->assertNotSame('INVENTADO-1', $contrato->contract_number);
        $this->assertNull($contrato->billing_start_date);
        $this->assertNull($contrato->activation_date);
    }

    public function test_lo_que_el_formulario_si_manda_se_guarda(): void
    {
        // La otra cara de la lista cerrada: no puede dejar fuera
        // ningún campo legítimo del formulario.
        $contrato = $this->contrato([
            'cpe_sn' => 'HWTC12345678',
            'user_pppoe' => 'juan_perez',
            'password_pppoe' => 'clave123',
            'ssid_wifi' => 'Familia Perez',
            'password_wifi' => 'wifi12345',
            'permanence_clause' => 12,
            'comment' => 'Casa esquinera',
        ])->fresh();

        $this->assertSame('HWTC12345678', $contrato->cpe_sn);
        $this->assertSame('juan_perez', $contrato->user_pppoe);
        $this->assertSame('Familia Perez', $contrato->ssid_wifi);
        $this->assertSame(12, (int) $contrato->permanence_clause);
        $this->assertSame('Casa esquinera', $contrato->comment);
        $this->assertSame('Yarumal', $contrato->municipality);
    }

    // ==================== Datos que no caben ====================

    public function test_un_serial_que_no_cabe_se_rechaza_con_mensaje(): void
    {
        // cpe_sn es varchar(20): sin validar, 21 caracteres eran un
        // «Data too long» de MySQL y una página de error 500.
        $this->post(route('contracts.store'), $this->datos(['cpe_sn' => str_repeat('A', 21)]))
            ->assertSessionHasErrors('cpe_sn');

        $this->assertSame(0, Contract::count());
    }

    public function test_una_permanencia_que_no_es_numero_se_rechaza(): void
    {
        $this->post(route('contracts.store'), $this->datos(['permanence_clause' => 'un año']))
            ->assertSessionHasErrors('permanence_clause');

        $this->assertSame(0, Contract::count());
    }

    public function test_un_cliente_que_no_existe_se_rechaza(): void
    {
        $this->post(route('contracts.store'), $this->datos(['client_id' => 999999]))
            ->assertSessionHasErrors('client_id');
    }

    // ==================== 2. Contrato y orden, juntos o ninguno ====================

    public function test_el_contrato_nace_con_su_orden_de_instalacion(): void
    {
        $contrato = $this->contrato();

        $this->assertSame(1, TechnicalOrder::where('contract_id', $contrato->id)->count());
    }

    /**
     * Si la orden falla, el contrato no queda a medias.
     *
     * Es también la prueba de «error de base de datos durante una
     * transacción»: lo que se había escrito se deshace entero.
     */
    public function test_si_la_orden_falla_no_queda_un_contrato_huerfano(): void
    {
        TechnicalOrder::creating(function () {
            throw new \RuntimeException('Fallo simulado al crear la orden');
        });

        $this->post(route('contracts.store'), $this->datos())->assertStatus(500);

        $this->assertSame(0, Contract::count(), 'Quedó un contrato sin orden de instalación.');
    }

    // ==================== 3. Un formulario no borra los datos de otro ====================

    /**
     * EL CASO QUE BORRABA DATOS EN SILENCIO.
     *
     * Guardar «Datos del servicio» con el plan y la permanencia en
     * blanco dejaba en NULO el serial, la cuenta PPPoE y el wifi.
     */
    public function test_guardar_el_servicio_vacio_no_borra_los_datos_tecnicos(): void
    {
        $contrato = $this->contrato([
            'cpe_sn' => 'HWTC12345678',
            'user_pppoe' => 'juan_perez',
            'ssid_wifi' => 'Familia Perez',
        ]);

        $this->put(route('contracts.update', $contrato), [
            'plan_id' => '',
            'permanence_clause' => '',
        ])->assertSessionHasErrors('plan_id');

        $contrato->refresh();

        $this->assertSame('HWTC12345678', $contrato->cpe_sn);
        $this->assertSame('juan_perez', $contrato->user_pppoe);
        $this->assertSame('Familia Perez', $contrato->ssid_wifi);
    }

    // ==================== 4. El cambio de plan valida el plan ====================

    public function test_no_se_puede_dejar_el_contrato_sin_plan(): void
    {
        $contrato = $this->contrato();

        $this->put(route('contracts.update', $contrato), ['plan_id' => ''])
            ->assertSessionHasErrors('plan_id');

        $this->assertSame($this->plan->id, (int) $contrato->fresh()->plan_id);
    }

    public function test_un_plan_que_no_existe_se_rechaza_sin_error_500(): void
    {
        $contrato = $this->contrato();

        $this->put(route('contracts.update', $contrato), ['plan_id' => 999999])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_no_se_asigna_un_plan_exclusivo_de_otra_sucursal(): void
    {
        $contrato = $this->contrato();

        $otraSede = Branch::factory()->create(['company_id' => $this->empresa->id]);
        $ajeno = $this->plan($otraSede, 'Plan de otra sede');

        $this->put(route('contracts.update', $contrato), ['plan_id' => $ajeno->id])
            ->assertSessionHasErrors('plan_id');

        $this->assertSame($this->plan->id, (int) $contrato->fresh()->plan_id);
    }

    public function test_cambiar_a_otro_plan_de_la_sucursal_funciona(): void
    {
        $contrato = $this->contrato();
        $nuevo = $this->plan($this->sucursal, 'Plan 300M');

        $this->put(route('contracts.update', $contrato), [
            'plan_id' => $nuevo->id,
            'permanence_clause' => 6,
        ])->assertSessionHasNoErrors();

        $contrato->refresh();

        $this->assertSame($nuevo->id, (int) $contrato->plan_id);
        $this->assertSame(6, (int) $contrato->permanence_clause);
    }

    public function test_guardar_sin_tocar_el_plan_que_ya_tenia_no_falla(): void
    {
        // Un contrato viejo puede tener un plan que hoy no pasaría la
        // regla. Guardar el modal sin cambiarlo no puede reventar.
        $contrato = $this->contrato();

        $otraSede = Branch::factory()->create(['company_id' => $this->empresa->id]);
        $heredado = $this->plan($otraSede, 'Plan heredado');

        Contract::whereKey($contrato->id)->update(['plan_id' => $heredado->id]);

        $this->put(route('contracts.update', $contrato), [
            'plan_id' => $heredado->id,
            'permanence_clause' => 12,
        ])->assertSessionHasNoErrors();
    }

    public function test_los_datos_tecnicos_tambien_se_validan_al_editar(): void
    {
        $contrato = $this->contrato(['cpe_sn' => 'BUENO123']);

        $this->put(route('contracts.update', $contrato), ['cpe_sn' => str_repeat('A', 21)])
            ->assertSessionHasErrors('cpe_sn');

        $this->assertSame('BUENO123', $contrato->fresh()->cpe_sn);
    }

    // ==================== Eliminar ====================

    public function test_eliminar_un_contrato_explica_que_hacer_en_vez_de_una_pagina_en_blanco(): void
    {
        $contrato = $this->contrato();

        $this->delete(route('contracts.destroy', $contrato))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('orden de retiro', session('error'));
        $this->assertDatabaseHas('contracts', ['id' => $contrato->id]);
    }
}
