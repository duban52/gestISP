<?php

namespace App\Http\Controllers;

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
 * La ruta va con el middleware `signed`: la URL lleva una firma hecha
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
    public function __invoke(int $invoice, InvoicePdf $pdf): StreamedResponse
    {
        $factura = Invoice::withoutGlobalScopes()
            ->with(['contract.client', 'contract.branch', 'invoice_items'])
            ->findOrFail($invoice);

        return response()->streamDownload(
            fn () => print $pdf->bytes($factura),
            $pdf->nombre($factura),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
