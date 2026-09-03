<?php

namespace App\Billing\Dian\Transport;

use App\Models\ElectronicDocument;

/**
 * Como se le manda un documento a la DIAN.
 *
 * POR QUE UNA INTERFAZ
 * --------------------
 * Porque de las tres cosas que hacen falta para hablar con la DIAN
 * —la URL del servicio, el certificado y la habilitacion— hoy no
 * tenemos ninguna confirmada: la URL la expone la DIAN dentro de la
 * cuenta del catalogo de cada facturador, no en su documentacion.
 *
 * Con una interfaz, todo lo que rodea al envio —los reintentos, los
 * estados, la idempotencia, el registro de intentos— se puede construir
 * y probar HOY, y el dia que haya credenciales solo se enchufa la
 * implementacion de verdad. Sin ella habria que esperar a tener
 * credenciales para escribir la primera linea.
 *
 * Ademas deja la puerta abierta a lo otro: si algun dia se decide pasar
 * por un proveedor tecnologico en vez de emitir directo, es otra
 * implementacion de esto mismo y nada mas cambia.
 */
interface DianTransport
{
    /**
     * Manda el documento y devuelve lo que contesto la DIAN.
     *
     * NO lanza excepciones por un rechazo ni por un error de red: eso
     * son respuestas, y quien llama tiene que poder distinguirlas para
     * decidir si reintenta. Solo lanza si el documento ni siquiera esta
     * en condiciones de enviarse.
     */
    public function enviar(ElectronicDocument $documento): TransmissionResult;

    /** Un nombre legible, para el registro de intentos. */
    public function nombre(): string;
}
