<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;

/**
 * Una copia de seguridad que existe en el disco del servidor.
 *
 * No hay tabla de copias en la base de datos, y es deliberado: el
 * inventario de copias no puede vivir dentro de lo que se está
 * respaldando. Si la base de datos se pierde —que es justo el día en
 * que hacen falta— el listado se perdería con ella. La verdad está en
 * los archivos, y todo lo que hace falta saber de cada uno va en su
 * nombre.
 */
final class BackupFile
{
    /** Copia pedida por una persona desde la pantalla de Sistema. */
    public const ORIGEN_MANUAL = 'manual';

    /** Copia lanzada por el cron del servidor. */
    public const ORIGEN_AUTOMATICO = 'auto';

    /**
     * Nombres válidos. Sirve para dos cosas: reconocer nuestras copias
     * entre otros archivos que hayan podido quedar en la carpeta, y
     * blindar la descarga (el nombre llega por la URL, así que solo se
     * acepta lo que encaje exactamente con este patrón).
     */
    public const PATRON = '/^gestisp-db-(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})-(manual|auto)\.sql\.gz$/';

    public function __construct(
        public readonly string $nombre,
        public readonly string $ruta,
        public readonly int $bytes,
        public readonly CarbonImmutable $fecha,
        public readonly string $origen,
    ) {
    }

    /**
     * Construye el objeto a partir de un archivo del disco.
     *
     * Devuelve null si el archivo no es una copia nuestra.
     */
    public static function desdeRuta(string $ruta): ?self
    {
        $nombre = basename($ruta);

        if (!preg_match(self::PATRON, $nombre, $partes)) {
            return null;
        }

        // La fecha se lee del nombre, no del sistema de archivos: una
        // copia traída de vuelta desde la NAS llega con la fecha en
        // que se copió, no con la del volcado.
        $fecha = CarbonImmutable::create(
            (int) $partes[1],
            (int) $partes[2],
            (int) $partes[3],
            (int) $partes[4],
            (int) $partes[5],
            (int) $partes[6],
        );

        if (!$fecha instanceof CarbonImmutable) {
            $fecha = CarbonImmutable::createFromTimestamp((int) @filemtime($ruta));
        }

        return new self(
            nombre: $nombre,
            ruta: $ruta,
            bytes: (int) (@filesize($ruta) ?: 0),
            fecha: $fecha,
            origen: $partes[7],
        );
    }

    /**
     * Nombre que tendrá el archivo que se genere ahora.
     */
    public static function nombreNuevo(string $origen, ?CarbonImmutable $momento = null): string
    {
        $momento ??= CarbonImmutable::now();

        return sprintf('gestisp-db-%s-%s.sql.gz', $momento->format('Y-m-d_His'), $origen);
    }

    public function esManual(): bool
    {
        return $this->origen === self::ORIGEN_MANUAL;
    }

    /**
     * Tamaño en unidades legibles ("184,3 MB").
     */
    public function tamanoLegible(): string
    {
        return self::formatearTamano($this->bytes);
    }

    public static function formatearTamano(int|float $bytes): string
    {
        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($unidades) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return number_format($bytes, $i < 2 ? 0 : 1, ',', '.') . ' ' . $unidades[$i];
    }

    /**
     * Antigüedad en horas, para avisar de copias automáticas atrasadas.
     */
    public function horasDeAntiguedad(): float
    {
        return $this->fecha->diffInMinutes(CarbonImmutable::now()) / 60;
    }
}
