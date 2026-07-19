<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Tareas programadas de la aplicación.
     *
     * El servidor solo necesita UNA línea de cron llamando a
     * schedule:run cada minuto; el reparto de horarios se define
     * aquí, de modo que agregar una tarea nueva no exige volver a
     * tocar el crontab.
     *
     * Los horarios se interpretan en la zona horaria de la
     * aplicación (America/Bogota), no en la del servidor.
     */
    protected function schedule(Schedule $schedule): void
    {
        // ---- Muestreo de red (cada 5 minutos) ----
        // Actualiza potencias y estado de las ONTs y alimenta el
        // historial que grafica la vista de detalle. Cinco minutos
        // dan resolución suficiente sin cargar las OLTs.
        $schedule->command('onts:poll')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            // Sin esto la salida va a /dev/null: si el muestreo
            // falla (OLT caída, extensión SNMP ausente) no quedaría
            // ningún rastro y el problema pasaría inadvertido.
            ->appendOutputTo(storage_path('logs/onts-poll.log'))
            ->onFailure(fn () => Log::error('La tarea programada onts:poll terminó con error. Revise storage/logs/onts-poll.log'));

        // Tráfico de las cuentas PPPoE (una petición por router).
        $schedule->command('pppoe:poll')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/pppoe-poll.log'))
            ->onFailure(fn () => Log::error('La tarea programada pppoe:poll terminó con error. Revise storage/logs/pppoe-poll.log'));

        // ---- Mantenimiento nocturno ----
        // Resuelve los índices de tráfico de las ONTs nuevas y poda
        // el historial con más de 30 días.
        $schedule->command('onts:poll --resolve-traffic --prune=30')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/onts-poll.log'))
            ->onFailure(fn () => Log::error('El mantenimiento nocturno de ONTs falló.'));

        $schedule->command('pppoe:poll --prune=30')
            ->dailyAt('03:45')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/pppoe-poll.log'))
            ->onFailure(fn () => Log::error('El mantenimiento nocturno de PPPoE falló.'));

        // Los logs de muestreo crecen unos pocos cientos de KB al
        // día; se recortan solos para no llenar el disco.
        $schedule->call(function () {
            foreach (['onts-poll.log', 'pppoe-poll.log'] as $file) {
                $path = storage_path('logs/' . $file);

                if (is_file($path) && filesize($path) > 5 * 1024 * 1024) {
                    // Conservar las últimas 500 líneas
                    $lines = array_slice(file($path), -500);
                    file_put_contents($path, implode('', $lines));
                }
            }
        })->dailyAt('04:00')->name('recorte-logs-muestreo');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
