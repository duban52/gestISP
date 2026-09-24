<?php

namespace App\Billing\Dian\Transport;

/**
 * Como se manda el SET DE PRUEBAS de la habilitacion.
 *
 * POR QUE UNA INTERFAZ APARTE Y NO UN METODO MAS EN DianTransport
 * --------------------------------------------------------------
 * Porque es otra conversacion. El envio de todos los dias es
 * `SendBillSync`: un documento, respuesta inmediata, aceptado o
 * rechazado. El set de pruebas es `SendTestSetAsync`: VARIOS documentos
 * en un mismo ZIP, con el identificador del set, y la respuesta no es
 * el resultado sino un acuse —una `ZipKey`— que hay que consultar
 * despues.
 *
 * Meterlo en la misma interfaz obligaria a todo lo que sepa transmitir a
 * saber tambien de habilitacion, que es una cosa que se hace UNA vez en
 * la vida de una empresa.
 */
interface DianTestSetTransport
{
    /**
     * Manda un lote de documentos como set de pruebas.
     *
     * @param  array<string, string>  $documentos  nombre de archivo => XML firmado
     * @param  string  $testSetId  El identificador que asigna la DIAN
     */
    public function enviarSetDePruebas(array $documentos, string $testSetId): TransmissionResult;

    /**
     * Le pregunta a la DIAN como quedo un set ya entregado.
     *
     * Es `GetStatusZip`, y se presenta la `ZipKey` que devolvio el
     * envio. La respuesta trae el veredicto del lote y, dentro, el de
     * cada documento: cual paso y cual no, y por que. Sin esto, «set
     * entregado» es todo lo que se sabe, y corregir un rechazo se
     * convierte en adivinar cual de los diez documentos fallo.
     *
     * @param  string|null  $endpointOverride  Si la empresa transmite
     *   por un proveedor tecnologico.
     */
    public function consultarSet(string $zipKey, ?string $endpointOverride = null): TransmissionResult;
}
