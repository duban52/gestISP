<?php

namespace App\Billing\Dian\Transport;

/**
 * Lo que contesto la DIAN a un envio.
 *
 * POR QUE UN OBJETO Y NO UN ARRAY
 * -------------------------------
 * Porque la diferencia entre «rechazado» y «no se pudo hablar con la
 * DIAN» decide cosas distintas —una se reintenta, la otra no— y con un
 * array esa distincion se pierde en cuanto alguien escribe
 * `$resultado['ok']`.
 *
 * LAS CUATRO RESPUESTAS POSIBLES
 * ------------------------------
 * · ACEPTADO  — validada. Es definitivo: no se vuelve a enviar.
 * · RECHAZADO — la DIAN la recibio y dijo que no. Tambien es
 *               definitivo: reintentar el mismo documento da el mismo
 *               rechazo. Hay que corregir y emitir otro.
 * · ERROR     — algo fallo hablando con ella. SE REINTENTA.
 * · DEMORA    — no contesto a tiempo. Se reintenta, pero con otra
 *               cadencia (§12.4 del anexo).
 *
 * Que el rechazo NO se reintente es lo importante: reintentar un
 * documento que la DIAN ya rechazo por su contenido es gastar intentos
 * para recibir el mismo no.
 */
class TransmissionResult
{
    public const ACEPTADO = 'accepted';
    public const RECHAZADO = 'rejected';
    public const ERROR = 'error';
    public const DEMORA = 'timeout';

    /**
     * @param  array<int, string>  $errores
     * @param  string|null  $acuse  El ApplicationResponse de la DIAN, ya
     *   desempaquetado. Es el XML que acredita que el documento fue
     *   validado, y lo que hay que entregarle al adquiriente junto con
     *   la factura. Viaja dentro de `$respuesta` en base64; se saca aqui
     *   para no tener que volver a abrir el SOAP mas adelante.
     */
    public function __construct(
        public readonly string $resultado,
        public readonly ?string $trackId = null,
        public readonly array $errores = [],
        public readonly ?int $httpStatus = null,
        public readonly ?string $respuesta = null,
        public readonly ?int $duracionMs = null,
        public readonly ?string $acuse = null,
    ) {
    }

    public static function aceptado(?string $trackId = null, ?string $respuesta = null, ?string $acuse = null): self
    {
        return new self(self::ACEPTADO, trackId: $trackId, respuesta: $respuesta, acuse: $acuse);
    }

    /** @param array<int, string> $errores */
    public static function rechazado(array $errores, ?string $trackId = null, ?string $respuesta = null): self
    {
        return new self(self::RECHAZADO, trackId: $trackId, errores: $errores, respuesta: $respuesta);
    }

    /** @param array<int, string> $errores */
    public static function error(array $errores, ?int $httpStatus = null, ?string $respuesta = null): self
    {
        return new self(self::ERROR, errores: $errores, httpStatus: $httpStatus, respuesta: $respuesta);
    }

    public static function demora(?int $duracionMs = null): self
    {
        return new self(self::DEMORA, errores: ['La DIAN no respondió a tiempo.'], duracionMs: $duracionMs);
    }

    /** ¿Hay algo mas que hacer con este documento? */
    public function esDefinitivo(): bool
    {
        return in_array($this->resultado, [self::ACEPTADO, self::RECHAZADO], true);
    }

    public function sePuedeReintentar(): bool
    {
        return !$this->esDefinitivo();
    }

    public function conDuracion(int $milisegundos): self
    {
        return new self(
            $this->resultado,
            $this->trackId,
            $this->errores,
            $this->httpStatus,
            $this->respuesta,
            $milisegundos,
        );
    }
}
