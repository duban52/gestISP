<?php

namespace App\Http\Controllers;

use App\Billing\Delivery\InvoicePackage;
use App\Billing\Delivery\InvoicePdf;
use App\Models\Invoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de la factura por parte del CLIENTE, sin sesión.
 *
 * Es la única entrada del sistema que atiende a alguien que no es un
 * usuario: el cliente del ISP recibe un enlace por WhatsApp y descarga
 * su factura. Por eso no lleva `auth`.
 *
 * LO QUE LA PROTEGE ES LA FIRMA
 * -----------------------------
 * Las rutas van con el middleware `signed`: la URL lleva una firma hecha
 * con la `APP_KEY` y una fecha de caducidad, y Laravel rechaza con 403
 * cualquier cosa que no cuadre. No se puede cambiar el número de la
 * factura en la barra del navegador para ver la de otro — eso
 * invalidaría la firma.
 *
 * SIN ALCANCE DE EMPRESA, Y A PROPÓSITO
 * -------------------------------------
 * `SetCompanyContext` se desentiende cuando no hay usuario, así que el
 * alcance global de empresa no está activo aquí. Se busca la factura con
 * `withoutGlobalScopes()` para que eso sea una decisión escrita y no una
 * coincidencia: si mañana el alcance se activara por defecto, esta
 * pantalla dejaría de funcionar en silencio.
 *
 * No hay fuga: quien llega aquí ya demostró tener un enlace firmado para
 * ESA factura concreta.
 */
class PublicInvoiceDownloadController extends Controller
{
    /** La representación gráfica. Es lo que se le manda a una factura interna. */
    public function pdf(int $invoice, InvoicePdf $pdf): StreamedResponse
    {
        $factura = $this->factura($invoice);

        // Se arma ANTES de devolver la respuesta. Dentro del cierre, un
        // fallo al renderizar ocurre con las cabeceras ya enviadas: el
        // cliente —que llega aqui desde el enlace de su WhatsApp— ve un
        // ERR_INVALID_RESPONSE del navegador en lugar de una pagina de
        // error, y en el log no queda nada que lo explique.
        $bytes = $pdf->bytes($factura);

        return response()->streamDownload(
            fn () => print $bytes,
            $pdf->nombre($factura),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * El paquete completo de una factura electrónica: XML, acuse y PDF.
     *
     * Si la factura no tiene documento electrónico —porque es interna, o
     * porque todavía no se ha emitido— se responde 404 en vez de
     * entregar un ZIP a medias. Un enlace que devuelve un paquete
     * incompleto es peor que uno que dice que no hay nada.
     */
    public function paquete(int $invoice, InvoicePackage $paquete): StreamedResponse
    {
        $factura = $this->factura($invoice);

        abort_unless($paquete->disponiblePara($factura), 404);

        // Fuera del cierre, por lo mismo que en pdf().
        $bytes = $paquete->bytes($factura);

        return response()->streamDownload(
            fn () => print $bytes,
            $paquete->nombre($factura),
            ['Content-Type' => 'application/zip'],
        );
    }

    private function factura(int $id): Invoice
    {
        return Invoice::withoutGlobalScopes()
            ->with(['contract.client', 'client', 'contract.branch', 'invoice_items'])
            ->findOrFail($id);
    }
}
