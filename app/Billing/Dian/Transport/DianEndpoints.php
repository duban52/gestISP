<?php

namespace App\Billing\Dian\Transport;

use App\Models\DianConfiguration;

/**
 * A qué URL de la DIAN se le habla, según el ambiente.
 *
 * POR QUÉ NO ES UNA SOLA URL
 * --------------------------
 * Era, y estaba mal. La DIAN tiene **dos** servicios —uno para
 * habilitación y otro para producción— y en un sistema multiempresa
 * conviven las dos a la vez: la empresa A puede estar todavía pasando su
 * set de pruebas mientras la B ya factura de verdad.
 *
 * Con una sola URL global, encender la producción de una empresa habría
 * mandado a producción también los documentos de prueba de la otra. Por
 * eso la URL sale del **ambiente del documento**, que ya se congela en
 * `electronic_documents.environment_code` cuando se emite.
 *
 * SON CONSTANTES, PERO CONFIGURABLES
 * ----------------------------------
 * Las dos URL son públicas y no cambian entre contribuyentes, así que
 * vienen puestas de fábrica: nadie tiene que averiguar nada ni copiar
 * nada de ningún sitio. Están en `config/dian.php` por si la DIAN las
 * mueve algún día — que ya lo ha hecho.
 *
 * ⚠️ La de **habilitación** está confirmada. La de **producción** sigue
 * el mismo patrón que las URL de catálogo del anexo (`vpfe-hab` frente a
 * `vpfe`), pero **conviene confirmarla** antes de encender producción.
 *
 * EL `?wsdl` NO ES EL ENDPOINT
 * ----------------------------
 * Esa dirección devuelve la *definición* del servicio, para que una
 * herramienta la lea. Los documentos se mandan a la URL **sin** ese
 * sufijo. Es un error fácil de cometer —la DIAN publica la del `?wsdl`—
 * y aquí se corrige solo.
 */
class DianEndpoints
{
    /**
     * La URL que le toca a un documento.
     *
     * Se decide en tres pasos, del mas concreto al mas general:
     *
     *   1. Lo que la EMPRESA tenga puesto en su configuracion. Es el
     *      escape para cuando la DIAN mueve la URL o cuando esa empresa
     *      pasa por un proveedor tecnologico.
     *   2. El override global del `.env`, para toda la instalacion.
     *   3. La URL publica del ambiente. Es el caso normal.
     *
     * @param  string|null  $ambiente  '1' produccion, '2' pruebas
     * @param  string|null  $deLaEmpresa  `dian_configurations.endpoint_override`
     */
    public function para(?string $ambiente, ?string $deLaEmpresa = null): ?string
    {
        if (filled($deLaEmpresa)) {
            return $this->limpiar($deLaEmpresa);
        }

        if (filled($forzado = config('dian.endpoint'))) {
            return $this->limpiar($forzado);
        }

        $url = $ambiente === DianConfiguration::PRODUCCION
            ? config('dian.endpoints.produccion')
            : config('dian.endpoints.habilitacion');

        return filled($url) ? $this->limpiar($url) : null;
    }

    /** ¿La URL que se va a usar la puso alguien a mano? */
    public function esPersonalizada(?string $deLaEmpresa = null): bool
    {
        return filled($deLaEmpresa) || filled(config('dian.endpoint'));
    }

    /**
     * La de habilitación.
     *
     * El set de pruebas va SIEMPRE ahí, aunque la empresa ya esté en
     * producción: es el trámite de habilitación.
     */
    public function habilitacion(?string $deLaEmpresa = null): ?string
    {
        if (filled($deLaEmpresa)) {
            return $this->limpiar($deLaEmpresa);
        }

        if (filled($forzado = config('dian.endpoint'))) {
            return $this->limpiar($forzado);
        }

        $url = config('dian.endpoints.habilitacion');

        return filled($url) ? $this->limpiar($url) : null;
    }

    /** ¿Hay a dónde transmitir en ese ambiente? */
    public function hayPara(?string $ambiente, ?string $deLaEmpresa = null): bool
    {
        return $this->para($ambiente, $deLaEmpresa) !== null;
    }

    /**
     * Quita el `?wsdl` si alguien pegó esa dirección.
     *
     * Es la que publica la DIAN y la que uno copia sin pensarlo, pero
     * apunta a la DEFINICIÓN del servicio, no al servicio. Mandar el
     * documento ahí no falla de forma evidente: contesta con el WSDL, y
     * el error resultante no dice nada de esto.
     */
    private function limpiar(string $url): string
    {
        return rtrim(preg_replace('/\?wsdl.*$/i', '', trim($url)) ?? '', '/');
    }
}
