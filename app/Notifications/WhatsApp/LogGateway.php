<?php

namespace App\Notifications\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor simulado de WhatsApp.
 *
 * No envía nada real: escribe el mensaje en el log. Es el driver por
 * defecto, para que todo el sistema funcione de punta a punta en
 * desarrollo y antes de contratar/aprobar el proveedor real. Cuando
 * estén las credenciales de Meta, se cambia WHATSAPP_DRIVER a "meta".
 */
class LogGateway implements WhatsAppGateway
{
    public function send(string $to, WhatsAppMessage $message): bool
    {
        Log::channel(config('logging.default'))->info('[WhatsApp simulado]', [
            'para' => $to,
            'plantilla' => $message->templateName,
            'parametros' => $message->templateParams,
            'texto' => $message->body,
        ]);

        return true;
    }
}
