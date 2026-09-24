<?php

namespace App\Billing\Dian;

use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
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
            $this->emision($empresa),
            $this->transporte($empresa),
            $this->contingencia($empresa),
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
            // La emisión apagada NO es un aviso: es un estado, y uno
            // perfectamente legítimo mientras se prepara la
            // habilitación. `dian:alertas` avisa de lo que va a
            // romperse —un certificado que caduca, un rango que se
            // agota—, y meter aquí «está apagada» le mandaría un correo
            // cada noche a quien todavía no ha encendido nada.
            ->reject(fn (array $paso) => $paso['clave'] === 'emision')
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

        // Autofirmado: firma bien, pero la DIAN lo rechaza. Es
        // BLOQUEANTE aunque el certificado esté ahí y vigente, porque
        // la pregunta que contesta esta revisión no es «¿se puede
        // firmar?» sino «¿se puede emitir?».
        //
        // Y va aquí, después de la vigencia, porque uno de pruebas
        // caducado es las dos cosas y lo primero que hay que decir es
        // que no sirve.
        if (!$vigente->sirveParaLaDian()) {
            return $this->paso('certificado', 'Certificado digital', false, true,
                sprintf(
                    '«%s» está AUTOFIRMADO: sirve para probar el flujo, pero la DIAN lo rechaza. '
                    . 'Hace falta uno de una entidad acreditada por la ONAC.',
                    $vigente->name,
                ));
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

    /**
     * ¿Hay algo que vaya a salir electrónico?
     *
     * POR QUÉ HACE FALTA ESTA COMPROBACIÓN
     * ------------------------------------
     * Una empresa puede tener el certificado, la resolución, el rango y
     * el servicio en verde, y no producir NI UN documento electrónico:
     * basta con que el interruptor de la empresa esté apagado, o con
     * que ningún contrato cuelgue de un grupo de afinidad electrónico.
     *
     * Y entonces `dian:set-de-pruebas` contesta «no hay documentos
     * firmados que mandar» sin decir por qué, que es exactamente el
     * punto en el que uno se queda mirando la pantalla. El diagnóstico
     * enseñaba todo lo demás menos esto.
     *
     * NO ES BLOQUEANTE: no impide emitir, es la razón por la que no hay
     * nada que emitir. Mezclarlo con los bloqueos haría que una empresa
     * lista pero sin contratos pareciera mal configurada.
     */
    private function emision(Company $empresa): array
    {
        if (!$empresa->electronic_invoicing_enabled) {
            return $this->paso('emision', 'Emisión electrónica', false, false,
                'Apagada: las facturas salen como documento interno. Enciéndala en pruebas con '
                . '`php artisan dian:habilitar --empresa=' . $empresa->id . ' --pruebas`.');
        }

        $grupos = \App\Models\AffinityGroup::withoutGlobalScopes()
            ->where('company_id', $empresa->id)
            ->where('requires_electronic_invoicing', true)
            ->pluck('id');

        if ($grupos->isEmpty()) {
            return $this->paso('emision', 'Emisión electrónica', false, false,
                'Encendida, pero ningún grupo de afinidad exige factura electrónica: '
                . 'todo sale como documento interno.');
        }

        $contratos = \App\Models\Contract::withoutGlobalScopes()
            ->whereIn('affinity_group_id', $grupos)
            ->count();

        if ($contratos === 0) {
            return $this->paso('emision', 'Emisión electrónica', false, false,
                'Encendida, pero no hay ningún contrato en los grupos electrónicos: '
                . 'no se va a generar ningún documento.');
        }

        return $this->paso('emision', 'Emisión electrónica', true, false, sprintf(
            'Encendida. %d contrato(s) en %d grupo(s) electrónico(s).',
            $contratos,
            $grupos->count(),
        ));
    }

    private function transporte(Company $empresa): array
    {
        // La URL sale del ambiente de la empresa y viene puesta de
        // fabrica: son las dos direcciones publicas de la DIAN, una para
        // habilitacion y otra para produccion. Solo falta si alguien las
        // vacio a proposito.
        $configuracion = $empresa->dianConfiguration;
        $endpoints = new \App\Billing\Dian\Transport\DianEndpoints();

        $url = $endpoints->para($configuracion?->environment_code, $configuracion?->endpoint_override);
        $personalizada = $endpoints->esPersonalizada($configuracion?->endpoint_override);
        $forzadoSimulado = config('dian.transport') === 'fake';

        if ($url === null) {
            return $this->paso('transporte', 'Servicio de la DIAN', false, true,
                'No hay URL del servicio configurada para este ambiente (config/dian.php).');
        }

        if ($forzadoSimulado) {
            return $this->paso('transporte', 'Servicio de la DIAN', false, false,
                'Hay URL, pero el transporte está forzado a simulado (DIAN_TRANSPORT=fake): no sale nada.');
        }

        // Si alguien la puso a mano, que se vea: una URL personalizada
        // que apunte al ambiente equivocado manda documentos de prueba
        // a produccion, o al reves.
        return $this->paso(
            'transporte',
            'Servicio de la DIAN',
            true,
            true,
            $personalizada ? $url . '  (personalizada)' : $url,
        );
    }

    // ==================== Apoyo ====================

    /**
     * Facturas expedidas en contingencia y todavia sin validar.
     *
     * El anexo da 48 HORAS para transmitirlas desde que se expiden.
     * Pasado ese plazo la factura queda expedida y sin validar, que es
     * un incumplimiento, y no hay forma de enterarse mirando pantallas:
     * el documento sigue ahi, en su cola, reintentando en silencio.
     *
     * Es AVISO y no bloqueo: el problema no impide emitir, y bloquear
     * la emision por documentos viejos agravaria la situacion.
     */
    private function contingencia(Company $empresa): array
    {
        $enContingencia = Invoice::withoutGlobalScopes()
            ->whereNotNull('contingency_at')
            ->whereHas('branch', fn ($q) => $q->where('company_id', $empresa->id))
            ->whereDoesntHave('electronicDocument', fn ($q) => $q
                ->where('status', ElectronicDocument::ACEPTADO))
            ->get(['id', 'contingency_at']);

        if ($enContingencia->isEmpty()) {
            return $this->paso('contingencia', 'Contingencia', true, false,
                'Ninguna factura pendiente de transmitir en contingencia.');
        }

        $vencidas = $enContingencia->filter(
            fn (Invoice $factura) => $factura->contingency_at->diffInHours(now()) >= 48,
        );

        if ($vencidas->isNotEmpty()) {
            return $this->paso('contingencia', 'Contingencia', false, false, sprintf(
                '%d factura(s) llevan MAS DE 48 HORAS expedidas en contingencia sin que la DIAN '
                . 'las valide. Es el plazo del anexo: revise la conexion con la DIAN.',
                $vencidas->count(),
            ));
        }

        return $this->paso('contingencia', 'Contingencia', false, false, sprintf(
            '%d factura(s) expedidas en contingencia esperan validacion. El plazo es de 48 horas '
            . 'desde que se expidieron.',
            $enContingencia->count(),
        ));
    }

    private function paso(string $clave, string $titulo, bool $ok, bool $bloqueante, string $detalle): array
    {
        return compact('clave', 'titulo', 'ok', 'bloqueante', 'detalle');
    }
}
