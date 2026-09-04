<?php

namespace App\Billing\Dian;

use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\NumberingRange;
use App\Reports\FiscalCompletenessReport;

/**
 * ¿Puede esta empresa emitir facturas electrónicas?
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Porque hasta ahora la respuesta estaba repartida en seis sitios: los
 * datos fiscales los sabe el informe de completitud, el certificado lo
 * sabe su modelo, la vigencia de la resolución el suyo, y si queda
 * rango el suyo. Nadie los juntaba, así que la única forma de saber si
 * se podía emitir era intentarlo.
 *
 * Y ese es exactamente el momento en que no se puede fallar: un
 * consecutivo autorizado que se gasta en un documento que la DIAN
 * rechaza deja un hueco que hay que justificar.
 *
 * LO QUE NO HACE
 * --------------
 * No arregla nada ni cambia nada. Solo mira y contesta. Encender la
 * producción es otra cosa y tiene su propio sitio —con este diagnóstico
 * como guardián.
 *
 * BLOQUEANTE NO ES LO MISMO QUE PENDIENTE
 * ---------------------------------------
 * Un certificado que caduca en veinte días no impide emitir hoy, pero
 * hay que verlo. Un certificado caducado sí impide. La diferencia está
 * marcada en cada comprobación, porque mezclarlas hace que los avisos
 * de verdad se pierdan entre los que no lo son.
 */
class DianReadiness
{
    /**
     * Días de antelación con los que se avisa de un vencimiento.
     *
     * Renovar un certificado ante una entidad acreditada no es
     * inmediato: si el aviso llega el día antes, ya es tarde.
     */
    public const DIAS_DE_AVISO = 30;

    /**
     * Revisa todo y devuelve la lista de comprobaciones.
     *
     * @return array<int, array{clave: string, titulo: string, ok: bool, bloqueante: bool, detalle: string}>
     */
    public function revisar(Company $empresa): array
    {
        return [
            $this->datosFiscales($empresa),
            $this->configuracion($empresa),
            $this->certificado($empresa),
            $this->resolucion($empresa),
            $this->rango($empresa),
            $this->setDePruebas($empresa),
            $this->transporte(),
        ];
    }

    /** ¿Está todo lo BLOQUEANTE resuelto? */
    public function puedeEmitir(Company $empresa): bool
    {
        return $this->bloqueos($empresa) === [];
    }

    /**
     * Solo lo que impide emitir, sin lo que es un simple aviso.
     *
     * @return array<int, string>
     */
    public function bloqueos(Company $empresa): array
    {
        return collect($this->revisar($empresa))
            ->filter(fn (array $paso) => $paso['bloqueante'] && !$paso['ok'])
            ->pluck('detalle')
            ->values()
            ->all();
    }

    /**
     * Los avisos: no impiden emitir, pero hay que verlos.
     *
     * @return array<int, string>
     */
    public function avisos(Company $empresa): array
    {
        return collect($this->revisar($empresa))
            ->filter(fn (array $paso) => !$paso['bloqueante'] && !$paso['ok'])
            ->pluck('detalle')
            ->values()
            ->all();
    }

    // ==================== Las comprobaciones ====================

    private function datosFiscales(Company $empresa): array
    {
        // Se reutiliza el informe de completitud en vez de repetir la
        // lista de campos: si mañana el XML exige uno más, se añade en
        // un sitio y aquí se entera solo.
        $faltan = (new FiscalCompletenessReport())->empresa()['faltan'];

        return $this->paso(
            'datos_fiscales',
            'Datos fiscales de la empresa',
            $faltan === [],
            bloqueante: true,
            detalle: $faltan === []
                ? 'Completos.'
                : 'Faltan: ' . implode(', ', $faltan) . '.',
        );
    }

    private function configuracion(Company $empresa): array
    {
        $configuracion = $empresa->dianConfiguration;

        if (!$configuracion) {
            return $this->paso('configuracion', 'Configuración DIAN', false, true,
                'No existe. Sin el identificador del software y su PIN no se puede armar el XML.');
        }

        $faltan = array_keys(array_filter([
            'identificador del software' => blank($configuracion->software_id),
            'PIN del software' => blank($configuracion->software_pin),
        ]));

        return $this->paso(
            'configuracion',
            'Configuración DIAN',
            $faltan === [],
            bloqueante: true,
            detalle: $faltan === []
                ? sprintf('Ambiente: %s.', $configuracion->estaEnProduccion() ? 'producción' : 'pruebas')
                : 'Falta el ' . implode(' y el ', $faltan) . '.',
        );
    }

