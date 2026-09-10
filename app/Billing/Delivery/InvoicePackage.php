<?php

namespace App\Billing\Delivery;

use App\Billing\Dian\AttachedDocumentBuilder;
use App\Models\DianCertificate;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * El paquete que se le entrega al adquiriente de una factura
 * electrónica.
 *
 * QUÉ LLEVA
 * ---------
 * · El `AttachedDocument`: el envoltorio estándar que lleva DENTRO la
 *   factura firmada y el acuse con que la DIAN la validó. Es lo que
 *   mandan los proveedores tecnológicos y lo que los sistemas contables
 *   cargan de un tirón.
 * · El PDF. Es lo único que la mayoría de la gente va a abrir.
 *
 * Entregar solo el PDF sería entregar la foto en vez del documento: el
 * documento fiscal es el XML.
 *
 * SI EL CONTENEDOR NO SE PUEDE ARMAR, SE MANDAN LOS SUELTOS
 * ---------------------------------------------------------
 * Necesita el certificado —va firmado— y el acuse de la DIAN. Un
 * documento aceptado ANTES de que se empezara a guardar el acuse no
 * tiene con qué; tampoco lo hay si el certificado caducó.
 *
 * En ese caso el cliente recibe los dos XML sueltos, que llevan
 * exactamente la misma información. Es preferible a no entregar nada, y
 * a que la entrega dependa de que el certificado esté vigente.
 *
 * EN MEMORIA, NO EN DISCO
 * -----------------------
 * El ZIP se arma y se devuelve; no queda ningún archivo. Todo lo que
 * contiene se puede rehacer a partir de la base, y guardar una copia por
 * factura sería acumular para siempre sin ganar nada.
 */
class InvoicePackage
{
    public function __construct(
        private readonly InvoicePdf $pdf,
        private readonly AttachedDocumentBuilder $contenedor,
    ) {
    }

    /** ¿Hay con qué armar el paquete de esta factura? */
    public function disponiblePara(Invoice $factura): bool
    {
        return $this->documentoDe($factura) !== null;
    }

    /** El nombre del ZIP que ve el cliente. */
    public function nombre(Invoice $factura): string
    {
        return 'factura-' . $factura->displayNumber() . '.zip';
    }

    /**
     * El ZIP, en bytes.
     *
     * @throws RuntimeException si la factura no es electrónica o su
     *   documento todavía no existe. Quien llame debe haber preguntado
     *   antes con `disponiblePara()`.
     */
    public function bytes(Invoice $factura): string
    {
        $documento = $this->documentoDe($factura);

        if (!$documento) {
            throw new RuntimeException(
                'La factura ' . $factura->displayNumber() . ' no tiene documento electrónico: no hay paquete que armar.',
            );
        }

        // ZipArchive solo trabaja contra un archivo. Se usa el temporal
        // del sistema y se borra siempre —también si algo revienta—,
        // para no dejar facturas ajenas tiradas en el disco.
        $ruta = tempnam(sys_get_temp_dir(), 'factura');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del paquete.');
        }

        try {
            $zip = new ZipArchive();

            if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('No se pudo abrir el ZIP del paquete.');
            }

            $base = $factura->displayNumber();
            $adjunto = $this->attachedDocument($documento);

            if ($adjunto) {
                $zip->addFromString('ad-' . $base . '.xml', $adjunto);
            } else {
                // Sin contenedor, los dos XML sueltos: llevan la misma
                // informacion y el cliente no se queda sin nada.
                $zip->addFromString($base . '.xml', (string) $documento->signed_xml);

                if ($documento->dian_response_xml) {
                    $zip->addFromString($base . '-respuesta-dian.xml', $documento->dian_response_xml);
                }
            }

            $zip->addFromString($this->pdf->nombre($factura), $this->pdf->bytes($factura));
            $zip->close();

            $bytes = file_get_contents($ruta);

            if ($bytes === false) {
                throw new RuntimeException('El paquete se armó pero no se pudo leer.');
            }

            return $bytes;
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * El `AttachedDocument` firmado, o null si no se puede armar.
     *
     * NO REVIENTA SI FALLA. Que el contenedor no salga —certificado
     * caducado, acuse ausente, cualquier cosa— no puede dejar al cliente
     * sin su factura: se anota y se entregan los XML sueltos.
     */
    private function attachedDocument(ElectronicDocument $documento): ?string
    {
        if (!$this->contenedor->disponiblePara($documento)) {
            return null;
        }

        $certificado = DianCertificate::withoutGlobalScopes()
            ->where('company_id', $documento->company_id)
            ->where('active', true)
            ->get()
            ->first(fn (DianCertificate $c) => $c->vigente());

        if (!$certificado) {
            return null;
        }

        try {
            return $this->contenedor->construir(
                $documento,
                $certificado->contenido(),
                (string) $certificado->password,
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo armar el AttachedDocument; se entregan los XML sueltos.', [
                'documento' => $documento->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * El documento electrónico de la factura, si lo tiene y está firmado.
     *
     * `withoutGlobalScopes` porque esto corre desde la cola y desde la
     * descarga pública, donde no hay contexto de empresa establecido.
     */
    private function documentoDe(Invoice $factura): ?ElectronicDocument
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->whereNotNull('signed_xml')
            ->latest('id')
            ->first();
    }
}
