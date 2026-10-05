<?php

namespace App\Console\Commands;

use App\Mail\CorreoDelSistema;
use App\Models\MailSetting;
use Illuminate\Console\Command;
use Symfony\Component\Mailer\Transport;
use Throwable;

/**
 * Qué configuración de correo está usando de verdad el sistema.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Cuando el servidor contesta «535 Username and Password not
 * accepted» no hay forma de saber qué se le mandó: si la contraseña
 * guardada, la del `.env`, una con espacios de más o ninguna. Todas
 * dan el mismo error y cada una se arregla distinto.
 *
 * Esto lo enseña: de dónde sale cada dato, cuántos caracteres tiene
 * la contraseña y si trae espacios — sin imprimirla nunca.
 *
 *   php artisan correo:diagnostico
 *   php artisan correo:diagnostico --probar=yo@dominio.com
 */
class DiagnoseMail extends Command
{
    protected $signature = 'correo:diagnostico
                            {--probar= : Intenta una conexión real y, si se indica correo, manda una prueba}
                            {--usuario= : Prueba con OTRO usuario, sin guardarlo}
                            {--clave= : Prueba con OTRA contraseña, sin guardarla}
                            {--sin-espacios : Quita los espacios de la contraseña antes de probar}';

    protected $description = 'Enseña con qué configuración de correo está enviando el sistema y por qué falla';

    public function handle(CorreoDelSistema $correo): int
    {
        // La misma que aplica la aplicación al arrancar.
        $correo->aplicarConfiguracion();

        // PROBAR SIN GUARDAR.
        //
        // Cuando el servidor dice «535» hay cuatro sospechosos —usuario,
        // contraseña, espacios y cuenta equivocada— y comprobarlos
        // guardando cada combinación es lento y deja rastro en la
        // configuración de verdad. Esto los prueba en el sitio, sin
        // tocar nada: lo que se pasa aquí vale solo para esta orden.
        if ($this->option('usuario')) {
            config(['mail.mailers.smtp.username' => $this->option('usuario')]);
        }

        if ($this->option('clave')) {
            config(['mail.mailers.smtp.password' => $this->option('clave')]);
        }

        if ($this->option('sin-espacios')) {
            config([
                'mail.mailers.smtp.password' => preg_replace(
                    '/\s+/u',
                    '',
                    (string) config('mail.mailers.smtp.password'),
                ),
            ]);
        }

        $guardada = MailSetting::first();

        $this->info('CONFIGURACIÓN EN USO');
        $this->newLine();

        if (!$guardada) {
            $this->warn('No hay nada guardado en la pantalla: todo sale del .env del servidor.');
        } elseif (!$guardada->enabled) {
            $this->error('EL ENVÍO DE CORREOS ESTÁ APAGADO en la pantalla. No sale ninguno.');
        }

        $this->table(['Dato', 'Valor', 'De dónde sale'], [
            ['Transporte', config('mail.default'), 'MAIL_MAILER del .env'],
            ['Servidor', config('mail.mailers.smtp.host'), $this->origen($guardada?->host)],
            ['Puerto', config('mail.mailers.smtp.port'), $this->origen($guardada?->port)],
            ['Cifrado', config('mail.mailers.smtp.encryption') ?: '(ninguno)', $this->origen($guardada?->encryption)],
            ['Usuario', config('mail.mailers.smtp.username') ?: '(ninguno)', $this->origen($guardada?->username)],
            ['Contraseña', $this->describirClave(config('mail.mailers.smtp.password')), $this->origen($guardada?->password)],
            ['Remitente', config('mail.from.address'), $this->origen($guardada?->from_address)],
        ]);

        $this->avisarDeLaClave((string) config('mail.mailers.smtp.password'));

        if (!$this->option('probar')) {
            $this->newLine();
            $this->comment('Para probar la conexión de verdad:');
            $this->line('  php artisan correo:diagnostico --probar=sucorreo@dominio.com');
            $this->newLine();
            $this->comment('Para descartar los espacios, probando sin guardar nada:');
            $this->line('  php artisan correo:diagnostico --probar=sucorreo@dominio.com --sin-espacios');
            $this->newLine();
            $this->comment('Para probar OTRA cuenta o la clave del .env, sin guardarlas:');
            $this->line('  php artisan correo:diagnostico --probar=sucorreo@dominio.com'
                . ' --usuario=otra@gmail.com --clave="la de aplicacion"');

            return self::SUCCESS;
        }

        return $this->probar();
    }

