<?php

namespace App\Listeners;

use App\Billing\Dian\ElectronicDocumentGenerator;
use App\Billing\Events\InvoiceIssued;

/**
 * Al emitir una factura electrónica, se genera su documento DIAN.
 *
 * POR QUÉ AQUÍ Y NO DENTRO DE InvoiceGenerator
 * --------------------------------------------
 * `InvoiceIssued` se describía desde el principio como «el punto de
 * enganche de la facturación electrónica». Engancharse aquí hace que
 * cubra por igual la corrida mensual y la emisión individual, sin que
 * el servicio de facturación tenga que saber nada de la DIAN.
 *
 * NO PUEDE FALLAR HACIA ARRIBA
 * ----------------------------
 * El evento se dispara FUERA de la transacción que crea la factura, así
 * que una excepción aquí no deshace la factura — pero sí subiría hasta
 * quien la emitió y reventaría la corrida mensual a mitad.
 *
 * Por eso el generador se traga sus propios errores y los anota en el
 * documento. Este listener no añade try/catch encima: si algún día algo
 * se escapa, es un fallo de programación y debe verse, no esconderse.
 */
class GenerateElectronicDocument
{
    public function __construct(
        private readonly ElectronicDocumentGenerator $generador,
    ) {
    }

    public function handle(InvoiceIssued $event): void
    {
        // Devuelve null cuando la factura es interna, que es la
        // mayoría: no es un error y no hay nada que hacer.
        $this->generador->generar($event->invoice);
    }
}
