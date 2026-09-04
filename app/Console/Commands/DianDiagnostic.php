<?php

namespace App\Console\Commands;

use App\Billing\Dian\DianReadiness;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Dice qué falta para poder facturar electrónicamente.
 *
 *     php artisan dian:diagnostico
 *     php artisan dian:diagnostico --empresa=3
 *
 * PARA QUÉ SIRVE
 * --------------
 * Hasta ahora la única forma de saber si una empresa podía emitir era
 * intentarlo — y ese es justamente el momento en que no se puede
 * fallar: un consecutivo autorizado gastado en un documento que la DIAN
 * rechaza deja un hueco que hay que justificar.
 *
 * Esto lo contesta antes, y sin tocar nada.
 */
class DianDiagnostic extends Command
{
    protected $signature = 'dian:diagnostico {--empresa= : Solo esta empresa}';

    protected $description = 'Revisa qué falta para que una empresa pueda emitir facturas electrónicas';

    public function handle(DianReadiness $revision): int
    {
        $empresas = Company::query()
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('legal_name')
            ->get();

        if ($empresas->isEmpty()) {
            $this->error('No hay ninguna empresa que revisar.');

            return self::FAILURE;
        }

        $todasListas = true;

        foreach ($empresas as $empresa) {
            $this->newLine();
            $this->line('<options=bold>' . $empresa->nombreVisible() . '</> — ' . $empresa->identificacion());

            $pasos = $revision->revisar($empresa);

            $this->table(
                ['', 'Comprobación', 'Detalle'],
                collect($pasos)->map(fn (array $paso) => [
                    $this->marca($paso),
                    $paso['titulo'],
                    $paso['detalle'],
                ]),
            );

            if ($revision->puedeEmitir($empresa)) {
                $this->info('  ✔ Puede emitir facturas electrónicas.');
            } else {
                $todasListas = false;
                $this->warn('  ✘ Todavía no puede emitir. Resuelva lo marcado en rojo.');
            }

            foreach ($revision->avisos($empresa) as $aviso) {
                $this->line('  <fg=yellow>Aviso:</> ' . $aviso);
            }
        }

        $this->newLine();

        // El código de salida sirve para encadenarlo en un despliegue:
        // «no sigas si la empresa no está lista».
        return $todasListas ? self::SUCCESS : self::FAILURE;
    }

    private function marca(array $paso): string
    {
        if ($paso['ok']) {
            return '<fg=green>✔</>';
        }

        return $paso['bloqueante'] ? '<fg=red>✘</>' : '<fg=yellow>!</>';
    }
}
