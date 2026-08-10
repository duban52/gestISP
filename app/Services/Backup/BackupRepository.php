<?php

namespace App\Services\Backup;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Acceso a la carpeta de copias del servidor.
 *
 * Todo lo que toque esa carpeta pasa por aquí. El motivo es la
 * descarga: el nombre del archivo llega desde la URL, y una carpeta de
 * copias es exactamente lo que busca quien intenta un "../../.env".
 * Concentrando aquí el acceso hay UN solo sitio que validar, y esa
 * validación (el patrón de BackupFile más la comprobación de que la
 * ruta real cae dentro de la carpeta) no se puede olvidar en una
 * pantalla nueva.
 */
class BackupRepository
{
    /**
     * Carpeta de copias, creada si aún no existe.
     */
    public function directorio(): string
    {
        $ruta = config('backup.path');

        if (!is_dir($ruta)) {
            File::ensureDirectoryExists($ruta, 0750);
        }

        return $ruta;
    }

    /**
     * Todas las copias, de la más reciente a la más antigua.
     *
     * @return Collection<int, BackupFile>
     */
    public function todas(): Collection
    {
        $archivos = glob(rtrim($this->directorio(), '/\\') . DIRECTORY_SEPARATOR . 'gestisp-db-*.sql.gz') ?: [];

        return collect($archivos)
            ->map(fn (string $ruta) => BackupFile::desdeRuta($ruta))
            ->filter()
            ->sortByDesc(fn (BackupFile $copia) => $copia->fecha->getTimestamp())
            ->values();
    }

    /**
     * Última copia, opcionalmente de un origen concreto.
     */
    public function ultima(?string $origen = null): ?BackupFile
    {
        return $this->todas()
            ->when($origen !== null, fn (Collection $copias) => $copias->where('origen', $origen))
            ->first();
    }

    /**
     * Localiza una copia por su nombre.
     *
     * Devuelve null ante cualquier nombre que no sea exactamente el de
     * una copia nuestra dentro de la carpeta. Nunca lanza excepción:
     * el que llama decide si eso es un 404 o un mensaje en pantalla.
     */
    public function buscar(string $nombre): ?BackupFile
    {
        // basename() descarta cualquier componente de ruta antes
        // incluso de mirar el patrón
        $nombre = basename($nombre);

        if (!preg_match(BackupFile::PATRON, $nombre)) {
            return null;
        }

        $ruta = rtrim($this->directorio(), '/\\') . DIRECTORY_SEPARATOR . $nombre;

        if (!is_file($ruta)) {
            return null;
        }

        // Cinturón y tirantes: aunque el patrón ya impide subir de
        // carpeta, se comprueba que la ruta resuelta (enlaces
        // simbólicos incluidos) sigue dentro de la carpeta de copias.
        $real = realpath($ruta);
        $base = realpath($this->directorio());

        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return null;
        }

        return BackupFile::desdeRuta($real);
    }

    /**
     * Borra una copia. Devuelve false si no existe.
     */
    public function borrar(string $nombre): bool
    {
        $copia = $this->buscar($nombre);

        return $copia !== null && @unlink($copia->ruta);
    }

    /**
     * Aplica la retención local.
     *
     * Borra las copias más antiguas que $dias, pero nunca deja menos
     * de $minimo en el disco: si el servidor estuvo apagado una
     * temporada, todas sus copias serán "viejas" y borrarlas dejaría
     * el servidor sin ninguna a mano.
     *
     * @return array{borradas: int, liberado: int}
     */
    public function purgar(?int $dias = null, ?int $minimo = null): array
    {
        $dias ??= (int) config('backup.keep_days', 7);
        $minimo ??= (int) config('backup.keep_min', 4);

        $todas = $this->todas();
        $limite = now()->subDays($dias);

        $borradas = 0;
        $liberado = 0;

        // Se recorre de la más antigua hacia arriba y se para en
        // cuanto quedarían menos del mínimo
        foreach ($todas->reverse() as $copia) {
            if ($todas->count() - $borradas <= $minimo) {
                break;
            }

            if ($copia->fecha->greaterThanOrEqualTo($limite)) {
                break;
            }

            if (@unlink($copia->ruta)) {
                $borradas++;
                $liberado += $copia->bytes;
            }
        }

        return ['borradas' => $borradas, 'liberado' => $liberado];
    }

    /**
     * Espacio que ocupan todas las copias juntas.
     */
    public function espacioOcupado(): int
    {
        return (int) $this->todas()->sum('bytes');
    }

    /**
     * Espacio libre en el disco donde viven las copias (null si el
     * sistema no lo puede informar).
     */
    public function espacioLibre(): ?int
    {
        $libre = @disk_free_space($this->directorio());

        return $libre === false ? null : (int) $libre;
    }
}
