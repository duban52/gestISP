<?php

namespace App\Jobs;

use App\Billing\Dian\Transport\DocumentTransmitter;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Models\ElectronicDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda un documento a la DIAN, en cola.
 *
 * POR QUÉ EN COLA Y NO EN LA PETICIÓN
 * -----------------------------------
 * Porque el anexo obliga a esperar entre reintentos: 5 segundos ante
 * error y **2 minutos** ante demora, hasta cinco veces. Dormir eso
 * dentro de la petición que emitió la factura dejaría al usuario
 * mirando una pantalla en blanco diez minutos, y la corrida mensual —que
 * emite cientos— sería imposible.
 *
 * Con la cola, emitir devuelve al instante y la transmisión ocurre
 * detrás. Que la DIAN tarde no es problema de nadie.
 *
 * CADA INTENTO ES UN JOB
 * ----------------------
 * No hay un bucle con `sleep`: el job hace UN intento y, si toca
 * reintentar, se vuelve a encolar con el retraso que manda el anexo.
 * Así un reintento a 2 minutos no ocupa un trabajador durante 2
 * minutos, y el estado de cada intento queda en la base y no en la
 * memoria de un proceso.
 *
 * LA IDEMPOTENCIA NO ESTÁ AQUÍ
 * ----------------------------
 * Está en `DocumentTransmitter`, a propósito. Una cola puede repetir un
 * job —es su comportamiento normal ante un fallo del trabajador—, así
 * que la garantía de «un documento aceptado no se reenvía» tiene que
 * vivir donde se decide, no en quien llama.
 */
class TransmitElectronicDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Reintentos del JOB, que no son los del anexo.
     *
     * Estos cubren que reviente el propio trabajador. Los reintentos por
     * respuesta de la DIAN se gestionan reencolando con su retraso, que
     * es otra cosa.
     */
    public int $tries = 3;

    public function __construct(
        public readonly int $documentoId,
    ) {
    }

    public function handle(DocumentTransmitter $transmisor): void
    {
        $documento = ElectronicDocument::withoutGlobalScopes()->find($this->documentoId);

        if (!$documento) {
            // Lo borraron entre encolar y ejecutar. No es un error.
            return;
        }

        $resultado = $transmisor->transmitir($documento);

        if ($resultado === null) {
            // No había nada que hacer: ya estaba cerrado o no estaba
            // listo. Tampoco es un error.
            return;
        }

        if ($resultado->esDefinitivo()) {
            return;
        }

        $espera = $transmisor->esperaHastaElSiguiente($resultado, $documento->fresh()->attempts);

        if ($espera === null) {
            // Se agotaron los intentos que concede el anexo. A partir de
            // aquí correspondería la contingencia tipo 04 —emitir sin
            // validación previa—, que todavía no está implementada.
            Log::warning('Se agotaron los intentos de transmisión a la DIAN', [
                'documento' => $documento->id,
                'factura' => $documento->invoice?->full_number,
                'intentos' => $documento->fresh()->attempts,
                'ultimo_error' => $resultado->errores,
            ]);

            return;
        }

        // Otro intento, con el retraso que manda el anexo. Se despacha
        // uno nuevo en vez de `release()` para que el contador de
        // reintentos del job siga cubriendo solo los fallos del
        // trabajador.
        self::dispatch($this->documentoId)->delay(now()->addSeconds($espera));
    }

    /**
     * Si el job revienta del todo, queda dicho.
     *
     * El documento no se toca: sigue firmado y a la espera, que es su
     * estado real. Lo que falló fue el envío, no el documento.
     */
    public function failed(\Throwable $error): void
    {
        Log::error('El job de transmisión a la DIAN falló', [
            'documento' => $this->documentoId,
            'motivo' => $error->getMessage(),
        ]);
    }
}
