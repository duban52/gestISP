<?php

namespace App\Support;

use App\Models\Branch;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use App\Tenancy\CurrentContext;

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
     * El papel de la representación gráfica de la factura: MEDIA CARTA.
     *
     * 612 x 396 puntos = 21,6 x 14,0 cm = 8,5 x 5,5 pulgadas, la mitad
     * exacta de una hoja carta. Es el papel en el que se imprimen las
     * facturas, y por eso no se usa `letter` como el resto de informes.
     *
     * ANTES ERA 612 x 419,53, QUE NO ES MEDIA CARTA
     * ---------------------------------------------
     * Son 14,8 cm de alto: el lado corto de un A5. O sea, ancho de
     * carta con alto de A5, que no es ningún tamaño de papel. La
     * impresora tenía que escalar o expulsar hoja de más.
     *
     * Está aquí, en una constante, porque estaba escrito a mano en dos
     * sitios —la factura suelta y el lote— y dos copias de un número
     * mágico acaban divergiendo.
     *
     * El contenido de la factura ocupa unos 12,1 cm, así que caben con
     * casi 2 cm de margen. Lo comprueba `InvoicePdfLayoutTest`.
     */
    public const MEDIA_CARTA = [0, 0, 612.00, 396.00];

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

        // El logo y los datos fiscales son de la EMPRESA, y todas
        // las sucursales de una empresa devuelven los mismos (ver
        // Branch::getImageAttribute). Por eso, en panel consolidado
        // —donde no hay una activa— vale cualquiera del alcance: antes
        // se devolvía null y los PDF salían sin membrete.
        $contexto = app(CurrentContext::class);
        $branchId = $contexto->branchId() ?? ($contexto->branchIds()[0] ?? null);

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
     * El logo ENCAJADO en una caja máxima, sin deformarlo.
     *
     * POR QUÉ NO BASTA CON PONER EL ANCHO
     * -----------------------------------
     * Porque entonces la altura la decide la imagen. Las facturas iban
     * con `<img width="100px">` y sin alto: un logo cuadrado medía 100
     * de alto, y el del talón —80— se llevaba la factura a una segunda
     * hoja. Una representación gráfica de dos páginas no es un problema
     * estético: es lo que se le entrega al cliente y lo que se lleva a
     * recaudo, y la segunda hoja se pierde.
     *
     * (De paso, `width="100px"` tampoco es un atributo HTML válido —el
     * atributo va en píxeles, sin unidad—. Aquí salen los dos ya
     * resueltos y sin sufijo.)
     *
     * POR QUÉ AQUÍ Y NO EN CSS
     * ------------------------
     * Porque con `max-height` el resultado hay que creérselo: depende
     * de cómo resuelva el motor la caja de la imagen dentro de la
     * celda. Calculándolo con el tamaño real del archivo, la altura que
     * sale está ACOTADA por construcción y se puede afirmar en una
     * prueba. Los topes de la factura están medidos; véase el bloque
     * `@php` de `gestisp/invoices/pdf.blade.php`.
     *
     * Nunca AGRANDA: un logo más pequeño que la caja se sirve tal cual,
     * porque estirarlo solo lo pixela.
     *
     * RECIBE LA RUTA, NO LA SUCURSAL
     * ------------------------------
     * Porque la plantilla ya tiene la ruta —se la pasa quien arma el
     * PDF— y en el mismo documento hacen falta DOS cajas de tamaños
     * distintos: la de la cabecera y la del talón.
     *
     * @param string|null $ruta lo que devolvió `logoPath()`
     * @return array{ruta: string, ancho: int, alto: int}|null
     */
    public static function logoBox(?string $ruta, int $anchoMaximo, int $altoMaximo): ?array
    {
        if (!$ruta || !is_file($ruta)) {
            return null;
        }

        $medidas = @getimagesize($ruta);

        // Sin poder medirla se sirve la caja máxima: mejor un logo algo
        // deformado que una factura partida en dos.
        if (!$medidas || $medidas[0] <= 0 || $medidas[1] <= 0) {
            return ['ruta' => $ruta, 'ancho' => $anchoMaximo, 'alto' => $altoMaximo];
        }

        $escala = min($anchoMaximo / $medidas[0], $altoMaximo / $medidas[1], 1);

        return [
            'ruta' => $ruta,
            'ancho' => max(1, (int) round($medidas[0] * $escala)),
            'alto' => max(1, (int) round($medidas[1] * $escala)),
        ];
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
