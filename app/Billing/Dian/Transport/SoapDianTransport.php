<?php

namespace App\Billing\Dian\Transport;

use App\Models\DianCertificate;
use App\Models\ElectronicDocument;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El transporte real: SOAP contra el servicio de la DIAN.
 *
 * ⚠️ NO ESTÁ VERIFICADO CONTRA EL SERVICIO REAL
 * ---------------------------------------------
 * Y no puede estarlo todavía. Lo que se sabe está sacado de la «Guía
 * Herramienta para el Consumo de Web Services» de la DIAN y de los
 * ejemplos del paquete oficial; lo que NO se sabe es la URL del
 * servicio, porque **la DIAN no la publica en su documentación**: la
 * expone dentro de la cuenta del catálogo de cada facturador
 * (Participants → Facturador).
 *
 * Por eso la URL es configuración y no una constante, y por eso el
 * transporte por defecto es el simulado. El día que haya credenciales,
 * esto se configura y se prueba de verdad; hasta entonces, todo lo que
 * lo rodea —reintentos, estados, idempotencia— ya está construido y
 * probado con `FakeDianTransport`.
 *
 * LO QUE SÍ SE SABE, Y ESTÁ AQUÍ
 * ------------------------------
 * · Es **SOAP 1.2**: la acción va como parámetro `action` dentro de la
 *   cabecera `Content-Type`, no en `SOAPAction`.
 * · La autenticación es **WS-Security con firma X.509**, usando el
 *   MISMO certificado con el que se firma la factura.
 * · Lleva **WS-Addressing** (`wsa:To`, `wsa:Action`) y un
 *   **Timestamp** con vigencia.
 * · La interfaz se llama `IWcfDianCustomerServices` y el método de
 *   envío es **`SendBillSync`** (anexo §12.1).
 *
 * POR QUÉ NO SE USA ext-soap
 * --------------------------
 * Porque no está instalada (`php -m` no la trae) y porque, aunque lo
 * estuviera, `SoapClient` no firma con WS-Security: habría que
 * interceptar y reescribir el sobre igual. Se arma a mano sobre HTTP,
 * que además deja ver exactamente qué se envía.
 */
class SoapDianTransport implements DianTransport
{
    private const NS_SOAP = 'http://www.w3.org/2003/05/soap-envelope';
    private const NS_WCF = 'http://wcf.dian.colombia';
    private const ACCION_ENVIO = 'http://wcf.dian.colombia/IWcfDianCustomerServices/SendBillSync';

    public function __construct(
        private readonly XmlSecuritySigner $seguridad = new XmlSecuritySigner(),
    ) {
    }

    public function nombre(): string
    {
        return 'soap';
    }

    public function enviar(ElectronicDocument $documento): TransmissionResult
    {
        $url = (string) config('dian.endpoint');

        if ($url === '') {
            return TransmissionResult::error([
                'No hay URL del servicio de la DIAN configurada (DIAN_ENDPOINT). '
                . 'La expone la propia DIAN en el catálogo del facturador.',
            ]);
        }

        $certificado = $documento->certificate;

        if (!$certificado) {
            return TransmissionResult::error([
                'El documento no tiene certificado: no se puede autenticar contra la DIAN.',
            ]);
        }

        $sobre = $this->sobre($documento, $url, $certificado);

        try {
            // SOAP 1.2: la accion va DENTRO del Content-Type, no en una
            // cabecera SOAPAction. Y va como segundo argumento de
            // withBody y no en withHeaders, porque withBody PISA el
            // Content-Type que se haya puesto antes — asi se perdia el
            // action y la DIAN no sabria que metodo se le esta pidiendo.
            $tipo = 'application/soap+xml;charset=UTF-8;action="' . self::ACCION_ENVIO . '"';

            $respuesta = Http::timeout((int) config('dian.timeout', 60))
                ->withBody($sobre, $tipo)
                ->post($url);
        } catch (\Illuminate\Http\Client\ConnectionException $error) {
            // Sin respuesta a tiempo: es la «demora declarada» del
            // §12.4, que tiene su propia cadencia de reintentos.
            return TransmissionResult::demora();
        }

        return $this->interpretar($respuesta->status(), $respuesta->body());
    }

    /**
     * Arma el sobre SOAP, firmado con WS-Security.
     */
    private function sobre(ElectronicDocument $documento, string $url, DianCertificate $certificado): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');

        $sobre = $doc->createElementNS(self::NS_SOAP, 'soap:Envelope');
        $sobre->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wcf', self::NS_WCF);
        $doc->appendChild($sobre);

        // ---- Cabecera: direccionamiento y seguridad ----
        $cabecera = $doc->createElementNS(self::NS_SOAP, 'soap:Header');
        $sobre->appendChild($cabecera);

