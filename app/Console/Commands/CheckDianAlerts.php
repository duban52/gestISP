<?php

namespace App\Console\Commands;

use App\Billing\Dian\DianReadiness;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Avisa de lo que va a romperse antes de que se rompa.
 *
 *     php artisan dian:alertas
 *
 * QUE VIGILA
 * ----------
 * Las dos cosas que caducan sin avisar y paran la facturacion de golpe:
 *
 *   · El CERTIFICADO. Renovarlo ante una entidad acreditada no es
 *     inmediato; si nadie mira la fecha, se descubre el dia que deja de
 *     firmar.
 *   · El RANGO de numeracion. Se agota a mitad de una corrida mensual,
 *     y a partir de ahi no se puede emitir ni una factura mas hasta que
 *     la DIAN autorice el siguiente.
 *
 * Las dos comprobaciones ya existian en los modelos —`diasParaCaducar()`
 * y `porAgotarse()`— y no las llamaba nadie. Aqui es donde sirven.
 *
 * PENSADO PARA EL CRON
 * --------------------
 * Devuelve codigo 1 si hay algo que mirar, para poder encadenarlo con
 * un aviso. Y lo deja en el log, que es donde se busca despues.
 */
class CheckDianAlerts extends Command
{
    protected $signature = 'dian:alertas {--empresa= : Solo esta empresa}';

    protected $description = 'Avisa de certificados por caducar y rangos de numeración por agotarse';

    public function handle(DianReadiness $revision): int
    {
        $empresas = Company::query()
            ->where('electronic_invoicing_enabled', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('legal_name')
            ->get();

        if ($empresas->isEmpty()) {
            $this->line('Ninguna empresa tiene la facturación electrónica encendida.');

            return self::SUCCESS;
        }

        $hayAlgo = false;

        foreach ($empresas as $empresa) {
            $bloqueos = $revision->bloqueos($empresa);
            $avisos = $revision->avisos($empresa);

            if ($bloqueos === [] && $avisos === []) {
                continue;
            }

            $hayAlgo = true;

            $this->newLine();
            $this->line('<options=bold>' . $empresa->nombreVisible() . '</>');

            foreach ($bloqueos as $bloqueo) {
                $this->error('  ' . $bloqueo);
            }

            foreach ($avisos as $aviso) {
                $this->warn('  ' . $aviso);
            }

            // Al log tambien: el cron corre de madrugada y nadie lee su
            // salida, pero el log si se consulta cuando algo falla.
            Log::warning('Avisos de facturación electrónica', [
                'empresa' => $empresa->nombreVisible(),
                'bloqueos' => $bloqueos,
                'avisos' => $avisos,
            ]);
        }

        if (!$hayAlgo) {
            $this->info('Todo en orden: nada por caducar ni por agotarse.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
