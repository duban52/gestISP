<?php

namespace Tests\Feature\TechnicalOrders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El listado de órdenes técnicas, con datos incompletos.
 *
 * DE DÓNDE SALE ESTA PRUEBA
 * -------------------------
 * De un error 500 real en producción: filtrar las órdenes por
 * «Cerrada» reventaba con «Attempt to read property "name" on null»,
 * y en local no se reproducía.
 *
 * LA TRAMPA, QUE YA NOS PILLÓ ANTES
 * ---------------------------------
 * `technical_orders.contract_id` NO es nulable y tiene clave ajena:
 * la fila del contrato existe SIEMPRE. Parece imposible que
 * `$orden->contract` sea null, y por eso la vista lo daba por hecho.
 *
 * Pero `Contract` y `Client` llevan el alcance global de empresa. Si su
 * `company_id` es nulo —los creados en modo consolidado— o de otra
 * empresa, la relación devuelve null aunque la fila esté ahí. La orden
 * aparece en el listado y su contrato «no existe».
 *
 * Es la misma trampa que [InvoiceListingResilienceTest] documenta para
 * las facturas. Que se repitiera en otro módulo es la razón de escribir
 * esta: no basta con arreglar la pantalla que reventó.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que un dato incompleto no tumbe la pantalla entera. Una orden sin
 * cliente visible es un problema de datos que hay que arreglar; que por
 * eso no se pueda consultar NINGUNA orden de la sucursal es un problema
 * mucho mayor.
 */
class OrderListingResilienceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->user = User::factory()->create();
        $this->user->assignRole($role);
        $this->user->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->user)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);
    }

    private function ordenCerrada(): TechnicalOrder
    {
        $plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);

        $client = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
        ]);

        $contract = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $this->user->id,
        ]);

        return TechnicalOrder::create([
            'contract_id' => $contract->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->user->id,
            'created_by' => $this->user->id,
            'type' => 'Servicio',
            'detail' => 'Instalación de servicio',
            'status' => 'Cerrada',
            'initial_comment' => 'Instalación del servicio',
        ]);
    }

    /** Deja al cliente fuera del alcance de empresa, como en consolidado. */
    private function esconderCliente(TechnicalOrder $orden): void
    {
        Client::withoutGlobalScopes()
            ->whereKey($orden->contract->client_id)
            ->update(['company_id' => null]);
    }

    /** Deja al contrato entero fuera del alcance. */
    private function esconderContrato(TechnicalOrder $orden): void
    {
        Contract::withoutGlobalScopes()
            ->whereKey($orden->contract_id)
            ->update(['company_id' => null]);
    }

    public function test_filtrar_por_cerradas_aguanta_un_cliente_escondido(): void
    {
        // Es EXACTAMENTE el caso que dio el 500: el filtro por estado
        // es lo único que saca a la luz las órdenes cerradas viejas, y
        // son las que arrastran los datos incompletos.
        $orden = $this->ordenCerrada();
        $this->esconderCliente($orden);

        $this->get(route('technicals_orders.index', [
            'filter_field' => 'status',
            'filter_value' => 'Cerrada',
        ]))->assertOk();
    }

    public function test_filtrar_por_cerradas_aguanta_un_contrato_escondido(): void
    {
        $orden = $this->ordenCerrada();
        $this->esconderContrato($orden);

        $this->get(route('technicals_orders.index', [
            'filter_field' => 'status',
            'filter_value' => 'Cerrada',
        ]))->assertOk();
    }

    public function test_el_listado_por_defecto_tambien_aguanta(): void
    {
        $orden = $this->ordenCerrada();
        $orden->update(['status' => 'Pendiente']);
        $this->esconderCliente($orden);

        $this->get(route('technicals_orders.index'))->assertOk();
    }

    public function test_la_ficha_de_la_orden_aguanta_los_mismos_huecos(): void
    {
        $orden = $this->ordenCerrada();
        $this->esconderCliente($orden);

        $this->get(route('technicals_orders.show', $orden->id))->assertOk();
    }
}
