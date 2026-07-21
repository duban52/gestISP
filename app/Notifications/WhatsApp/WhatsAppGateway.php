<?php

namespace App\Notifications\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;

/**
 * Contrato de un proveedor de envío de WhatsApp.
 *
 * Cambiar de proveedor (Meta, Twilio, un gateway local...) es
 * escribir otra implementación de esta interfaz y apuntar la
 * configuración a ella. El resto del sistema —notificaciones,
 * canal, disparadores— no cambia.
 */
interface WhatsAppGateway
{
    /**
     * Envía un mensaje a un número ya normalizado (E.164 sin "+").
     *
     * Devuelve true si el proveedor aceptó el mensaje. No lanza
     * excepción ante un fallo de red o del proveedor: registra el
     * problema y devuelve false, para que un WhatsApp caído nunca
     * tumbe el flujo de negocio (crear una orden, generar una
     * factura) que lo disparó.
     */
    public function send(string $to, WhatsAppMessage $message): bool;
}
