<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Una lista de valores sacada de un .txt, .csv, .xlsx o .xls.
 *
 * La usan los cortes masivos (números de contrato, usuarios) y los
 * movimientos de almacén (números de serie): todos llegan igual, en
 * una columna de un archivo exportado de algún sitio.
 *
 * Reglas de lectura:
 *  - Si la primera fila trae un encabezado reconocible (los que pase
 *    quien llama), se usa ESA columna y se salta la fila.
 *  - Si no, la primera columna desde la fila uno, que es como llega un
 *    .txt pelado.
 */
final class ListaDesdeArchivo
{
    /**
     * @param  array<int, string>  $encabezados  en minúsculas, sin tildes ni espacios
     * @return array<int, string>  los valores tal cual, sin limpiar
     */
    public static function valores(UploadedFile $archivo, array $encabezados): array
    {
        $extension = strtolower($archivo->getClientOriginalExtension());

        $filas = in_array($extension, ['txt', 'csv'], true)
            ? self::leerTexto($archivo->getRealPath())
            : self::leerHoja($archivo->getRealPath());

        if ($filas->isEmpty()) {
            return [];
        }

        $columna = self::columna($filas->first(), $encabezados);
        $datos = $columna === null ? $filas : $filas->skip(1);
        $indice = $columna ?? 0;

        return $datos->map(fn ($fila) => (string) ($fila[$indice] ?? ''))->values()->all();
    }

    /**
     * Lee un .txt o .csv como filas de celdas.
     *
     * No se usa la librería de Excel para estos: convierte los valores,
     * y un contrato «00123» o un serial «0012AB» perderían los ceros.
     *
     * @return Collection<int, array<int, string>>
     */
    private static function leerTexto(string $ruta): Collection
    {
        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            return collect();
        }

        $primeraLinea = fgets($manejador) ?: '';
        // BOM que agrega Excel al guardar en UTF-8
        $primeraLinea = preg_replace('/^\xEF\xBB\xBF/', '', $primeraLinea);

        $separador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';

        rewind($manejador);

        $filas = collect();
        $primera = true;

        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            if ($primera) {
                $fila[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($fila[0] ?? ''));
                $primera = false;
            }

            $filas->push(array_map(fn ($v) => (string) $v, $fila));
        }

        fclose($manejador);

        return $filas->reject(fn ($fila) => collect($fila)->filter(fn ($v) => trim($v) !== '')->isEmpty())
            ->values();
    }

    /**
     * Lee un .xlsx o .xls como filas de celdas.
     *
     * @return Collection<int, array<int, string>>
     */
    private static function leerHoja(string $ruta): Collection
    {
        $hoja = Excel::toCollection(null, $ruta)->first() ?? collect();

        return $hoja
            ->map(fn ($fila) => collect($fila)->map(fn ($v) => (string) $v)->all())
            ->reject(fn ($fila) => collect($fila)->filter(fn ($v) => trim($v) !== '')->isEmpty())
            ->values();
    }

    /**
     * Índice de la columna que buscamos, o null si la primera fila no
     * parece un encabezado.
     *
     * @param  array<int, string>  $primeraFila
     * @param  array<int, string>  $encabezados
     */
    private static function columna(array $primeraFila, array $encabezados): ?int
    {
        foreach ($primeraFila as $indice => $titulo) {
            $normalizado = preg_replace('/[^a-z]/', '', mb_strtolower(
                iconv('UTF-8', 'ASCII//TRANSLIT', (string) $titulo) ?: (string) $titulo
            ));

            if (in_array($normalizado, $encabezados, true)) {
                return (int) $indice;
            }
        }

        return null;
    }
}
