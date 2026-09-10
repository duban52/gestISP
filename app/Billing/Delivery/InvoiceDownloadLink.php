<?php

namespace App\Billing\Delivery;

use App\Models\Invoice;
use Illuminate\Support\Facades\URL;

/**
 * El enlace con el que el cliente descarga su factura.
 *
 * POR QUÉ UN ENLACE Y NO UN ADJUNTO
 * ---------------------------------
 * Por WhatsApp. Meta admite mandar documentos, pero exige subir el
 * archivo a sus servidores o exponerlo en una URL pública, más una
 * plantilla aprobada con cabecera de documento. Un enlace evita las tres
 * cosas y no obliga a guardar archivos en ningún sitio: el PDF se rehace
 * al abrirlo.
 *
 * POR QUÉ FIRMADO Y TEMPORAL
 * --------------------------
 * El destinatario no es un usuario del sistema —es el cliente del ISP—,
 * así que la ruta no puede pedir sesión. Lo que la protege es la firma:
 * sin ella la URL no vale, y la firma no se puede fabricar sin la
 * `APP_KEY`.
 *
 * Aun así, **quien tenga el enlace ve esa factura**. Eso es inherente:
 * el mismo problema tiene un PDF adjunto reenviado. Por eso caduca —30
 * días por defecto— y por eso apunta a UNA factura y no a un listado.
 *
 * OJO CON `APP_URL`
 * -----------------
 * El enlace se arma desde la cola, sin petición HTTP, así que el dominio
 * sale de `APP_URL`. Si está mal, los enlaces salen mal y nadie se entera
 * hasta que un cliente lo dice.
 */
class InvoiceDownloadLink
{
    /** Cuántos días vive el enlace. */
    public function diasDeVigencia(): int
    {
        return (int) config('notifications.invoice_link_days', 30);
    }

    /** La URL firmada para descargar el PDF de esta factura. */
    public function para(Invoice $factura): string
    {
        return URL::temporarySignedRoute(
            'invoices.public_pdf',
            now()->addDays($this->diasDeVigencia()),
            ['invoice' => $factura->id],
        );
    }
}
