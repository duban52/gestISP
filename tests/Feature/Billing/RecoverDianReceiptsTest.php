<?php

namespace Tests\Feature\Billing;

use App\Models\DocumentTransmission;
use App\Models\ElectronicDocument;
use App\Notifications\ElectronicInvoiceDelivered;
use Illuminate\Support\Facades\Notification;

/**
 * Recuperar el acuse de la DIAN de documentos ya aceptados.
 *
 * DE DÓNDE SALE ESTA NECESIDAD
 * ----------------------------
 * De un caso real: el documento 10 en producción quedó `accepted` con el
 * acuse en blanco, aunque la respuesta completa de la DIAN sí estaba
 * guardada en `document_transmissions`. Sin acuse no se puede armar el
 * `AttachedDocument`, y el cliente no recibe su factura.
 *
 * Lo mismo le pasa a todo lo aceptado ANTES de que se empezara a guardar
 * el acuse.
 *
 * LO IMPORTANTE: NO SE LE PIDE NADA A LA DIAN
 * -------------------------------------------
 * El acuse viaja dentro de la respuesta SOAP que ya está en la base. Se
 * desempaqueta de ahí. Reemitir o retransmitir para conseguirlo sería
 * gastar consecutivos autorizados por un dato que ya se tiene.
 */
class RecoverDianReceiptsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Una respuesta de la DIAN como la real: el acuse en base64 dentro. */
    private function respuestaConAcuse(string $acuse): string
    {
        return '<?xml version="1.0"?><s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
            . '<SendBillSyncResponse><SendBillSyncResult>'
            . '<b:IsValid xmlns:b="urn:b">true</b:IsValid>'
            . '<b:XmlBase64Bytes xmlns:b="urn:b">' . base64_encode($acuse) . '</b:XmlBase64Bytes>'
            . '</SendBillSyncResult></SendBillSyncResponse></s:Body></s:Envelope>';
    }

    /** Un documento aceptado SIN acuse, con su respuesta guardada. */
    private function aceptadoSinAcuse(string $acuse = '<ApplicationResponse>validada</ApplicationResponse>'): ElectronicDocument
    {
        $factura = $this->facturaElectronica();

        $documento = ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();

        $documento->forceFill([
            'status' => ElectronicDocument::ACEPTADO,
            'accepted_at' => now(),
            'dian_response_xml' => null,
        ])->save();

        DocumentTransmission::withoutGlobalScopes()->create([
            'electronic_document_id' => $documento->id,
            'company_id' => $documento->company_id,
            'attempt' => 1,
            'outcome' => 'accepted',
            'http_status' => 200,
            'response' => $this->respuestaConAcuse($acuse),
        ]);

        return $documento->fresh();
    }

    public function test_recupera_el_acuse_de_la_respuesta_guardada(): void
    {
        $documento = $this->aceptadoSinAcuse();

        $this->artisan('dian:recuperar-acuses')
            ->expectsOutputToContain('Acuses recuperados: 1 de 1')
            ->assertSuccessful();

        $this->assertStringContainsString(
            '<ApplicationResponse>validada</ApplicationResponse>',
            $documento->fresh()->dian_response_xml,
        );
    }

    public function test_en_seco_no_guarda_nada(): void
    {
        $documento = $this->aceptadoSinAcuse();

        $this->artisan('dian:recuperar-acuses --dry-run')->assertSuccessful();

        $this->assertNull($documento->fresh()->dian_response_xml);
    }

    public function test_busca_en_todos_los_intentos_no_solo_en_el_ultimo(): void
    {
        // Un documento con varios intentos tiene respuestas SIN acuse
        // —los errores 500— antes de la buena. Mirar solo la última daría
        // por irrecuperable algo que sí se puede recuperar.
        $documento = $this->aceptadoSinAcuse();

        DocumentTransmission::withoutGlobalScopes()->create([
            'electronic_document_id' => $documento->id,
            'company_id' => $documento->company_id,
            'attempt' => 2,
            'outcome' => 'error',
            'http_status' => 500,
            'response' => '<s:Envelope><s:Body><s:Fault>InvalidSecurity</s:Fault></s:Body></s:Envelope>',
        ]);

        $this->artisan('dian:recuperar-acuses')->assertSuccessful();

        $this->assertNotNull($documento->fresh()->dian_response_xml);
    }

    public function test_lo_que_ya_tiene_acuse_no_se_toca(): void
    {
        $documento = $this->aceptadoSinAcuse();
        $documento->forceFill(['dian_response_xml' => '<ya>estaba</ya>'])->save();

        $this->artisan('dian:recuperar-acuses')
            ->expectsOutputToContain('No hay documentos aceptados sin acuse')
            ->assertSuccessful();

        $this->assertSame('<ya>estaba</ya>', $documento->fresh()->dian_response_xml);
    }

    public function test_sin_entregar_no_le_manda_nada_al_cliente(): void
    {
        // Rellenar una columna no puede acabar en correos a clientes sin
        // que nadie lo haya pedido.
        Notification::fake();

        $documento = $this->aceptadoSinAcuse();

        $this->artisan('dian:recuperar-acuses')->assertSuccessful();

        // La factura de prueba dispara su propio aviso al emitirse; lo
        // que aqui NO puede salir es la ENTREGA.
        Notification::assertNotSentTo(
            $documento->invoice->contract->client,
            ElectronicInvoiceDelivered::class,
        );
    }

    public function test_con_entregar_si(): void
    {
        Notification::fake();

        $documento = $this->aceptadoSinAcuse();

        $this->artisan('dian:recuperar-acuses --entregar')->assertSuccessful();

        Notification::assertSentTo(
            $documento->invoice->contract->client,
            ElectronicInvoiceDelivered::class,
        );
    }

    public function test_entregar_funciona_en_una_corrida_aparte(): void
    {
        // ESTE ES EL USO NORMAL, Y LA PRIMERA VERSION NO LO CUBRIA.
        //
        // Se recuperan los acuses, se comprueba que cuadran, y DESPUES
        // se entrega. En ese momento ya no queda nada por recuperar, y
        // la version anterior —que solo entregaba lo recuperado en la
        // misma corrida— no mandaba nada. La bandera era inservible
        // justo cuando se necesitaba.
        $documento = $this->aceptadoSinAcuse();

        $this->artisan('dian:recuperar-acuses')->assertSuccessful();

        Notification::fake();

        $this->artisan('dian:recuperar-acuses --entregar')
            ->expectsOutputToContain('Entregas encoladas: 1')
            ->assertSuccessful();

        Notification::assertSentTo(
            $documento->invoice->contract->client,
            ElectronicInvoiceDelivered::class,
        );
    }

    public function test_entregar_respeta_lo_ya_entregado(): void
    {
        // La guarda de `delivered_at` manda por encima del comando: una
        // factura que ya llegó al cliente no se le manda otra vez.
        Notification::fake();

        $documento = $this->aceptadoSinAcuse();
        $documento->forceFill(['delivered_at' => now()])->save();

        $this->artisan('dian:recuperar-acuses --entregar')->assertSuccessful();

        Notification::assertNotSentTo(
            $documento->invoice->contract->client,
            ElectronicInvoiceDelivered::class,
        );
    }
}
