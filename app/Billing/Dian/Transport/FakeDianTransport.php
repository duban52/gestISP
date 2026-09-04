<?php

namespace App\Billing\Dian\Transport;

use App\Models\ElectronicDocument;

/**
 * Un transporte que no habla con nadie.
 *
 * PARA QUE SIRVE
 * --------------
 * Para dos cosas, y las dos importan:
 *
 * 1. **Las pruebas.** Toda la maquinaria de la fase 11 —reintentos,
 *    estados, idempotencia, registro de intentos— se puede probar de
 *    verdad sin credenciales de la DIAN, programando aqui la respuesta
 *    que se quiere provocar.
 *
 * 2. **Trabajar mientras no haya habilitacion.** Es el transporte por
 *    defecto: mientras la empresa no tenga su URL y su certificado, los
 *    documentos se generan, se firman y se «transmiten» sin salir de
 *    casa. Nada se manda a ninguna parte.
 *
 * POR DEFECTO NO ACEPTA NADA
 * --------------------------
 * Devuelve un ERROR diciendo que no hay transporte configurado, y no un
 * «aceptado» de mentira. Un aceptado falso dejaria documentos marcados
 * como validados por la DIAN que la DIAN no ha visto nunca — que es
 * exactamente la clase de mentira que no puede vivir en una tabla
 * fiscal.
 */
class FakeDianTransport implements DianTransport, DianTestSetTransport
{
    /** @var array<int, TransmissionResult> */
    private array $respuestas = [];

    /** @var array<int, ElectronicDocument> */
    public array $enviados = [];

    /** Programa la siguiente respuesta (se consumen en orden). */
    public function responder(TransmissionResult ...$respuestas): self
    {
        foreach ($respuestas as $respuesta) {
            $this->respuestas[] = $respuesta;
        }

        return $this;
    }

    public function enviar(ElectronicDocument $documento): TransmissionResult
    {
        $this->enviados[] = $documento;

        return array_shift($this->respuestas) ?? TransmissionResult::error([
            'No hay ningún transporte a la DIAN configurado: '
            . 'falta la URL del servicio, que la DIAN expone en el catálogo del facturador.',
        ]);
    }

    /** @var array<int, array{documentos: array<string, string>, testSetId: string}> */
    public array $setsEnviados = [];

    /** @param array<string, string> $documentos */
    public function enviarSetDePruebas(array $documentos, string $testSetId): TransmissionResult
    {
        $this->setsEnviados[] = ['documentos' => $documentos, 'testSetId' => $testSetId];

        return array_shift($this->respuestas) ?? TransmissionResult::error([
            'No hay ningún transporte a la DIAN configurado: no se puede mandar el set de pruebas.',
        ]);
    }

    public function nombre(): string
    {
        return 'simulado';
    }

    /** Cuantas veces se llamo. */
    public function veces(): int
    {
        return count($this->enviados);
    }
}
