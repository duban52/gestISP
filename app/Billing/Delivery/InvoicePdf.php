<?php

namespace App\Billing\Delivery;

use App\Billing\Dian\GraphicRepresentation;
use App\Models\Invoice;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Milon\Barcode\Facades\DNS1DFacade;

/**
 * La representación gráfica de una factura, en bytes.
 *
 * POR QUÉ EXISTE ESTA CLASE
 * -------------------------
 * Porque esto vivía dentro de `InvoiceController::downloadInvoicePdf()`,
 * y desde ahí solo se podía producir un PDF si había alguien pulsando un
 * botón. Ahora la factura también se le envía al cliente por correo y
 * por WhatsApp, y eso ocurre en una cola, sin petición HTTP: el código
 * de barras, el bloque DIAN y el tamaño del papel tenían que salir del
 * controlador para poder invocarse desde las dos partes.
 *
 * EL LOGO Y EL CÓDIGO DE BARRAS NO VIAJAN POR URL
 * -----------------------------------------------
 * El logo se pasa como RUTA ABSOLUTA DE DISCO —`PdfBranding::logoPath()`,
 * que ya hacía esto para el resto de informes—. Cargarlo con `asset()`
 * obliga a que el servidor pueda pedirse a sí mismo por HTTP; si el
 * dominio no resuelve desde dentro, el logo sale roto y nadie se entera
 * hasta que un cliente recibe la factura sin él.
 *
 * Y EL CÓDIGO DE BARRAS, EMBEBIDO
 * -------------------------------
 * Antes se escribía un PNG en `storage/app/public/barcodes/` y se
 * enlazaba con `asset()`, lo que obligaba a `isRemoteEnabled` y a que el
 * servidor pudiera pedirse a sí mismo por HTTP. Desde un worker en cola
 * eso es exactamente lo que falla —y además dejaba un archivo por
 * factura para siempre—. Va como `data:` dentro del propio PDF: mismo
 * dibujo, sin archivos y sin red.
 */
class InvoicePdf
{
    public function __construct(
        private readonly GraphicRepresentation $representacion,
    ) {
    }

    /** El PDF listo para adjuntar o descargar. */
    public function bytes(Invoice $factura): string
    {
        return $this->documento($factura)->output();
    }

    /** El nombre con el que se le entrega al cliente. */
    public function nombre(Invoice $factura): string
    {
        return 'factura-' . $factura->displayNumber() . '.pdf';
    }

    /**
     * El PDF ya renderizado, por si hace falta el lienzo.
     *
     * Lo usan las pruebas para contar páginas; el resto de la
     * aplicación debería quedarse con `bytes()`.
     */
    public function documento(Invoice $factura)
    {
        $factura->loadMissing(['contract.client', 'contract.branch', 'invoice_items']);

        $codigo = $this->codigoDeBarras($factura);

        // Null si la factura es interna, que es la mayoría: entonces el
        // bloque DIAN no se pinta y el PDF sale sin QR ni CUFE.
        $dian = $this->representacion->para($factura);

        $pdf = Pdf::loadView('gestisp.invoices.pdf', [
            'invoice' => $factura,
            'barcodeUrl' => $this->barrasEmbebidas($codigo),
            'codeString' => $codigo,
            'dian' => $dian,
            'logo' => PdfBranding::logoPath($factura->contract?->branch),
        ]);

        $pdf->setPaper(PdfBranding::MEDIA_CARTA, 'portrait');
        $pdf->render();

        return $pdf;
    }

    /**
     * Lo que codifica el código de barras del talón.
     *
     * PENDIENTE: el formato lo fija la red de recaudo, no nosotros. Este
     * es el que había —`0100` + id interno de la factura + total en
     * centavos— y se mantiene tal cual hasta tener confirmado el que
     * esperan. Cambiarlo a ciegas es peor que dejarlo.
     */
    private function codigoDeBarras(Invoice $factura): string
    {
        return '0100'
            . str_pad((string) $factura->id, 8, '0', STR_PAD_LEFT)
            . str_pad((string) (int) round((float) $factura->total * 100), 10, '0', STR_PAD_LEFT);
    }

    /** El PNG del código de barras, como `data:` para meterlo en el PDF. */
    private function barrasEmbebidas(string $codigo): string
    {
        return 'data:image/png;base64,' . DNS1DFacade::getBarcodePNG($codigo, 'C128');
    }
}
