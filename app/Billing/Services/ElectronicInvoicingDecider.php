<?php

namespace App\Billing\Services;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;

/**
 * El único sitio donde se decide si una factura es electrónica.
 *
 * POR QUÉ UN SERVICIO PARA UNA CONDICIÓN
 * --------------------------------------
 * Porque en cuanto esa condición se escriba en dos sitios, un día
 * dirán cosas distintas — y el día que lo hagan, la consecuencia es
 * que un documento sale por el camino equivocado. Eso no se descubre
 * en pruebas: se descubre cuando alguien revisa.
 *
 * Ningún otro sitio del sistema toma esta decisión. Si hace falta
 * saberla, se pregunta aquí.
 *
 * LA REGLA
 * --------
 * Hacen falta las TRES cosas a la vez:
 *
 *   1. La EMPRESA tiene la facturación electrónica encendida.
 *      Es el interruptor general: una empresa que todavía no está
 *      habilitada no emite electrónicamente nada, diga lo que diga el
 *      grupo de sus contratos.
 *
 *   2. El GRUPO DE AFINIDAD del contrato la exige.
 *      Es la clasificación contrato a contrato.
 *
 *   3. La CONFIGURACIÓN DIAN está en producción Y habilitada.
 *      Añadida en la fase 10. No basta con cambiar el ambiente: sin
 *      la habilitación aprobada no se ha pasado el set de pruebas, y
 *      emitir así es emitir documentos que van a ser rechazados.
 *
 * SE PREGUNTA UNA VEZ, AL EMITIR
 * ------------------------------
 * El resultado se congela en `invoices.document_kind`. Recalcularlo al
 * vuelo significaría que la misma factura puede responder cosas
 * distintas según cuándo se pregunte —basta con que alguien cambie el
 * grupo del contrato— y en un documento ya emitido eso es inaceptable.
 *
 * Para saber qué es una factura YA emitida se mira su columna, no este
 * servicio.
 */
class ElectronicInvoicingDecider
{
    /** Los dos caminos posibles. */
    public const INTERNO = 'internal';
    public const ELECTRONICO = 'electronic';

    /**
     * Qué tipo de documento le corresponde a un contrato AHORA.
     *
     * Se llama al emitir. Después, lo que vale es lo que quedó
     * guardado en la factura.
     */
    public function tipoPara(Contract $contrato): string
    {
        return $this->esElectronico($contrato) ? self::ELECTRONICO : self::INTERNO;
    }

    /**
     * ¿Los documentos de este contrato son electrónicos?
     */
    public function esElectronico(Contract $contrato): bool
    {
        $empresa = $this->empresaDe($contrato);

        if (!$empresa || !$empresa->electronic_invoicing_enabled) {
            return false;
        }

        if ($contrato->affinityGroup?->requires_electronic_invoicing !== true) {
            return false;
        }

        // ---- Tercera condición (fase 10) ----
        //
        // La empresa tiene que estar en PRODUCCIÓN y con la
        // habilitación aprobada. No basta con haber cambiado el
        // ambiente: sin `enabled_at` no se ha pasado el set de pruebas,
        // y emitir en producción sin haberlo pasado es emitir
        // documentos que la DIAN va a rechazar.
        if (! $empresa->dianConfiguration?->estaEnProduccion()) {
            return false;
        }

        return true;
    }

    /**
     * Motivo por el que una factura NO es electrónica.
     *
     * Para pantallas de diagnóstico. Devuelve null cuando sí lo es.
     * Existe porque «no salió electrónica» sin decir por qué obliga a
     * revisar tres sitios distintos.
     */
    public function motivoInterno(Contract $contrato): ?string
    {
        $empresa = $this->empresaDe($contrato);

        if (!$empresa) {
            return 'El contrato no tiene empresa.';
        }

        if (!$empresa->electronic_invoicing_enabled) {
            return 'La empresa todavía no tiene activada la facturación electrónica.';
        }

        if ($contrato->affinityGroup === null) {
            return 'El contrato no tiene grupo de afinidad asignado.';
        }

        if (!$contrato->affinityGroup->requires_electronic_invoicing) {
            return sprintf(
                'El grupo «%s» emite documento interno.',
                $contrato->affinityGroup->name,
            );
        }

        $configuracion = $empresa->dianConfiguration;

        if (!$configuracion) {
            return 'La empresa no tiene configuración de facturación electrónica.';
        }

        if ($configuracion->environment_code !== \App\Models\DianConfiguration::PRODUCCION) {
            return 'La empresa está en ambiente de pruebas.';
        }

        if ($configuracion->enabled_at === null) {
            return 'La habilitación todavía no está aprobada.';
        }

        return null;
    }

    /**
     * ¿Una factura YA EMITIDA es electrónica?
     *
     * Lee la columna congelada, no vuelve a decidir. Es el método que
     * debe usar todo lo que consulte una factura existente.
     */
    public function facturaEsElectronica(Invoice $factura): bool
    {
        return $factura->document_kind === self::ELECTRONICO;
    }

    private function empresaDe(Contract $contrato): ?Company
    {
        // withoutGlobalScopes: esto corre también desde la corrida
        // mensual y desde comandos, donde no hay contexto establecido.
        return Company::withoutGlobalScopes()->find(
            $contrato->company_id
                ?? $contrato->branch?->company_id,
        );
    }
}
