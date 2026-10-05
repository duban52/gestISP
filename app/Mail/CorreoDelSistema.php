<?php

namespace App\Mail;

use App\Models\MailLog;
use App\Models\MailSetting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * El correo saliente del sistema: cómo se manda y qué pasó con él.
 *
 * TRES COSAS, EN UN SOLO SITIO
 * ----------------------------
 *  1. Aplica el SMTP configurado en pantalla sobre el del `.env`.
 *  2. Apaga el correo entero cuando hace falta.
 *  3. Anota cada intento, con el motivo si falló.
 *
 * POR QUÉ SOBRE LOS EVENTOS DE LARAVEL Y NO ENVOLVIENDO EL MAILER
 * ---------------------------------------------------------------
 * `MessageSending` y `MessageSent` los dispara el propio Laravel en
 * TODO envío: las nueve notificaciones, el restablecimiento de
 * contraseña, el correo de prueba de esta misma pantalla y el que
 * alguien añada mañana. Envolver el mailer habría dejado fuera lo que
 * no pasara por el envoltorio, que es exactamente el correo que se
 * pierde sin que nadie se entere.
 *
 * Y un detalle de Laravel que hace de interruptor: si un oyente de
 * `MessageSending` devuelve `false`, el correo NO se manda. No hace
 * falta tocar ninguna notificación.
 *
 * LA CONFIGURACIÓN SE CACHEA
 * --------------------------
 * Esto corre en cada petición y en cada trabajo de la cola. Una
 * consulta a la base por correo enviado, con mil facturas, son mil
 * consultas de más. Se guarda en caché y se tira al guardar.
 */
class CorreoDelSistema
{
    public const CACHE = 'mail_settings_vigentes';

    /**
     * La fila de bitácora del envío en curso.
     *
     * Entre `MessageSending` y `MessageSent` solo hay un correo: cada
     * trabajo de la cola manda uno. Esto es lo que permite marcarlo
     * como enviado, o ponerle el motivo cuando el trabajo revienta.
     */
    private static ?int $filaEnCurso = null;

    /**
     * Pone la configuración guardada encima de la del `.env`.
     *
     * Se llama al arrancar la aplicación. Falla en silencio a
     * propósito: si la tabla todavía no existe —una instalación nueva,
     * un despliegue antes de migrar— el sistema tiene que arrancar
     * igual y seguir enviando con el `.env`. Reventar aquí dejaría la
     * aplicación entera caída por una tabla de configuración.
     */
    public function aplicarConfiguracion(): void
    {
        try {
            $ajustes = $this->vigentes();

            if (!$ajustes) {
                return;
            }

            foreach ($ajustes->comoConfigDeLaravel() as $clave => $valor) {
                Config::set($clave, $valor);
            }
        } catch (Throwable $e) {
            // Ni un log de error: en una instalación nueva esto pasa
            // en cada petición hasta que se migra, y llenaría el log
            // de ruido por algo que no es un problema.
        }
    }

    /**
     * La configuración DEL SISTEMA, cacheada.
     *
     * `whereNull('branch_id')` no es un detalle: desde que las
     * sucursales guardan la suya en esta misma tabla, un `first()` a
     * secas devuelve la de cualquiera. Con una sede que tuviera el
     * correo apagado, eso apagaba el correo de TODO el sistema —
     * incluidos los restablecimientos de contraseña— y además le
     * aplicaba su servidor SMTP al resto. Lo encontró una prueba que
     * esperaba que apagar una sede no callara a las demás.
     */
    public function vigentes(): ?MailSetting
    {
        return Cache::rememberForever(
            self::CACHE,
            fn () => MailSetting::whereNull('branch_id')->first(),
        );
    }

    public static function olvidarCache(): void
    {
        Cache::forget(self::CACHE);
    }