    private function certificado(Company $empresa): array
    {
        $certificados = DianCertificate::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('active', true)
            ->get();

        $vigente = $certificados->first(fn (DianCertificate $c) => $c->vigente());

        if (!$vigente) {
            $caducado = $certificados->isNotEmpty();

            return $this->paso('certificado', 'Certificado digital', false, true,
                $caducado
                    ? 'El certificado está caducado o fuera de vigencia. Firmar con él produce documentos que la DIAN rechaza.'
                    : 'No hay ningún certificado cargado: sin él no se puede firmar.');
        }

        // Está y sirve. Pero si le quedan pocos días hay que avisarlo:
        // renovar ante una entidad acreditada lleva tiempo.
        $dias = $vigente->diasParaCaducar();

        if ($dias !== null && $dias <= self::DIAS_DE_AVISO) {
            return $this->paso('certificado', 'Certificado digital', false, false,
                sprintf('«%s» caduca en %d día(s). Renuévelo antes: no es inmediato.', $vigente->name, $dias));
        }

        return $this->paso('certificado', 'Certificado digital', true, true,
            sprintf('«%s», vigente%s.', $vigente->name, $dias !== null ? " ({$dias} días)" : ''));
    }

    private function resolucion(Company $empresa): array
    {
        $resoluciones = DianResolution::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('active', true)
            ->where('document_type_code', DianResolution::FACTURA)
            ->get();

        $vigente = $resoluciones->first(fn (DianResolution $r) => $r->vigente());

        if (!$vigente) {
            return $this->paso('resolucion', 'Resolución de numeración', false, true,
                $resoluciones->isNotEmpty()
                    ? 'La resolución está fuera de vigencia: no autoriza nada, por muchos consecutivos que le queden.'
                    : 'No hay ninguna resolución registrada.');
        }

        $dias = $vigente->diasDeVigencia();

        if ($dias !== null && $dias <= self::DIAS_DE_AVISO) {
            return $this->paso('resolucion', 'Resolución de numeración', false, false,
                sprintf('La resolución %s vence en %d día(s). Solicite la siguiente.', $vigente->resolution_number, $dias));
        }

        return $this->paso('resolucion', 'Resolución de numeración', true, true,
            sprintf('%s, vigente.', $vigente->resolution_number));
    }

    private function rango(Company $empresa): array
    {
        $rangos = NumberingRange::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('active', true)
            ->with('resolution')
            ->get()
            ->filter(fn (NumberingRange $rango) => $rango->resolution?->vigente());

        if ($rangos->isEmpty()) {
            return $this->paso('rango', 'Rango de numeración', false, true,
                'No hay ningún rango autorizado y vigente: no se puede numerar una factura electrónica.');
        }

        $agotandose = $rangos->first(fn (NumberingRange $rango) => $rango->porAgotarse());

        if ($agotandose) {
            return $this->paso('rango', 'Rango de numeración', false, false,
                sprintf(
                    'Al prefijo %s le quedan %d número(s). Pida el siguiente rango antes de agotarlo.',
                    $agotandose->prefix,
                    $agotandose->restantes(),
                ));
        }

        $total = $rangos->sum(fn (NumberingRange $rango) => $rango->restantes());

        return $this->paso('rango', 'Rango de numeración', true, true,
            sprintf('%s número(s) disponibles.', number_format($total, 0, ',', '.')));
    }

    private function setDePruebas(Company $empresa): array
    {
        $configuracion = $empresa->dianConfiguration;

        if ($configuracion?->enabled_at) {
            return $this->paso('set_de_pruebas', 'Habilitación', true, true,
                'Aprobada el ' . $configuracion->enabled_at->format('d/m/Y') . '.');
        }

        // El set de pruebas no es bloqueante para EMITIR en pruebas —de
        // hecho es lo que hay que hacer en pruebas—, pero sí para pasar
        // a producción. Se marca como bloqueante porque «poder emitir»
        // aquí significa emitir de verdad.
        return $this->paso('set_de_pruebas', 'Habilitación', false, true,
            blank($configuracion?->test_set_id)
                ? 'Falta el identificador del set de pruebas que asigna la DIAN.'
                : 'El set de pruebas todavía no está aprobado.');
    }

    private function transporte(): array
    {
        $hayEndpoint = filled(config('dian.endpoint'));
        $forzadoSimulado = config('dian.transport') === 'fake';

        if (!$hayEndpoint) {
            return $this->paso('transporte', 'Servicio de la DIAN', false, true,
                'No hay URL configurada (DIAN_ENDPOINT). La expone la propia DIAN en el catálogo del facturador.');
        }

        if ($forzadoSimulado) {
            return $this->paso('transporte', 'Servicio de la DIAN', false, false,
                'Hay URL, pero el transporte está forzado a simulado (DIAN_TRANSPORT=fake): no sale nada.');
        }

        return $this->paso('transporte', 'Servicio de la DIAN', true, true, 'Configurado.');
    }

    // ==================== Apoyo ====================

    private function paso(string $clave, string $titulo, bool $ok, bool $bloqueante, string $detalle): array
    {
        return compact('clave', 'titulo', 'ok', 'bloqueante', 'detalle');
    }
}
