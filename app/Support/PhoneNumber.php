<?php

namespace App\Support;

/**
 * Normaliza números de teléfono al formato que exige WhatsApp.
 *
 * Meta (y casi cualquier pasarela) espera el número en formato
 * internacional E.164 SIN el signo "+": indicativo de país seguido
 * del número, todo en dígitos. En GestISP los teléfonos se guardan
 * de muchas formas ("311 555 4433", "+57 311...", "3115554433"),
 * así que aquí se limpian y se les antepone el indicativo del país
 * cuando les falta.
 */
class PhoneNumber
{
    /**
     * Devuelve el número listo para WhatsApp, o null si no parece un
     * número válido (menos de 10 dígitos tras limpiar).
     */
    public static function forWhatsApp(?string $raw, ?string $countryCode = null): ?string
    {
        $countryCode = $countryCode ?: (string) config('notifications.whatsapp.default_country_code', '57');

        // Se conservan solo los dígitos: se descartan espacios,
        // guiones, paréntesis y el "+".
        $digits = preg_replace('/\D+/', '', (string) $raw);

        if ($digits === '' || strlen($digits) < 10) {
            return null;
        }

        // Ya trae el indicativo del país (empieza por él y tiene la
        // longitud de un número internacional): se deja tal cual.
        if (str_starts_with($digits, $countryCode) && strlen($digits) > 10) {
            return $digits;
        }

        // Número nacional de 10 dígitos (celular colombiano): se le
        // antepone el indicativo.
        if (strlen($digits) === 10) {
            return $countryCode . $digits;
        }

        // Cualquier otro caso (ya venía con indicativo u otro país):
        // se devuelve tal cual, sin inventar.
        return $digits;
    }
}