    /**
     * Antes de cada envío: decidir si sale y anotarlo.
     *
     * @return bool|null `false` cancela el envío
     */
    public function alEnviar(MessageSending $evento): ?bool
    {
        $ajustes = $this->vigentes();
        $destino = $this->destinatario($evento);
        $asunto = $evento->message->getSubject();

        // De qué sucursal es este correo, y si esa sede lo tiene
        // apagado. Lo marcó ArmaCorreo con una cabecera porque aquí no
        // hay forma de saberlo: al oyente le llega un mensaje, no una
        // notificación con su contexto.
        [$sucursal, $sedeApagada] = $this->leerYQuitarLasMarcas($evento);

        if ($sedeApagada) {
            $this->anotar(
                MailLog::OMITIDO,
                $destino,
                $asunto,
                $evento,
                'La sucursal tiene el envío de correos deshabilitado.',
                $sucursal,
            );

            return false;
        }

        if ($ajustes && !$ajustes->enabled) {
            // Se anota igual: al volver a encender el correo hay que
            // poder saber qué se quedó sin mandar.
            $this->anotar(
                MailLog::OMITIDO,
                $destino,
                $asunto,
                $evento,
                'El envío de correos está deshabilitado.',
                $sucursal,
            );

            return false;
        }

        $this->frenar($ajustes);

        // Nace FALLIDO: si el envío revienta, el proceso se muere o el
        // servidor cuelga la conexión, la fila ya dice la verdad.
        // `MessageSent` es quien la asciende a enviado.
        self::$filaEnCurso = $this->anotar(MailLog::FALLIDO, $destino, $asunto, $evento, null, $sucursal)?->id;

        return null;
    }

    /** El envío salió: la fila pasa a enviada. */
    public function alEnviarse(MessageSent $evento): void
    {
        if (!self::$filaEnCurso) {
            return;
        }

        MailLog::whereKey(self::$filaEnCurso)->update([
            'status' => MailLog::ENVIADO,
            'sent_at' => now(),
            'updated_at' => now(),
        ]);

        self::$filaEnCurso = null;
    }

    /**
     * El trabajo de la cola falló: ponerle el motivo a la fila.
     *
     * Es lo que convierte «no llegó» en «la contraseña está mal».
     */
    public function alFallarElTrabajo(JobFailed $evento): void
    {
        if (!self::$filaEnCurso) {
            return;
        }

        MailLog::whereKey(self::$filaEnCurso)->update([
            'error' => \Illuminate\Support\Str::limit($evento->exception->getMessage(), 2000),
            'updated_at' => now(),
        ]);

        self::$filaEnCurso = null;
    }

    /**
     * Manda un correo de prueba y devuelve lo que pasó.
     *
     * SÍNCRONO Y A PROPÓSITO: la gracia de probar es ver el error del
     * servidor en el momento. Por la cola, el fallo acabaría en el log
     * del servidor y quien configura se quedaría mirando una pantalla
     * que dice «enviado».
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function enviarPrueba(string $destino, ?string $mailer = null): array
    {
        $ajustes = $this->vigentes();

        if ($ajustes && !$ajustes->enabled) {
            return [
                'ok' => false,
                'mensaje' => 'El envío de correos está deshabilitado: enciéndalo antes de probar.',
            ];
        }

        try {
            // Con un mailer concreto se prueba el de UNA sucursal sin
            // tocar el del sistema: es lo que permite comprobar la sede
            // nueva mientras las demas siguen enviando.
            Mail::mailer($mailer)->raw(
                "Este es un correo de prueba de GestISP.\n\n"
                . "Si lo está leyendo, la configuración de salida funciona: el servidor aceptó el "
                . "mensaje y lo entregó.\n\n"
                . 'Enviado el ' . now()->format('d/m/Y \a \l\a\s H:i') . '.',
                function ($mensaje) use ($destino) {
                    $mensaje->to($destino)->subject('Prueba de envío de GestISP');
                },
            );
        } catch (Throwable $e) {
            Log::warning('Falló el correo de prueba', ['error' => $e->getMessage()]);

            // La fila ya la creó alEnviar(); aquí se le pone el motivo.
            if (self::$filaEnCurso) {
                MailLog::whereKey(self::$filaEnCurso)->update([
                    'error' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
                    'context' => 'Correo de prueba',
                ]);
                self::$filaEnCurso = null;
            }

            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        return ['ok' => true, 'mensaje' => 'El correo salió hacia ' . $destino . '.'];
    }

    /**
     * No más de N correos por minuto.
     *
     * Una corrida de mil facturas encola mil trabajos y el worker los
     * vacía a toda velocidad. Todos los proveedores tienen un límite
     * por segundo, así que sin freno una parte se rechaza — y encima
     * un dominio recién estrenado que suelta mil correos de golpe
     * parece spam.
     *
     * ponytail: es una espera, no un cubo de fichas. Con un solo worker
     * —que es como está montado— el efecto es el mismo y cabe en tres
     * líneas. Con varios workers el ritmo real se multiplica por el
     * número de ellos; si algún día hace falta exacto, el sitio es un
     * RateLimiter de Redis.
     */
    private function frenar(?MailSetting $ajustes): void
    {
        $porMinuto = $ajustes?->per_minute;

        if (!$porMinuto || $porMinuto <= 0 || !app()->runningInConsole()) {
            return;
        }

        usleep((int) (60_000_000 / $porMinuto));
    }