        $direccionamiento = 'http://www.w3.org/2005/08/addressing';
        $accion = $doc->createElementNS($direccionamiento, 'wsa:Action', self::ACCION_ENVIO);
        $destino = $doc->createElementNS($direccionamiento, 'wsa:To', $url);
        $destino->setAttribute('Id', 'to');
        $cabecera->appendChild($accion);
        $cabecera->appendChild($destino);

        // ---- Cuerpo: el documento, comprimido y en base64 ----
        //
        // La DIAN recibe el XML dentro de un ZIP en base64, no el XML
        // suelto. Es lo que hace el metodo SendBillSync.
        $cuerpo = $doc->createElementNS(self::NS_SOAP, 'soap:Body');
        $sobre->appendChild($cuerpo);

        $envio = $doc->createElementNS(self::NS_WCF, 'wcf:SendBillSync');
        $cuerpo->appendChild($envio);

        $envio->appendChild($doc->createElementNS(
            self::NS_WCF,
            'wcf:fileName',
            $documento->cufe . '.xml',
        ));

        $contenido = $doc->createElementNS(self::NS_WCF, 'wcf:contentFile');
        $contenido->appendChild($doc->createTextNode($this->comprimir($documento)));
        $envio->appendChild($contenido);

        // La firma WS-Security va al final, cuando el sobre ya esta
        // armado: firma la cabecera de direccionamiento y el cuerpo.
        return $this->seguridad->firmar($doc, $certificado);
    }

    /**
     * El XML firmado, dentro de un ZIP y en base64.
     *
     * Es como lo quiere SendBillSync. Se usa ZipArchive sobre un
     * temporal porque no hay forma de armar un zip en memoria con la
     * extension estandar.
     */
    private function comprimir(ElectronicDocument $documento): string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'dian');

        try {
            $zip = new \ZipArchive();

            if ($zip->open($temporal, \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo preparar el archivo para enviar a la DIAN.');
            }

            $zip->addFromString($documento->cufe . '.xml', (string) $documento->signed_xml);
            $zip->close();

            return base64_encode((string) file_get_contents($temporal));
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Traduce la respuesta de la DIAN a uno de los cuatro resultados.
     *
     * El `IsValid` es lo que decide: la DIAN puede contestar 200 y
     * haber rechazado el documento. Tratar cualquier 200 como aceptado
     * es el error que deja documentos marcados como validados que no lo
     * estan.
     */
    private function interpretar(int $estado, string $cuerpo): TransmissionResult
    {
        if ($estado >= 500) {
            return TransmissionResult::error(
                ['La DIAN respondió con un error ' . $estado . '.'],
                httpStatus: $estado,
                respuesta: $cuerpo,
            );
        }

        if ($estado >= 400) {
            return TransmissionResult::error(
                ['La petición fue rechazada con un ' . $estado . ' (revise el certificado y la URL).'],
                httpStatus: $estado,
                respuesta: $cuerpo,
            );
        }

        $valores = $this->extraer($cuerpo);

        if ($valores['valido'] === true) {
            return new TransmissionResult(
                TransmissionResult::ACEPTADO,
                trackId: $valores['trackId'],
                httpStatus: $estado,
                respuesta: $cuerpo,
            );
        }

        // Contestó, y dijo que no: es un rechazo, no un error de
        // comunicación. No se reintenta.
        return new TransmissionResult(
            TransmissionResult::RECHAZADO,
            trackId: $valores['trackId'],
            errores: $valores['errores'] ?: ['La DIAN rechazó el documento sin detallar el motivo.'],
            httpStatus: $estado,
            respuesta: $cuerpo,
        );
    }

    /**
     * Saca de la respuesta lo que hace falta.
     *
     * Se lee con XPath sin espacios de nombres porque la DIAN ha
     * cambiado los suyos entre versiones y atarse a ellos hace que una
     * respuesta perfectamente buena se lea como vacía.
     *
     * @return array{valido: ?bool, trackId: ?string, errores: array<int, string>}
     */
    private function extraer(string $cuerpo): array
    {
        $anterior = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $leido = $doc->loadXML($cuerpo);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$leido) {
            return ['valido' => null, 'trackId' => null, 'errores' => ['La respuesta de la DIAN no es un XML válido.']];
        }

        $xpath = new \DOMXPath($doc);

        $texto = fn (string $nombre) => $xpath
            ->query("//*[local-name()='{$nombre}']")
            ->item(0)?->nodeValue;

        $errores = [];

        foreach ($xpath->query("//*[local-name()='ErrorMessage']/*[local-name()='string']") as $nodo) {
            $errores[] = trim($nodo->nodeValue);
        }

        $valido = $texto('IsValid');

        return [
            'valido' => $valido === null ? null : filter_var($valido, FILTER_VALIDATE_BOOLEAN),
            'trackId' => $texto('XmlDocumentKey') ?? $texto('TrackId'),
            'errores' => $errores,
        ];
    }
}
