<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Muestreo SNMP de las ONTs: actualiza potencias y estado, y
        // alimenta el historial que grafica la vista de detalle.
        // Cada 5 minutos da una resolución suficiente para las
        // gráficas sin cargar la OLT (un recorrido masivo por OLT).
        $schedule->command('onts:poll')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Una vez al día: resuelve los ifIndex de tráfico de las
        // ONTs nuevas y poda el historial con más de 30 días.
        $schedule->command('onts:poll --resolve-traffic --prune=30')
            ->dailyAt('03:30')
            ->withoutOverlapping();
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
