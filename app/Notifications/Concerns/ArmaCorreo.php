<?php

namespace App\Notifications\Concerns;

use App\Models\Branch;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Construye los correos de GestISP con la plantilla de marca.
 *
 * Todas las notificaciones comparten la misma estructura visual
 * (encabezado de la sucursal, cuerpo, ficha de datos, botón y pie con
 * los datos de contacto). Este trait evita repetirla siete veces y
 * garantiza que si se cambia el diseño, cambien todas a la vez.
 */
trait ArmaCorreo
{
    /** Colores de acento según la intención del mensaje. */
    private const COLORES = [
        'institucional' => '#1F4E79',
        'exito' => '#1E7B34',
        'aviso' => '#C97A0E',
        'alerta' => '#B32020',
    ];

    /**
     * Arma el correo con la plantilla común.
     *
     * @param  array<string, mixed>  $datos  Variables de la plantilla
     */
    protected function correo(string $asunto, array $datos, ?Branch $sucursal = null, string $tono = 'institucional'): MailMessage
    {
        return (new MailMessage)
            ->subject($asunto)
            ->view('emails.layout', array_merge([
                'sucursal' => $sucursal,
                'color' => self::COLORES[$tono] ?? self::COLORES['institucional'],
                // Si no se indica, la vista previa de la bandeja
                // muestra el propio asunto.
                'preheader' => $asunto,
            ], $datos));
    }

    /**
     * Formatea un importe en pesos colombianos.
     */
    protected function pesos(float|int|string|null $valor): string
    {
        return '$' . number_format((float) $valor, 0, ',', '.');
    }
}
