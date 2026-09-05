<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;

/**
 * El listado y el PDF de facturas, con datos incompletos.
 *
 * DE DÓNDE SALE ESTA PRUEBA
 * -------------------------
 * De un fallo real en producción tras el despliegue de multiempresa:
 * «Attempt to read property "name" on null» al consultar las facturas.
 *
 * La causa es una trampa que va a repetirse, así que conviene tenerla
 * escrita. El listado arma su consulta con un JOIN a `clients`, pero
 * pinta el nombre a través de la RELACIÓN de Eloquent. El JOIN es SQL
 * crudo y no pasa por el alcance global de empresa; la relación sí. Un
 * cliente con `company_id` nulo —los creados en modo consolidado— hace
 * que el JOIN encuentre la fila y la relación devuelva null.
 *
 * O sea: la factura aparece en el listado y su cliente no existe.
 *
 * Y `contracts.plan_id` es nulo con `ON DELETE SET NULL`: borrar un
 * plan deja sin plan a todos sus contratos, y el PDF de cada factura de
 * esos contratos reventaba.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que un dato incompleto **no tumbe la pantalla entera**. Una factura
 * sin cliente visible es un problema que hay que arreglar en los datos;
 * que por eso no se pueda consultar ninguna factura de la sucursal es
 * un problema mucho mayor.
 */
class InvoiceListingResilienceTest extends BillingTestCase
{
    public function test_el_listado_aguanta_un_cliente_sin_empresa(): void
    {
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        // Así queda un cliente creado en consolidado: el JOIN lo
        // encuentra, el alcance de empresa lo esconde.
        Client::withoutGlobalScopes()
            ->whereKey($contrato->client_id)
            ->update(['company_id' => null]);

        $this->get(route('invoices.index'))->assertOk();
    }

    public function test_el_pdf_aguanta_un_contrato_sin_plan(): void
    {
        // `contracts.plan_id` es nulo con ON DELETE SET NULL: basta con
        // que alguien borre un plan.
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $contrato->update(['plan_id' => null]);

        $this->get(route('invoices.download-pdf', $factura->id))->assertOk();
    }

    public function test_la_ficha_de_la_factura_aguanta_un_cliente_escondido(): void
    {
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        Client::withoutGlobalScopes()
            ->whereKey($contrato->client_id)
            ->update(['company_id' => null]);

        $this->get(route('invoices.show', $factura))->assertOk();
    }

    public function test_el_pdf_masivo_aguanta_los_mismos_huecos(): void
    {
        // El PDF del mes recorre TODAS las facturas de la sucursal. Un
        // solo contrato sin plan tumbaba el lote entero, que es peor
        // que una factura mal impresa.
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $contrato->update(['plan_id' => null]);
        Client::withoutGlobalScopes()
            ->whereKey($contrato->client_id)
            ->update(['company_id' => null]);

        $this->get(route('invoices.generate_max_pdf'))->assertRedirect();
    }
}
