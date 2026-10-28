<?php

namespace Tests\Feature\System;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La empresa de prueba que se siembra EN PRODUCCIÓN.
 *
 * Esto no es un seeder de desarrollo: se ejecuta a mano en el
 * servidor real, al lado de los datos de un cliente de verdad. Lo
 * que se defiende aquí no es que funcione —eso se ve al ejecutarlo—
 * sino que **no pueda hacer daño**:
 *
 *  1. Que los clientes inventados sean INALCANZABLES. Sin correo y
 *     sin teléfono no hay canal por el que escribirles, pase lo que
 *     pase con los interruptores de notificaciones. Si alguien
 *     «arregla» el sembrado poniéndoles un correo de relleno, estas
 *     pruebas fallan.
 *
 *  2. Que las facturas sean INTERNAS. Con el grupo en electrónica,
 *     cada factura de prueba gastaría un consecutivo del rango
 *     autorizado por la DIAN, que no se recupera.
 *
 *  3. Que se pueda DESHACER. Sembrar datos en producción solo es
 *     aceptable si se pueden quitar.
 *
 *  4. Que el borrado se niegue a tocar una empresa que no esté
 *     marcada como de prueba.
 */
class EmpresaDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        // El comando cuelga lo creado del primer usuario que haya:
        // en un servidor real siempre existe al menos el superadmin.
        User::factory()->create(['number_phone' => '3000000000']);
    }

    private function sembrar(int $clientes = 5): Company
    {
        $this->artisan('demo:sembrar', [
            '--clientes' => $clientes,
            '--force' => true,
        ])->assertSuccessful();

        return Company::withoutGlobalScopes()
            ->where('legal_name', 'like', '%DEMO%')
            ->firstOrFail();
    }

    // ==================== Que no pueda hacer daño ====================

    public function test_los_clientes_de_prueba_no_tienen_por_donde_recibir_nada(): void
    {
        $empresa = $this->sembrar();

        $alcanzables = Client::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where(fn ($q) => $q->whereNotNull('email')->orWhere('number_phone', '!=', ''))
            ->count();

        $this->assertSame(0, $alcanzables, 'Hay clientes de prueba a los que se les puede escribir.');
    }

    /**
     * La prueba que importa de verdad: el canal se decide con
     * `filled(routeNotificationFor(...))`, así que se comprueba
     * contra ESE método y no contra las columnas.
     */
    public function test_ninguna_notificacion_encuentra_destino(): void
    {
        $empresa = $this->sembrar();

        $cliente = Client::withoutGlobalScopes()->where('company_id', $empresa->id)->firstOrFail();

        $this->assertFalse(filled($cliente->routeNotificationFor('mail')));
        $this->assertFalse(filled($cliente->routeNotificationFor('whatsapp')));
    }

    public function test_las_facturas_de_prueba_no_van_a_la_dian(): void
    {
        $empresa = $this->sembrar();

        $this->assertDatabaseHas('affinity_groups', [
            'company_id' => $empresa->id,
            'requires_electronic_invoicing' => false,
        ]);

        $electronicos = \App\Models\AffinityGroup::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('requires_electronic_invoicing', true)
            ->count();

        $this->assertSame(0, $electronicos);
    }

    public function test_no_crea_ningun_equipo(): void
    {
        $empresa = $this->sembrar();

        $sucursales = \App\Models\Branch::withoutGlobalScopes()
            ->where('company_id', $empresa->id)->pluck('id');

        $this->assertSame(0, \App\Models\Olt::withoutGlobalScopes()->whereIn('branch_id', $sucursales)->count());
        $this->assertSame(0, \App\Models\Router::withoutGlobalScopes()->whereIn('branch_id', $sucursales)->count());
    }

    // ==================== Que sirva para lo que se pidió ====================

    public function test_siembra_clientes_con_contrato_activo_y_numerado(): void
    {
        $empresa = $this->sembrar(5);

        $sucursales = \App\Models\Branch::withoutGlobalScopes()
            ->where('company_id', $empresa->id)->pluck('id');

        $contratos = Contract::withoutGlobalScopes()->whereIn('branch_id', $sucursales)->get();

        $this->assertCount(5, $contratos);
        $this->assertTrue($contratos->every(fn ($c) => filled($c->contract_number)));
        $this->assertTrue($contratos->every(fn ($c) => filled($c->plan_id)));
        $this->assertSame(5, $contratos->pluck('contract_number')->unique()->count());
    }

    // ==================== Que se pueda deshacer ====================

    public function test_el_borrado_lo_quita_todo(): void
    {
        $empresa = $this->sembrar(5);

        $this->artisan('demo:borrar', ['--empresa' => $empresa->id, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('companies', ['id' => $empresa->id]);
        $this->assertSame(0, Client::withoutGlobalScopes()->where('company_id', $empresa->id)->count());
        $this->assertSame(0, \App\Models\Branch::withoutGlobalScopes()->where('company_id', $empresa->id)->count());
    }

    public function test_el_borrado_se_niega_con_una_empresa_que_no_es_de_prueba(): void
    {
        // La protección que el día que alguien se equivoque de id
        // evitará el desastre.
        $real = Company::create([
            'legal_name' => 'EASYNET San Andrés',
            'document_number' => '900123456',
            'active' => true,
        ]);

        $this->artisan('demo:borrar', ['--empresa' => $real->id, '--force' => true])
            ->assertFailed();

        $this->assertDatabaseHas('companies', ['id' => $real->id]);
    }
}
