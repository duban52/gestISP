<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\Transport\DianTransport;
use App\Billing\Dian\Transport\DocumentTransmitter;
use App\Billing\Dian\Transport\FakeDianTransport;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Jobs\TransmitElectronicDocument;
use App\Models\DocumentTransmission;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * La transmisión a la DIAN (fase 11).
 *
 * QUÉ SE PUEDE PROBAR SIN CREDENCIALES
 * ------------------------------------
 * Todo lo que importa, que no es el envío. El envío es una línea; lo
 * que cuesta —y lo que se rompe— es lo de alrededor:
 *
 *   · que un documento aceptado **no se vuelva a mandar**,
 *   · que un rechazo **no se reintente**, porque daría el mismo no,
 *   · que un error de comunicación **sí** se reintente, con la cadencia
 *     que fija el anexo, y que se pare al agotarla,
 *   · y que cada intento quede registrado con lo que contestó la DIAN.
 *
 * Se prueba con `FakeDianTransport`, que deja programar la respuesta.
 * Lo único que queda sin probar es si la DIAN acepta nuestro sobre SOAP
 * — y eso no se puede saber hasta tener su URL, que ella misma expone
 * dentro de la cuenta del catálogo del facturador.
 */
class DianTransmissionTest extends TestCase
{
    use RefreshDatabase;

