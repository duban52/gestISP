<?php

namespace Database\Seeders;

use App\Models\FiscalCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra los catalogos fiscales desde los JSON versionados.
 *
 *     php artisan db:seed --class=FiscalCatalogSeeder
 *
 * Lee de `database/data/dian/`, que se genera con
 * `dian:importar-catalogos` a partir de los .gc de la DIAN. Esa
 * carpeta de origen esta en .gitignore y no existe en el servidor;
 * los JSON si se versionan, que es donde debe verse un cambio de
 * catalogo fiscal.
 *
 * ES IDEMPOTENTE Y NO BORRA
 * -------------------------
 * Actualiza lo que cambio y anade lo nuevo. Un codigo que desaparece
 * del catalogo NO se borra: se marca inactivo. Los documentos ya
 * emitidos lo llevan, y borrarlo dejaria sin nombre a lo que ya se
 * emitio.
 */
class FiscalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $carpeta = database_path('data/dian');

        if (!is_dir($carpeta)) {
            $this->command?->error("No existe {$carpeta}. Ejecute antes: php artisan dian:importar-catalogos");

            return;
        }

        $archivos = glob($carpeta . DIRECTORY_SEPARATOR . '*.json');

        foreach ($archivos as $archivo) {
            $catalogo = pathinfo($archivo, PATHINFO_FILENAME);
            $codigos = json_decode(file_get_contents($archivo), true);

            if (!is_array($codigos) || $codigos === []) {
                $this->command?->warn("  {$catalogo}: vacio o ilegible, se salta.");

                continue;
            }

            $this->sembrar($catalogo, $codigos);
        }
    }

    /** @param  array<int, array<string, mixed>>  $codigos */
    private function sembrar(string $catalogo, array $codigos): void
    {
        DB::transaction(function () use ($catalogo, $codigos) {
            $vistos = [];

            foreach ($codigos as $codigo) {
                FiscalCatalog::updateOrCreate(
                    ['catalog' => $catalogo, 'code' => $codigo['code']],
                    [
                        'name' => $codigo['name'],
                        'parent_code' => $codigo['parent_code'] ?? null,
                        'extra' => $codigo['extra'] ?? null,
                        'sort_order' => $codigo['sort_order'] ?? 0,
                        'active' => true,
                    ],
                );

                $vistos[] = $codigo['code'];
            }

            // Lo que ya no viene en el archivo se desactiva, no se
            // borra: puede estar referenciado por un documento emitido.
            $retirados = FiscalCatalog::de($catalogo)
                ->whereNotIn('code', $vistos)
                ->where('active', true)
                ->update(['active' => false]);

            $mensaje = sprintf('  %-28s %4d codigos', $catalogo, count($vistos));

            if ($retirados > 0) {
                $mensaje .= sprintf(' (%d retirados)', $retirados);
            }

            $this->command?->info($mensaje);
        });
    }
}
