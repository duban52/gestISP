<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\ContractStatus;
use App\Billing\Events\InvoiceIssued;
use App\Models\Audit;
use App\Models\Branch;
use App\Models\Invoice;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

/**
 * Facturar UN contrato desde su ficha.
 *
 * QUÉ CIERRA
 * ----------
 * Hasta ahora la única forma de facturar era la corrida mensual, que es
 * de toda la sucursal. Un contrato instalado ayer, o uno que quedó
 * fuera del lote por estar suspendido y ya se reconectó, obligaba a
 * lanzar la corrida entera o a crear la factura a mano — que es
 * exactamente como se gasta un consecutivo autorizado sin las reglas
 * que lo protegen.
 *
 * LO QUE MÁS SE DEFIENDE AQUÍ
 * ---------------------------
 * Que esto **no sea un segundo camino**. Un botón que emita facturas
 * con sus propias reglas es como se acaba emitiendo una factura
 * electrónica sin CUFE, o gastando un consecutivo de otra sucursal.
 *
 * Por eso las pruebas no comprueban tanto que funcione —eso es una
 * línea— como que se comporte **igual que la corrida**: mismas
 * negativas (suspendido, ya facturado), mismo tipo de documento, mismo
 * evento, y el mismo permiso, que no es uno menor por ser de a uno.
 */
class SingleContractInvoiceTest extends BillingTestCase
{
    public function test_factura_el_contrato_y_lleva_a_la_factura(): void
    {
        $contrato = $this->createBillableContract(price: 80000);

        $respuesta = $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $respuesta->assertRedirect(route('invoices.show', $factura));
        $this->assertSame(now()->format('Ym'), $factura->billed_year_month);
    }

    public function test_la_factura_no_pertenece_a_ninguna_corrida(): void
    {
        // Es la única diferencia con el lote, y es intencionada: no hay
        // corrida a la que pertenecer. Por eso la huella de quién la
        // emitió tiene que estar en otro sitio (ver más abajo).
        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));

        $this->assertNull(Invoice::where('contract_id', $contrato->id)->value('billing_run_id'));
    }

    public function test_no_factura_dos_veces_el_mismo_periodo(): void
    {
        // El caso que de verdad importa: dos clics seguidos, o facturar
        // a mano algo que la corrida ya facturó. Una segunda factura
        // gasta otro consecutivo por el mismo servicio.
        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));
        $this->post(route('contracts.invoice', $contrato))
            ->assertSessionHas('error');

        $this->assertSame(1, Invoice::where('contract_id', $contrato->id)->count());
    }

    public function test_al_repetir_dice_cual_es_la_factura_que_ya_existe(): void
    {
        // Repetir «no se pudo» no ayuda a nadie: lo que la persona
        // necesita saber es que ya está hecho y cuál es.
        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));
        $numero = Invoice::where('contract_id', $contrato->id)->value('full_number');

        $this->post(route('contracts.invoice', $contrato));

        $this->assertStringContainsString((string) $numero, session('error'));
    }

    public function test_no_factura_un_contrato_suspendido(): void
    {
        $contrato = $this->createBillableContract();
        $contrato->update(['status' => ContractStatus::Suspendido->value]);

        $this->post(route('contracts.invoice', $contrato))
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
    }

    public function test_no_se_factura_un_contrato_de_otra_sucursal(): void
    {
        // Emitir en una sede ajena consume SU consecutivo autorizado.
        // Poner el id en la URL no puede bastar.
        $otra = Branch::factory()->create();
        $contrato = $this->createBillableContract();
        $contrato->update(['branch_id' => $otra->id]);

        $this->post(route('contracts.invoice', $contrato))->assertForbidden();

        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
    }

    public function test_exige_el_mismo_permiso_que_la_corrida(): void
    {
        // La autoridad que hace falta es la misma —crear un documento
        // fiscal que gasta un consecutivo— y no una menor por ser de
        // a uno.
        Role::where('name', 'superadministrador')->firstOrFail()
            ->revokePermissionTo('invoices.generate');

        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato))->assertForbidden();
    }

    public function test_queda_anotado_en_la_trazabilidad(): void
    {
        // Sin corrida a la que pertenecer, esta es la única huella de
        // por qué apareció una factura fuera del lote del mes.
        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));

        $anotacion = Audit::where('category', 'facturacion')
            ->where('description', 'like', '%individualmente%')
            ->first();

        $this->assertNotNull($anotacion, 'No se anotó la emisión individual.');
        $this->assertSame(Invoice::class, $anotacion->auditable_type);
    }

    public function test_dispara_el_evento_que_encadena_dian_y_el_aviso(): void
    {
        // `InvoiceIssued` es de donde cuelgan el documento electrónico
        // y el correo al cliente. Si esta vía no lo disparara, una
        // factura emitida desde el contrato saldría sin reportar a la
        // DIAN y sin que el cliente se entere — y nadie lo notaría
        // hasta cuadrar el mes.
        Event::fake([InvoiceIssued::class]);

        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));

        Event::assertDispatched(InvoiceIssued::class);
    }

    public function test_sale_por_el_mismo_camino_que_la_corrida(): void
    {
        // La prueba que justifica todo el diseño: si esto fuera un
        // camino paralelo, el tipo de documento se decidiría aquí en
        // vez de en ElectronicInvoicingDecider, y una factura podria
        // salir sin la naturaleza que le toca.
        $contrato = $this->createBillableContract();

        $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $this->assertNotNull($factura->document_kind, 'No se congeló el tipo de documento.');
        $this->assertNotNull($factura->full_number, 'No se numeró.');
        $this->assertSame($contrato->affinity_group_id, $factura->affinity_group_id);
        $this->assertTrue($factura->invoice_items()->exists(), 'Salió sin renglones.');
    }
}