    private FakeDianTransport $transporte;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transporte = new FakeDianTransport();
        $this->app->instance(DianTransport::class, $this->transporte);
    }

    // ==================== Idempotencia ====================

    public function test_un_documento_aceptado_no_se_vuelve_a_mandar(): void
    {
        // Es la propiedad más importante de toda la fase. Una cola puede
        // repetir un trabajo —es su comportamiento normal ante un fallo
        // del trabajador—, así que la garantía tiene que estar aquí y no
        // en quien llama.
        $documento = $this->documento(['status' => ElectronicDocument::ACEPTADO]);

        $resultado = $this->transmisor()->transmitir($documento);

        $this->assertNull($resultado);
        $this->assertSame(0, $this->transporte->veces());
    }

    public function test_un_documento_rechazado_tampoco(): void
    {
        // El rechazo es por el contenido del documento: reintentarlo da
        // exactamente el mismo no. Lo que hay que hacer es corregir y
        // emitir otro.
        $documento = $this->documento(['status' => ElectronicDocument::RECHAZADO]);

        $this->assertNull($this->transmisor()->transmitir($documento));
        $this->assertSame(0, $this->transporte->veces());
    }

    public function test_un_documento_sin_firmar_no_se_manda(): void
    {
        // Transmitir un XML sin firma es gastar un intento para que lo
        // rechacen.
        $documento = $this->documento(['status' => ElectronicDocument::GENERADO]);

        $this->assertNull($this->transmisor()->transmitir($documento));
        $this->assertSame(0, $this->transporte->veces());
    }

    // ==================== Los estados ====================

    public function test_aceptado_cierra_el_documento(): void
    {
        $this->transporte->responder(TransmissionResult::aceptado('track-123'));

        $documento = $this->documento();
        $this->transmisor()->transmitir($documento);

        $documento->refresh();

        $this->assertSame(ElectronicDocument::ACEPTADO, $documento->status);
        $this->assertSame('track-123', $documento->dian_track_id);
        $this->assertNotNull($documento->accepted_at);
        $this->assertNull($documento->last_error);
        $this->assertTrue($documento->estaCerrado());
    }

    public function test_rechazado_guarda_el_motivo(): void
    {
        // El motivo es lo que hay que corregir antes de emitir el
        // documento que lo sustituya: sin él no se sabe qué arreglar.
        $this->transporte->responder(TransmissionResult::rechazado([
            'Regla FAJ40: el NIT del adquiriente no existe.',
        ]));

        $documento = $this->documento();
        $this->transmisor()->transmitir($documento);

        $documento->refresh();

        $this->assertSame(ElectronicDocument::RECHAZADO, $documento->status);
        $this->assertStringContainsString('FAJ40', $documento->last_error);
    }

    public function test_un_error_de_comunicacion_no_cambia_el_estado(): void
    {
        // Al documento no le ha pasado nada: sigue firmado y a la
        // espera. Lo que falló fue la comunicación.
        $this->transporte->responder(TransmissionResult::error(['La DIAN no responde.']));

        $documento = $this->documento();
        $this->transmisor()->transmitir($documento);

        $documento->refresh();

        $this->assertSame(ElectronicDocument::FIRMADO, $documento->status);
        $this->assertSame(1, $documento->attempts);
        $this->assertStringContainsString('no responde', $documento->last_error);
    }

    // ==================== El registro de intentos ====================

    public function test_cada_intento_queda_registrado(): void
    {
        // El anexo obliga a «mantener o archivar las evidencias del
        // error» (§12.2). Con una sola fila por documento, cada
        // reintento pisaría al anterior.
        $this->transporte->responder(
            TransmissionResult::error(['Primero falló.']),
            TransmissionResult::error(['Y otra vez.']),
            TransmissionResult::aceptado('track-final'),
        );

        $documento = $this->documento();
        $transmisor = $this->transmisor();

        $transmisor->transmitir($documento);
        $transmisor->transmitir($documento->fresh());
        $transmisor->transmitir($documento->fresh());

        $intentos = DocumentTransmission::withoutGlobalScopes()
            ->where('electronic_document_id', $documento->id)
            ->orderBy('attempt')
            ->get();

        $this->assertCount(3, $intentos);
        $this->assertSame([1, 2, 3], $intentos->pluck('attempt')->all());
        $this->assertSame(DocumentTransmission::ERROR, $intentos[0]->outcome);
        $this->assertSame(DocumentTransmission::ACEPTADO, $intentos[2]->outcome);
        $this->assertSame('Primero falló.', $intentos[0]->primerError());
    }

    public function test_el_intento_guarda_cuanto_tardo(): void
    {
        // Sirve para detectar la «demora declarada» del §12.4, que
        // empieza al minuto.
        $this->transporte->responder(TransmissionResult::aceptado());

        $documento = $this->documento();
        $this->transmisor()->transmitir($documento);

        $intento = DocumentTransmission::withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($intento->duration_ms);
        $this->assertGreaterThanOrEqual(0, $intento->duration_ms);
    }

    public function test_si_el_transporte_revienta_se_anota_como_error(): void
    {
        // Que el transporte lance no puede tumbar la cola: es un error
        // de comunicación como cualquier otro.
        $this->app->instance(DianTransport::class, new class implements DianTransport {
            public function enviar(ElectronicDocument $documento): TransmissionResult
            {
                throw new \RuntimeException('Se cayó la red.');
            }

            public function nombre(): string
            {
                return 'explosivo';
            }
        });

        $documento = $this->documento();
        $resultado = $this->transmisor()->transmitir($documento);

        $this->assertSame(TransmissionResult::ERROR, $resultado->resultado);
        $this->assertSame(ElectronicDocument::FIRMADO, $documento->fresh()->status);
        $this->assertStringContainsString('Se cayó la red', $documento->fresh()->last_error);
    }

    // ==================== La cadencia del anexo ====================

    public function test_ante_un_error_se_espera_cinco_segundos(): void
    {
        // §12.2: reintentar a los 5 segundos, y dos veces más cada 5.
        $transmisor = $this->transmisor();
        $error = TransmissionResult::error(['falló']);

        $this->assertSame(5, $transmisor->esperaHastaElSiguiente($error, 1));
        $this->assertSame(5, $transmisor->esperaHastaElSiguiente($error, 2));

        // Al tercero se acaba: a los 15 segundos toca contingencia.
        $this->assertNull($transmisor->esperaHastaElSiguiente($error, 3));
        $this->assertTrue($transmisor->agotoLosIntentos($error, 3));
    }

    public function test_ante_una_demora_se_esperan_dos_minutos(): void
    {
        // §12.4: la cadencia es otra —2 minutos, hasta cinco veces—
        // porque una demora no es lo mismo que un error.
        $transmisor = $this->transmisor();
        $demora = TransmissionResult::demora();

        $this->assertSame(120, $transmisor->esperaHastaElSiguiente($demora, 1));
        $this->assertSame(120, $transmisor->esperaHastaElSiguiente($demora, 4));
        $this->assertNull($transmisor->esperaHastaElSiguiente($demora, 5));
    }

    public function test_lo_definitivo_no_se_reintenta(): void
    {
        $transmisor = $this->transmisor();

        $this->assertNull($transmisor->esperaHastaElSiguiente(TransmissionResult::aceptado(), 1));
        $this->assertNull($transmisor->esperaHastaElSiguiente(TransmissionResult::rechazado(['no']), 1));
        $this->assertFalse($transmisor->agotoLosIntentos(TransmissionResult::rechazado(['no']), 3));
    }

    // ==================== El job ====================

    public function test_el_job_reencola_con_el_retraso_del_anexo(): void
    {
        Queue::fake();

        $this->transporte->responder(TransmissionResult::error(['falló']));

        $documento = $this->documento();

        (new TransmitElectronicDocument($documento->id))->handle($this->transmisor());

        Queue::assertPushed(TransmitElectronicDocument::class);
    }

    public function test_el_job_no_reencola_lo_aceptado(): void
    {
        Queue::fake();

        $this->transporte->responder(TransmissionResult::aceptado('track-1'));

        $documento = $this->documento();

        (new TransmitElectronicDocument($documento->id))->handle($this->transmisor());

        Queue::assertNothingPushed();
    }

    public function test_el_job_no_revienta_si_el_documento_ya_no_existe(): void
    {
        Queue::fake();

        (new TransmitElectronicDocument(999999))->handle($this->transmisor());

        Queue::assertNothingPushed();
        $this->assertSame(0, $this->transporte->veces());
    }

    // ==================== El transporte por defecto ====================

    public function test_sin_endpoint_no_se_transmite_de_verdad(): void
    {
        // El simulado devuelve ERROR y no un «aceptado» de mentira: un
        // aceptado falso dejaría documentos marcados como validados por
        // la DIAN que la DIAN no ha visto nunca.
        $documento = $this->documento();

        $resultado = (new DocumentTransmitter(new FakeDianTransport()))->transmitir($documento);

        $this->assertSame(TransmissionResult::ERROR, $resultado->resultado);
        $this->assertStringContainsString('transporte', $resultado->errores[0]);
        $this->assertSame(ElectronicDocument::FIRMADO, $documento->fresh()->status);
    }

    // ==================== Apoyo ====================

    private function transmisor(): DocumentTransmitter
    {
        return new DocumentTransmitter($this->app->make(DianTransport::class));
    }

    /** @param array<string, mixed> $extra */
    private function documento(array $extra = []): ElectronicDocument
    {
        $factura = Invoice::factory()->create();

        return ElectronicDocument::withoutGlobalScopes()->create(array_merge([
            'company_id' => $factura->company_id,
            'invoice_id' => $factura->id,
            'environment_code' => '2',
            'cufe' => str_repeat('a', 96),
            'signed_xml' => '<Invoice><ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"/></Invoice>',
            'qr_content' => 'CUFE: ' . str_repeat('a', 96),
            'status' => ElectronicDocument::FIRMADO,
            'generated_at' => now(),
            'signed_at' => now(),
        ], $extra));
    }
}
