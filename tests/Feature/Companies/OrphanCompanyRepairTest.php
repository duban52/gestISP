<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingTestCase;

/**
 * Filas que perdieron su empresa, y la ficha que reventaba por eso.
 *
 * DE DÓNDE SALE ESTO
 * ------------------
 * De dos fallos reales en producción tras el despliegue de
 * multiempresa: «Attempt to read property "name" on null» al consultar
 * facturas, y «Attempt to read property "id" on null» al abrir un
 * contrato.
 *
 * La causa es la misma y conviene entenderla, porque no es evidente:
 *
 *   1. La migración rellenó `company_id` con un JOIN por `branch_id`.
 *      Las filas SIN sucursal —clientes creados en modo consolidado—
 *      se quedaron en null.
 *   2. `BelongsToCompany` filtra por `company_id = X`, y eso EXCLUYE
 *      las nulas. La fila existe en la base y desaparece de la
 *      aplicación.
 *   3. Una consulta con JOIN en SQL crudo sí la encuentra. Así que la
 *      fila sale en un listado y su relación devuelve null.
 *
 * QUÉ SE DEFIENDE
 * ---------------
 * Las dos mitades, porque hacen falta las dos:
 *
 *   · Que la pantalla **aguante** el dato huérfano en vez de tumbarse.
 *     Un dato malo hay que arreglarlo; que por eso no se pueda abrir
 *     ningún contrato es un problema mayor.
 *   · Que el comando lo **repare de verdad**, que es lo único que
 *     devuelve el cliente a la vista. Una pantalla que no revienta
 *     pero muestra guiones no es el arreglo.
 */
class OrphanCompanyRepairTest extends BillingTestCase
{
    public function test_la_ficha_del_contrato_aguanta_un_cliente_escondido(): void
    {
        $contrato = $this->createBillableContract();

        $this->esconder($contrato);

        $this->get(route('contracts.show', $contrato))->assertOk();
    }

    public function test_la_ficha_avisa_de_que_el_cliente_no_esta_visible(): void
    {
        // Sin el aviso, la ficha sale con los campos en blanco y parece
        // un contrato sin datos en vez de un dato que hay que reparar.
        $contrato = $this->createBillableContract();

        $this->esconder($contrato);

        $this->get(route('contracts.show', $contrato))
            ->assertSee('quedó sin empresa asignada', escape: false);
    }

    public function test_el_comando_devuelve_el_cliente_por_su_contrato(): void
    {
        // Un cliente creado en consolidado no tiene sucursal propia,
        // pero sus contratos sí — y ahí es donde de verdad se le
        // atiende.
        $contrato = $this->createBillableContract();
        $empresaId = $contrato->fresh()->company_id;

        $this->esconder($contrato, tambienLaSucursal: true);

        $this->artisan('gestisp:empresas-migrar --aplicar')->assertSuccessful();

        $this->assertSame(
            $empresaId,
            Client::withoutGlobalScopes()->whereKey($contrato->client_id)->value('company_id'),
        );
    }

    public function test_sin_aplicar_no_escribe_nada(): void
    {
        // El modo revisión tiene que poder correrse en producción sin
        // consecuencias: es lo que se mira antes de decidir.
        $contrato = $this->createBillableContract();

        $this->esconder($contrato, tambienLaSucursal: true);

        $this->artisan('gestisp:empresas-migrar')->assertSuccessful();

        $this->assertNull(
            Client::withoutGlobalScopes()->whereKey($contrato->client_id)->value('company_id'),
        );
    }

    public function test_el_cliente_vuelve_a_verse_despues_de_reparar(): void
    {
        // La comprobación que cierra el círculo: no basta con que la
        // columna se rellene, tiene que volver a pasar el alcance.
        //
        // Se mira POR HTTP y no sobre el modelo a propósito: el alcance
        // de empresa solo actúa con el contexto activo, y quien lo
        // activa es el middleware. Comprobarlo con `$contrato->client`
        // a secas no probaría nada — fuera de una petición no hay
        // filtro y el cliente se ve igual.
        $contrato = $this->createBillableContract();
        $nombre = $contrato->client->name;

        $this->esconder($contrato, tambienLaSucursal: true);

        $this->get(route('contracts.show', $contrato))
            ->assertSee('quedó sin empresa asignada', escape: false);

        $this->artisan('gestisp:empresas-migrar --aplicar');

        $this->get(route('contracts.show', $contrato))
            ->assertDontSee('quedó sin empresa asignada', escape: false)
            ->assertSee($nombre, escape: false);
    }

    // ============ El cliente que está en OTRA empresa ============

    public function test_repara_al_cliente_que_quedo_en_otra_empresa(): void
    {
        // Peor que un huérfano y más difícil de ver: el cliente sí
        // aparece —en SU empresa— pero desaparece desde el contrato,
        // que está en la sucursal de otra. Es como quedaron los
        // clientes cuya branch_id apuntaba a una sede distinta de la
        // de sus contratos.
        $contrato = $this->createBillableContract();
        $otra = Company::factory()->create();

        DB::table('clients')->where('id', $contrato->client_id)
            ->update(['company_id' => $otra->id]);

        $this->get(route('contracts.show', $contrato))->assertOk();

        $this->artisan('gestisp:empresas-migrar --aplicar')->assertSuccessful();

        $this->assertSame(
            $contrato->fresh()->company_id,
            Client::withoutGlobalScopes()->whereKey($contrato->client_id)->value('company_id'),
        );
    }

    public function test_no_mueve_a_un_cliente_con_contratos_en_varias_empresas(): void
    {
        // Mover un cliente entre contribuyentes tiene consecuencias
        // fiscales. Si sus contratos están repartidos, no hay una
        // respuesta correcta que el comando pueda deducir: se informa
        // y se deja quieto.
        $contrato = $this->createBillableContract();

        $otraEmpresa = Company::factory()->create();
        $otraSucursal = Branch::factory()->create(['company_id' => $otraEmpresa->id]);

        // Un segundo contrato del MISMO cliente, en otra empresa.
        Contract::factory()->create([
            'client_id' => $contrato->client_id,
            'branch_id' => $otraSucursal->id,
            'company_id' => $otraEmpresa->id,
            'plan_id' => null,
            'user_id' => $this->admin->id,
        ]);

        $empresaOriginal = Client::withoutGlobalScopes()
            ->whereKey($contrato->client_id)->value('company_id');

        $this->artisan('gestisp:empresas-migrar --aplicar')->assertSuccessful();

        $this->assertSame(
            $empresaOriginal,
            Client::withoutGlobalScopes()->whereKey($contrato->client_id)->value('company_id'),
            'Se movió un cliente cuyo destino era ambiguo.',
        );
    }

    /**
     * Deja al cliente como quedan los creados en modo consolidado.
     *
     * Se escribe por consulta directa a propósito: con el modelo, el
     * gancho `saving` de BelongsToCompany volvería a rellenarlo.
     */
    private function esconder(Contract $contrato, bool $tambienLaSucursal = false): void
    {
        $datos = ['company_id' => null];

        if ($tambienLaSucursal) {
            $datos['branch_id'] = null;
        }

        DB::table('clients')->where('id', $contrato->client_id)->update($datos);
    }
}