    /**
     * Describe la contraseña SIN enseñarla.
     *
     * Lo que importa para diagnosticar no es cuál es, sino cuántos
     * caracteres tiene y si trae espacios: una de aplicación de Google
     * son exactamente 16 y sin ninguno.
     */
    private function describirClave(?string $clave): string
    {
        if (blank($clave)) {
            return '(vacía)';
        }

        return sprintf(
            '%d caracteres%s',
            strlen($clave),
            str_contains($clave, ' ') ? ' — ¡CONTIENE ESPACIOS!' : '',
        );
    }

    private function avisarDeLaClave(string $clave): void
    {
        if (blank($clave)) {
            return;
        }

        $this->newLine();

        if (str_contains($clave, ' ')) {
            // SIN SENTENCIAR. Google dice en su ayuda que la
            // contraseña de aplicación vale con o sin espacios, y hay
            // instalaciones funcionando con ellos. Son sospechosos,
            // no culpables: lo que se ofrece es la forma de
            // descartarlos en diez segundos.
            $this->warn('La contraseña tiene espacios.');
            $this->line('  Google las enseña en grupos de cuatro y suele aceptarlas así, pero no siempre.');
            $this->line('  Para descartarlo sin guardar nada, repita la orden añadiendo --sin-espacios');

            return;
        }

        if (str_contains((string) config('mail.mailers.smtp.host'), 'gmail') && strlen($clave) !== 16) {
            $this->warn('Gmail espera una contraseña de APLICACIÓN de 16 caracteres, y esta tiene ' . strlen($clave) . '.');
            $this->line('  La contraseña normal de la cuenta no sirve: hay que crear una en');
            $this->line('  Cuenta de Google → Seguridad → Contraseñas de aplicaciones (exige verificación en dos pasos).');
        }
    }

    /** Conexión real contra el servidor, con el error tal cual. */
    private function probar(): int
    {
        $destino = $this->option('probar');

        $this->newLine();
        $this->info('PROBANDO LA CONEXIÓN...');

        $dsn = sprintf(
            'smtp://%s:%s@%s:%s',
            rawurlencode((string) config('mail.mailers.smtp.username')),
            rawurlencode((string) config('mail.mailers.smtp.password')),
            config('mail.mailers.smtp.host'),
            config('mail.mailers.smtp.port'),
        );

        try {
            $transporte = Transport::fromDsn($dsn);
            $transporte->start();

            $this->info('El servidor ACEPTÓ el usuario y la contraseña.');
            $transporte->stop();
        } catch (Throwable $e) {
            $this->error('El servidor rechazó la conexión:');
            $this->line('  ' . $e->getMessage());
            $this->newLine();
            $this->interpretar($e->getMessage());

            return self::FAILURE;
        }

        if (filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $resultado = app(CorreoDelSistema::class)->enviarPrueba($destino);

            $resultado['ok']
                ? $this->info('Correo de prueba enviado a ' . $destino . '.')
                : $this->error('No salió: ' . $resultado['mensaje']);

            return $resultado['ok'] ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function interpretar(string $error): void
    {
        if (str_contains($error, '535') || stripos($error, 'BadCredentials') !== false) {
            $this->comment('El usuario o la contraseña no son los que espera el servidor. Con Gmail, por orden:');
            $this->line('  1. Que sea una contraseña de APLICACIÓN, no la del correo.');
            $this->line('  2. Que esa contraseña sea de LA MISMA cuenta que figura como usuario.');
            $this->line('  3. Que la cuenta tenga la verificación en dos pasos activada.');
            $this->line('  4. Si es Google Workspace, que el administrador no las tenga bloqueadas.');

            return;
        }

        if (stripos($error, 'Connection') !== false || stripos($error, 'timed out') !== false) {
            $this->comment('No se llegó al servidor. Suele ser el firewall del proveedor del VPS:');
            $this->line('  muchos cierran el puerto 587 de salida por defecto y hay que pedir que lo abran.');
            $this->line('  Compruébelo con:  telnet ' . config('mail.mailers.smtp.host') . ' ' . config('mail.mailers.smtp.port'));
        }
    }

    private function origen(mixed $valorGuardado): string
    {
        return filled($valorGuardado) ? 'La pantalla' : 'El .env';
    }
}
