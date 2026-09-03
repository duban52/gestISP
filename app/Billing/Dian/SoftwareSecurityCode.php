<?php

namespace App\Billing\Dian;

/**
 * La huella del software que produjo la factura.
 *
 * QUÉ ES
 * ------
 * Un SHA-384 sobre tres datos, dos de ellos **secretos**: el
 * identificador que la DIAN asigna al software cuando se activa, el PIN
 * que el facturador eligió en ese momento, y el número del documento.
 *
 * El anexo técnico 1.9 (§11.8) lo dice así:
 *
 *     SoftwareSecurityCode := SHA-384 (Id Software + Pin + NroDocumentos)
 *
 * donde `NroDocumentos` es el `cbc:ID` del documento —el número
 * completo, prefijo incluido: SETP990000001—.
 *
 * PARA QUÉ SIRVE
 * --------------
 * Es lo que permite a la DIAN saber que la factura la produjo el
 * software que ella autorizó, y no otro. Por eso el PIN y el
 * identificador **no viajan nunca** en el XML: viaja solo este hash. El
 * propio anexo insiste en que hay que mantenerlos en reserva.
 *
 * En el sistema eso se traduce en que `software_pin` está cifrado en la
 * base (cast `encrypted` de DianConfiguration) y no se enseña en
 * ninguna pantalla.
 *
 * A DIFERENCIA DEL CUFE, NO SE PUEDE VERIFICAR
 * --------------------------------------------
 * El anexo publica un ejemplo resuelto del CUFE, así que aquel se pudo
 * comprobar contra la propia DIAN. De este no publica ninguno —no
 * podría, sin revelar un PIN—, así que lo único que se puede defender
 * en pruebas es la forma: que sean 96 caracteres, que la concatenación
 * sea la del anexo y que cambiar cualquiera de los tres datos cambie el
 * resultado.
 */
class SoftwareSecurityCode
{
    /**
     * Calcula el código de seguridad del software.
     *
     * @param  string  $softwareId       El que asignó la DIAN al activar el software
     * @param  string  $pin              El que eligió el facturador en ese momento
     * @param  string  $numeroDocumento  El cbc:ID completo, con prefijo (SETP990000001)
     */
    public function calcular(string $softwareId, string $pin, string $numeroDocumento): string
    {
        return hash('sha384', $this->cadena($softwareId, $pin, $numeroDocumento));
    }

    /**
     * La cadena antes del hash.
     *
     * Se expone por la misma razón que en el CUFE: cuando el código no
     * cuadra, comparar dos hashes no dice nada; hay que poder ver qué se
     * concatenó. Con la diferencia de que esta cadena **lleva secretos
     * dentro**, así que no debe registrarse en ningún log ni enseñarse
     * en pantalla — solo sirve para depurar en el momento.
     */
    public function cadena(string $softwareId, string $pin, string $numeroDocumento): string
    {
        return $softwareId . $pin . $numeroDocumento;
    }
}
