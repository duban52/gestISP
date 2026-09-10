<?php

namespace App\Billing\Events;

use App\Models\ElectronicDocument;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La DIAN validó un documento electrónico.
 *
 * POR QUÉ HACE FALTA ESTE EVENTO, HABIENDO YA `InvoiceIssued`
 * -----------------------------------------------------------
 * Porque `InvoiceIssued` dispara al NUMERAR la factura — antes de
 * transmitirla. En ese instante la factura todavía no está validada y el
 * acuse de la DIAN no existe: no hay paquete que entregar.
 *
 * Este es el momento en que sí lo hay. Es también el único punto del
 * sistema en que una factura pasa de «emitida» a «con valor fiscal
 * pleno», y por eso merece un evento propio en vez de que el transmisor
 * llame directamente a una notificación.
 *
 * SIRVE PARA FACTURAS Y PARA NOTAS
 * --------------------------------
 * Lleva el `ElectronicDocument`, no la factura: el mismo evento cubre
 * las notas crédito y débito, que también se transmiten y también hay
 * que entregarle al adquiriente.
 */
class ElectronicDocumentAccepted
{
    use Dispatchable;

    public function __construct(public readonly ElectronicDocument $document)
    {
    }
}
