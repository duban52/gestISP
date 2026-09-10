<?php

namespace App\Billing\Dian\Transport;

use App\Models\DocumentTransmission;
use App\Models\ElectronicDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manda un documento a la DIAN y se hace cargo de lo que pase.
 *
 * QUÉ RESUELVE
 * ------------
 * El envío en sí es una línea: se lo pide al `DianTransport`. Lo que
 * vive aquí es todo lo demás, que es lo que de verdad cuesta:
 *
 *   · **Idempotencia.** Un documento aceptado no se vuelve a mandar,
 *     pase lo que pase. Es la propiedad más importante de todas: la
 *     DIAN identifica los documentos por su CUFE, así que reenviar uno
 *     aceptado no crea un duplicado, pero sí gasta tiempo y ensucia el
 *     historial. Y un documento RECHAZADO tampoco se reintenta: el
 *     rechazo es por su contenido, y reintentarlo da el mismo no.
 *
 *   · **El registro de intentos.** Uno por intento, con lo que
 *     contestó la DIAN. El anexo obliga a archivar las evidencias del
 *     error (§12.2), y sin esto la única respuesta a «¿qué pasó?» sería
 *     el último estado.
 *
 *   · **La cadencia de reintentos**, que el anexo fija y no es una
 *     sola: 5 segundos ante error, 2 minutos ante demora.
 *
 * NO REINTENTA POR SU CUENTA
 * --------------------------
 * Hace UN intento y dice qué pasó. Quién espera y vuelve a llamar es la
 * cola —el job—, porque dormir 2 minutos dentro de una petición web es
 * exactamente lo que no hay que hacer. Aquí solo se calcula CUÁNTO hay
 * que esperar, que es la parte que depende del anexo.
 */
class DocumentTransmitter
{
    /**
     * La cadencia que fija el anexo, en segundos.
     *
     * §12.2 — ante error: reintentar a los 5 segundos, y dos veces más
     * cada 5 segundos. A los 15 segundos sin arreglo, contingencia.
     *
     * §12.4 — ante demora (la DIAN tarda más de un minuto): reintentar
     * a los 2 minutos, y cuatro veces más cada 2 minutos.
     */
    public const ESPERA_ERROR = 5;
    public const ESPERA_DEMORA = 120;

    public const INTENTOS_ERROR = 3;
    public const INTENTOS_DEMORA = 5;

    public function __construct(
        private readonly DianTransport $transporte,
    ) {
    }

    /**
     * Intenta transmitir el documento una vez.
     *
     * Devuelve el resultado, o null si no había nada que hacer —porque
     * ya estaba cerrado o porque no está en condiciones de enviarse—.
     */
    public function transmitir(ElectronicDocument $documento): ?TransmissionResult
    {
        // IDEMPOTENCIA. Va lo primero y sin excepciones: un documento
        // aceptado o rechazado no se vuelve a mandar aunque alguien
        // reencole el job, aunque la cola repita, aunque se llame dos
        // veces a la vez.
        if ($documento->estaCerrado()) {
            return null;
        }

        if (!$documento->sePuedeTransmitir()) {
            Log::warning('Se intentó transmitir un documento que no está listo', [
                'documento' => $documento->id,
                'estado' => $documento->status,
            ]);

            return null;
        }

        $intento = $documento->attempts + 1;
        $empezo = microtime(true);

        try {
            $resultado = $this->transporte->enviar($documento);
        } catch (Throwable $error) {
            // Que el transporte reviente es un error de comunicación
            // como cualquier otro: se anota y se reintenta. No puede
            // tumbar la cola.
            $resultado = TransmissionResult::error([$error->getMessage()]);
        }

        $resultado = $resultado->conDuracion((int) ((microtime(true) - $empezo) * 1000));

        $this->registrar($documento, $intento, $resultado);
        $this->aplicar($documento, $intento, $resultado);

        return $resultado;
    }

    /**
     * Cuánto hay que esperar antes del siguiente intento.
     *
     * Devuelve null cuando ya no hay que reintentar: o porque el
     * resultado fue definitivo, o porque se agotaron los intentos que
     * el anexo concede.
     */
    public function esperaHastaElSiguiente(TransmissionResult $resultado, int $intento): ?int
    {
        if ($resultado->esDefinitivo()) {
            return null;
        }

        [$maximo, $espera] = $resultado->resultado === TransmissionResult::DEMORA
            ? [self::INTENTOS_DEMORA, self::ESPERA_DEMORA]
            : [self::INTENTOS_ERROR, self::ESPERA_ERROR];

        return $intento >= $maximo ? null : $espera;
    }

    /**
     * ¿Se agotaron los intentos y toca contingencia?
     *
     * El anexo (§12.2) dice que tras el último intento fallido se
     * expide el documento SIN validación previa, con
     * `InvoiceTypeCode = 04`. Eso todavía no está implementado: aquí
     * solo se detecta la situación para poder avisar.
     */
    public function agotoLosIntentos(TransmissionResult $resultado, int $intento): bool
    {
        return $resultado->sePuedeReintentar()
            && $this->esperaHastaElSiguiente($resultado, $intento) === null;
    }

    // ==================== Lo que queda escrito ====================

    private function registrar(ElectronicDocument $documento, int $intento, TransmissionResult $resultado): void
    {
        DocumentTransmission::withoutGlobalScopes()->create([
            'electronic_document_id' => $documento->id,
            'company_id' => $documento->company_id,
            'attempt' => $intento,
            'outcome' => $resultado->resultado,
            'http_status' => $resultado->httpStatus,
            'track_id' => $resultado->trackId,
            // La respuesta puede ser enorme si la DIAN devuelve el
            // documento entero: se recorta, que para diagnosticar sobra.
            'response' => $resultado->respuesta === null
                ? null
                : mb_substr($resultado->respuesta, 0, 65000),
            'errors' => $resultado->errores ?: null,
            'duration_ms' => $resultado->duracionMs,
        ]);
    }

    private function aplicar(ElectronicDocument $documento, int $intento, TransmissionResult $resultado): void
    {
        $cambios = [
            'attempts' => $intento,
            'dian_track_id' => $resultado->trackId ?: $documento->dian_track_id,
        ];

        switch ($resultado->resultado) {
            case TransmissionResult::ACEPTADO:
                $cambios['status'] = ElectronicDocument::ACEPTADO;
                $cambios['accepted_at'] = now();
                $cambios['last_error'] = null;

                // El acuse de la DIAN: es lo que hay que entregarle al
                // adquiriente junto con la factura. Solo se pisa si
                // vino de verdad, para no borrar el de un intento
                // anterior con uno vacio.
                if ($resultado->acuse) {
                    $cambios['dian_response_xml'] = $resultado->acuse;
                }
                break;

            case TransmissionResult::RECHAZADO:
                // Se guarda el motivo: es lo que hay que corregir antes
                // de emitir el documento que lo sustituya.
                $cambios['status'] = ElectronicDocument::RECHAZADO;
                $cambios['last_error'] = implode(' | ', $resultado->errores);
                break;

            default:
                // Error o demora: el documento SIGUE FIRMADO y a la
                // espera. No se le cambia el estado, porque no le ha
                // pasado nada — lo que falló fue la comunicación.
                $cambios['last_error'] = implode(' | ', $resultado->errores);
        }

        $documento->update($cambios);
    }
}