    /**
     * Deja la fila en la bitácora. Nunca estorba al envío: si anotar
     * falla, el correo tiene que salir igual.
     */
    private function anotar(
        string $estado,
        string $destino,
        ?string $asunto,
        MessageSending $evento,
        ?string $error = null,
        ?int $sucursal = null,
    ): ?MailLog {
        try {
            return MailLog::create([
                'status' => $estado,
                'to' => \Illuminate\Support\Str::limit($destino, 190, ''),
                'subject' => $asunto ? \Illuminate\Support\Str::limit($asunto, 250) : null,
                'mailer' => config('mail.default'),
                'from_address' => config('mail.from.address'),
                'context' => $this->contexto($evento),
                'error' => $error,
                'company_id' => $this->empresa(),
                'branch_id' => $sucursal,
                'sent_at' => $estado === MailLog::ENVIADO ? now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo anotar el correo en la bitácora', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Lee las marcas que dejó ArmaCorreo y las QUITA del mensaje.
     *
     * Son cabeceras internas: dicen de qué sucursal es el correo y si
     * esa sede lo tiene apagado. Se quitan antes de enviar porque no
     * pintan nada en la bandeja del cliente — y porque una cabecera
     * `X-` rara es justo el tipo de detalle que algunos filtros de
     * spam puntúan.
     *
     * @return array{0: ?int, 1: bool}  [sucursal, apagada]
     */
    private function leerYQuitarLasMarcas(MessageSending $evento): array
    {
        $cabeceras = $evento->message->getHeaders();

        $sucursal = $cabeceras->get(\App\Mail\CorreoDeLaSucursal::CABECERA_SEDE)?->getBodyAsString();
        $apagada = $cabeceras->has(\App\Mail\CorreoDeLaSucursal::CABECERA_APAGADA);

        $cabeceras->remove(\App\Mail\CorreoDeLaSucursal::CABECERA_SEDE);
        $cabeceras->remove(\App\Mail\CorreoDeLaSucursal::CABECERA_APAGADA);

        return [$sucursal ? (int) $sucursal : null, $apagada];
    }

    /** Todos los destinatarios, separados por coma. */
    private function destinatario(MessageSending $evento): string
    {
        $destinos = $evento->message->getTo();

        return implode(', ', array_map(
            fn ($d) => $d->getAddress(),
            $destinos,
        )) ?: '(sin destinatario)';
    }

    /**
     * Qué provocó el correo.
     *
     * La clase de la notificación dice más que el asunto: «factura
     * generada» frente a «Su factura EGP000123», que no distingue el
     * aviso de la factura del recordatorio de vencimiento.
     */
    private function contexto(MessageSending $evento): ?string
    {
        $notificacion = $evento->data['__laravel_notification'] ?? null;

        if (is_object($notificacion)) {
            return class_basename($notificacion);
        }

        return null;
    }

    /** La empresa del contexto, si la hay (en la cola puede no haberla). */
    private function empresa(): ?int
    {
        try {
            $contexto = app(\App\Tenancy\CurrentContext::class);

            return $contexto->activo() ? $contexto->companyId() : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
