<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Services\OltSshService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deja lista la lista de ONTs en autofind de cada OLT.
 *
 * Consultarla por SSH tarda unos 40 segundos, y la pantalla de ONTs no
 * autorizadas la pedía en vivo cada vez que se elegía una OLT. Con esto
 * llega hecha.
 *
 *   php artisan onts:autofind-cache
 *   php artisan onts:autofind-cache --olt=3
 */
class WarmAutofindCache extends Command
{
    protected $signature = 'onts:autofind-cache
                            {--olt= : ID de una OLT específica}';

    protected $description = 'Consulta el autofind de cada OLT y lo deja en caché';

    public function __construct(private readonly OltSshService $ssh)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $olts = Olt::query()
            ->where('active', true)
            ->when($this->option('olt'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($olts->isEmpty()) {
            $this->warn('No hay OLTs activas.');

            return self::SUCCESS;
        }

        foreach ($olts as $olt) {
            try {
                // `fresh` porque el sentido de esta tarea es renovar:
                // sin él se quedaría leyendo su propia caché.
                $datos = $this->ssh->getAutoFindOntsCached($olt, fresh: true);

                $this->info(sprintf('%s: %d ONT(s) en autofind.', $olt->name, count($datos['onts'])));
            } catch (Throwable $e) {
                // Una OLT caída no puede impedir refrescar las demás. Y
                // su caché anterior sigue sirviéndose hasta que caduque.
                $this->error(sprintf('%s: %s', $olt->name, $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
