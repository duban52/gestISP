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
        $correo = (new MailMessage)
            ->subject($asunto)
            ->view('emails.layout', array_merge([
                'sucursal' => $sucursal,
                'color' => self::COLORES[$tono] ?? self::COLORES['institucional'],
                // Si no se indica, la vista previa de la bandeja
                // muestra el propio asunto.
                'preheader' => $asunto,
            ], $datos));

        return $this->porLaSucursal($correo, $sucursal);
    }

    /**
     * Hace que el correo salga por el servidor de SU sucursal.
     *
     * AL CLIENTE LE ESCRIBE SU OPERADOR, no una central. Todas las
     * notificaciones del cliente pasan por aquí, así que es el único
     * sitio donde hay que decidirlo: nueve notificaciones resolviendo
     * cada una su servidor es la forma de que una se quede sin
     * actualizar y mande por donde no debe.
     *
     * Y corre DENTRO del trabajo de la cola, que es cuando se arma el
     * mensaje. Resolverlo al encolar habría hecho que las novecientas
     * facturas de una corrida salieran todas por la sucursal de la
     * última.
     *
     * Sin servidor propio, el correo sale como siempre: por el del
     * sistema o el del `.env`.
     */
    private function porLaSucursal(MailMessage $correo, ?Branch $sucursal): MailMessage
    {
        $delaSede = app(\App\Mail\CorreoDeLaSucursal::class);

        if ($mailer = $delaSede->mailerDe($sucursal)) {
            $correo->mailer($mailer);
        }

        // UNA MARCA EN EL MENSAJE, NO UN MAILER FALSO.
        //
        // Si la sede tiene el correo apagado hay que cancelar el envío,
        // y el único sitio que sabe cancelar es el oyente de
        // `MessageSending` —el mismo que apaga el correo del sistema—.
        // Pero ese oyente no sabe de qué sucursal es el mensaje: se lo
        // dice esta cabecera, que él lee y quita antes de enviar.
        //
        // La alternativa era un mailer llamado «apagado», y entonces
        // Laravel reventaría con «Mailer [apagado] is not defined» en
        // mitad de una corrida.
        if ($sucursal) {
            $apagada = $delaSede->estaApagada($sucursal);

            $correo->withSymfonyMessage(function ($mensaje) use ($sucursal, $apagada) {
                $mensaje->getHeaders()->addTextHeader(\App\Mail\CorreoDeLaSucursal::CABECERA_SEDE, (string) $sucursal->id);

                if ($apagada) {
                    $mensaje->getHeaders()->addTextHeader(\App\Mail\CorreoDeLaSucursal::CABECERA_APAGADA, '1');
                }
            });
        }

        if ($remitente = $delaSede->remitenteDe($sucursal)) {
            // Laravel resuelve el «from» del MENSAJE, no del
            // transporte: sin esto saldría por el servidor de la sede
            // pero firmado por el del sistema.
            $correo->from($remitente['address'], $remitente['name']);
        }

        return $correo;
    }

    /**
     * Formatea un importe en pesos colombianos.
     */
    protected function pesos(float|int|string|null $valor): string
    {
        return '$' . number_format((float) $valor, 0, ',', '.');
    }
}
