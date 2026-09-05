<?php

namespace App\Billing\Dian;

use App\Models\Company;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Models\NumberingRange;
use Milon\Barcode\Facades\DNS2DFacade;
use Throwable;

/**
 * Los datos DIAN de la representación gráfica de una factura.
 *
 * QUÉ ES LA REPRESENTACIÓN GRÁFICA
 * --------------------------------
 * El PDF que ve el cliente. La factura de verdad es el XML firmado —eso
 * es lo que se le manda a la DIAN y lo que tiene valor— pero el
 * adquirente recibe además una versión legible, y esa versión tiene que
 * llevar unos datos concretos para que valga:
 *
 *   · el CUFE del documento,
 *   · el código QR, que lleva a consultarlo en el catálogo de la DIAN,
 *   · la resolución que autorizó el rango, con su vigencia,
 *   · y el NIT del CONTRIBUYENTE.
 *
 * POR QUÉ ESTO NO SE ARMA EN LA VISTA
 * -----------------------------------
 * Porque venía armado ahí, y ahí es donde se torció: el CUFE estaba
 * escrito a mano en la plantilla —el mismo en todas las facturas— y la
 * «fecha de validación» imprimía la fecha de creación de la factura,
 * que es una fecha inventada. Los dos errores tienen la misma causa:
 * una plantilla no puede consultar nada, así que quien la escribe pone
 * lo que tiene a mano.
 *
 * Aquí se consulta de verdad, y si no hay documento electrónico se
 * devuelve null — que es lo correcto para las facturas internas, que
 * son la mayoría y no llevan nada de esto.
 *
 * EL NIT ES EL DE LA EMPRESA, NO EL DE LA SUCURSAL
 * ------------------------------------------------
 * El contribuyente es la empresa: un NIT. La sucursal es una sede suya.
 * La plantilla venía de cuando «sucursal» y «contribuyente» eran lo
 * mismo, y seguía imprimiendo `branch->nit` — que en una instalación
 * multiempresa puede no ser el del emisor.
 */
class GraphicRepresentation
{
    /**
     * Los datos DIAN de esta factura, o null si no es electrónica.
     *
     * @return array<string, mixed>|null
     */
    public function para(Invoice $factura): ?array
    {
        $documento = ElectronicDocument::withoutGlobalScopes()
            ->with(['resolution', 'company'])
            ->where('invoice_id', $factura->id)
            ->first();

        if (!$documento || blank($documento->cufe)) {
            return null;
        }

        $resolucion = $documento->resolution;
        $empresa = $documento->company;

        // El rango vive aparte de la resolución: una resolución puede
        // repartirse en varios rangos (uno por sede). Se busca el que
        // CONTIENE este número, no el activo — una factura vieja pudo
        // salir de un rango ya agotado, y su representación tiene que
        // seguir declarando el que de verdad la autorizó.
        $rango = $this->rangoDe($factura, $documento);

        return [
            'cufe' => $documento->cufe,
            'qr' => $this->qr($documento->qr_content),
            'qr_texto' => $documento->qr_content,

            // La fecha de validación REAL. Null mientras la DIAN no
            // haya contestado: antes venía impresa la fecha de
            // creación, que decía que estaba validada cuando no lo
            // estaba.
            'validado_en' => $documento->accepted_at,
            'estado' => $documento->status,
            'ambiente' => $documento->environment_code,

            'resolucion' => $resolucion ? [
                'numero' => $resolucion->resolution_number,
                'valida_desde' => $resolucion->valid_from,
                'valida_hasta' => $resolucion->valid_until,
                'prefijo' => $rango?->prefix,
                'desde' => $rango?->range_start,
                'hasta' => $rango?->range_end,
            ] : null,

            'emisor' => $empresa ? $this->emisor($empresa) : null,
        ];
    }

    /** El rango autorizado que contiene el número de esta factura. */
    private function rangoDe(Invoice $factura, ElectronicDocument $documento): ?NumberingRange
    {
        return NumberingRange::withoutGlobalScopes()
            ->where('company_id', $documento->company_id)
            ->where('prefix', $factura->prefix)
            ->where('range_start', '<=', (int) $factura->number)
            ->where('range_end', '>=', (int) $factura->number)
            ->first();
    }

    /**
     * El QR como imagen embebida.
     *
     * Va como `data:` y no como archivo porque el PDF se genera con
     * dompdf y un archivo suelto obliga a que exista, a que sea
     * accesible por URL y a limpiarlo después. Embebido viaja dentro
     * del propio PDF.
     *
     * Si la librería falla se devuelve null en vez de reventar: una
     * factura sin QR es un problema, pero una corrida mensual caída a
     * mitad es peor. El CUFE impreso sigue permitiendo consultarla.
     */
    private function qr(?string $contenido): ?string
    {
        if (blank($contenido)) {
            return null;
        }

        try {
            return 'data:image/png;base64,' . DNS2DFacade::getBarcodePNG($contenido, 'QRCODE', 4, 4);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function emisor(Company $empresa): array
    {
        return [
            'nombre' => $empresa->legal_name ?: $empresa->nombreVisible(),
            'nit' => $empresa->identificacion(),
        ];
    }
}
