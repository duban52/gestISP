<?php

namespace App\Support;

use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;

/**
 * Datos de marca para los PDFs del sistema.
 *
 * Resuelve la sucursal emisora del documento y la ruta de su logo.
 *
 * El logo se entrega como RUTA ABSOLUTA DE DISCO, no como URL:
 * dompdf lee el archivo directamente y no depende de que el
 * servidor pueda alcanzarse a sí mismo por HTTP (con URL, si el
 * dominio no resuelve desde el propio servidor, el logo sale roto).
 */
class PdfBranding
{
    /**
     * Construye un PDF del sistema con el formato estándar:
     * tamaño carta, orientación indicada y paginación
     * "Página X de Y" en el pie.
     *
     * La paginación se estampa con la API de canvas de dompdf
     * (después de render, antes de output) porque el contador CSS
     * counter(pages) devuelve 0 dentro de elementos de posición
     * fija — que es donde vive nuestro pie. Se hace desde PHP y no
     * con <script type="text/php">, para no tener que habilitar la
     * ejecución de PHP dentro del HTML del PDF.
     *
     * @param string $view Vista Blade del documento
     * @param array $data Datos de la vista
     * @param bool $landscape true para reportes anchos
     */
    public static function make(string $view, array $data = [], bool $landscape = false): PdfWrapper
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('letter', $landscape ? 'landscape' : 'portrait');

        $pdf->render();
        self::stampPageNumbers($pdf);

        return $pdf;
    }

    /**
     * Escribe "Página X de Y" alineado a la derecha del pie, en
     * todas las páginas del documento ya renderizado.
     */
    private static function stampPageNumbers(PdfWrapper $pdf): void
    {
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');

        if (!$font) {
            return;
        }

        // Coordenadas en puntos, medidas desde el borde de la hoja.
        // El margen derecho del layout es 34px = 25,5pt y se reserva
        // el ancho aproximado del texto ya paginado (~44pt), de modo
        // que quede alineado a la derecha.
        // La altura corresponde a la PRIMERA línea del pie: la
        // segunda la ocupa la fecha de generación, por eso el pie
        // deja esa línea en blanco (sin esto los textos se pisan).
        $x = $canvas->get_width() - 25.5 - 44;
        $y = $canvas->get_height() - 52;

        $canvas->page_text(
            $x,
            $y,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $font,
            5.9,
            [0.47, 0.47, 0.47] // mismo gris del pie
        );
    }

    /**
     * Sucursal del documento: la indicada o, por defecto, la
     * sucursal activa en sesión.
     */
    public static function branch(?Branch $branch = null): ?Branch
    {
        if ($branch) {
            return $branch;
        }

        $branchId = session('branch_id');

        return $branchId ? Branch::find($branchId) : null;
    }

    /**
     * Ruta absoluta del logo de la sucursal, o null si no tiene
     * logo cargado o el archivo no existe en disco.
     */
    public static function logoPath(?Branch $branch): ?string
    {
        if (!$branch || !$branch->image) {
            return null;
        }

        $path = public_path('storage/' . $branch->image);

        return is_file($path) ? $path : null;
    }

    /**
     * Línea de ubicación de la sucursal (municipio, departamento,
     * país) omitiendo los campos vacíos.
     */
    public static function locationLine(?Branch $branch): string
    {
        if (!$branch) {
            return '';
        }

        return collect([$branch->municipality, $branch->department, $branch->country])
            ->filter()
            ->implode(', ');
    }

    /**
     * Línea de teléfonos de la sucursal.
     */
    public static function phoneLine(?Branch $branch): string
    {
        if (!$branch) {
            return '';
        }

        return collect([$branch->number_phone, $branch->additional_number])
            ->filter()
            ->implode(' · ');
    }
}
