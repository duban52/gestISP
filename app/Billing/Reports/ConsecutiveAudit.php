<?php

namespace App\Billing\Reports;

use App\Billing\Enums\InvoiceStatus;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Models\NumberingRange;
use App\Tenancy\CurrentContext;
use Illuminate\Support\Collection;

/**
 * Qué pasó con cada consecutivo autorizado por la DIAN.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * La DIAN autoriza un rango de numeración y espera poder pedir cuenta
 * de cada número: qué documento salió con él, o por qué no salió
 * ninguno. Un consecutivo reservado que no acabó en factura —o que
 * acabó en una que la DIAN rechazó— es un hueco que hay que poder
 * justificar, y hasta ahora la única forma de encontrarlos era
 * consultar la base a mano.
 *
 * El propio código lo reconocía en tres comentarios distintos («un
 * rechazo deja un hueco que hay que justificar») sin que hubiera dónde
 * verlos.
 *
 * QUÉ CUENTA COMO HUECO
 * ---------------------
 * El consecutivo se reserva ANTES de saber si la DIAN acepta, así que
 * hay tres formas de quemarlo:
 *
 *   · SIN DOCUMENTO. El número se reservó y no quedó factura: la
 *     transacción se revirtió después de avanzar el contador, o la
 *     factura se borró. Es el caso que peor pinta tiene y el que menos
 *     rastro deja.
 *   · RECHAZADO. Hay factura, pero la DIAN no la validó. No tiene
 *     valor fiscal; el número queda gastado igual.
 *   · ANULADO. Hay factura y se anuló. Legítimo, pero hay que poder
 *     decir por qué.
 *
 * SOLO LAS FACTURAS
 * -----------------
 * Las notas crédito y débito llevan su propia serie
 * (`note_numbering_sequences`), no consumen el rango que autoriza la
 * resolución. Meterlas aquí mezclaría dos numeraciones que la DIAN
 * mira por separado.
 *
 * NO SE RECORRE EL RANGO ENTERO
 * -----------------------------
 * Un rango autorizado puede ser de cinco millones de números. Solo se
 * examina lo USADO —de `range_start` al consecutivo actual—, que es lo
 * único de lo que hay algo que decir. Aun así hay un tope, porque un
 * `current_number` corrupto podría pedir un recorrido absurdo.
 */
class ConsecutiveAudit
{
    /** Estados de un consecutivo. */
    public const OK = 'ok';
    public const PENDIENTE = 'pendiente';
    public const RECHAZADO = 'rechazado';
    public const ANULADO = 'anulado';
    public const SIN_DOCUMENTO = 'sin_documento';

    /**
     * Cuántos consecutivos se examinan como máximo por rango.
     *
     * Es una red contra datos corruptos, no un límite de negocio: con
     * la facturación mensual de un ISP, lo usado de un rango no llega
     * a esta cifra en años. Si se alcanza, la pantalla lo dice en vez
     * de presentar un informe incompleto como si fuera completo.
     */
    private const TOPE_DE_EXAMEN = 50000;

    /**
     * Un informe por cada rango autorizado del alcance actual.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rangosAutorizados(): Collection
    {
        $contexto = app(CurrentContext::class);

        // LA EMPRESA SE FILTRA A MANO. `NumberingRange` no lleva el
        // scope global de `BelongsToCompany` —a diferencia de casi todo
        // lo demás—, así que aquí no hay barrera automática. Sin este
        // filtro, la pantalla enseñaría la numeración fiscal de otros
        // contribuyentes.
        //
        // Sin contexto activo no se devuelve nada: esta pantalla se
        // alcanza siempre desde una sesión, y un informe de numeración
        // sin saber de quién es no tiene lectura posible.
        if (!$contexto->activo()) {
            return collect();
        }

        $sucursales = $contexto->branchIds();

        return NumberingRange::with('resolution', 'branch')
            ->where('company_id', $contexto->companyId())
            // Los rangos sin sucursal son de toda la empresa.
            ->where(fn ($q) => $q->whereIn('branch_id', $sucursales)->orWhereNull('branch_id'))
            ->orderBy('prefix')
            ->get()
            ->map(fn (NumberingRange $rango) => $this->examinar($rango));
    }

    /**
     * @return array<string, mixed>
     */
    private function examinar(NumberingRange $rango): array
    {
        $desde = (int) $rango->range_start;
        $hasta = (int) $rango->current_number;

        // Nada emitido todavía: el contador arranca en 0 y no en
        // `range_start - 1`, así que hay que mirarlo explícitamente.
        $usados = $hasta >= $desde ? ($hasta - $desde + 1) : 0;
        $truncado = $usados > self::TOPE_DE_EXAMEN;

        if ($truncado) {
            $desde = $hasta - self::TOPE_DE_EXAMEN + 1;
        }

        $consecutivos = $usados > 0
            ? $this->detallar($rango, $desde, $hasta)
            : collect();

        $problemas = $consecutivos->whereNotIn('estado', [self::OK, self::PENDIENTE])->values();

        return [
            'rango' => $rango,
            'prefijo' => $rango->prefix,
            'resolucion' => $rango->resolution?->resolution_number,
            'vigencia' => $rango->resolution,
            'autorizado_desde' => (int) $rango->range_start,
            'autorizado_hasta' => (int) $rango->range_end,
            'consecutivo_actual' => (int) $rango->current_number,
            'usados' => $usados,
            'restantes' => $rango->restantes(),
            'por_agotarse' => $rango->porAgotarse(),
            'consecutivos' => $consecutivos,
            // Lo único que de verdad hay que mirar: lo que habría que
            // justificar si la DIAN preguntara.
            'problemas' => $problemas,
            'conteos' => [
                self::OK => $consecutivos->where('estado', self::OK)->count(),
                self::PENDIENTE => $consecutivos->where('estado', self::PENDIENTE)->count(),
                self::RECHAZADO => $consecutivos->where('estado', self::RECHAZADO)->count(),
                self::ANULADO => $consecutivos->where('estado', self::ANULADO)->count(),
                self::SIN_DOCUMENTO => $consecutivos->where('estado', self::SIN_DOCUMENTO)->count(),
            ],
            // Que la pantalla pueda decir que el informe está recortado
            // en vez de presentarlo como completo.
            'truncado' => $truncado,
            'examinados_desde' => $desde,
        ];
    }

    /**
     * Número a número, qué documento salió y cómo acabó.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function detallar(NumberingRange $rango, int $desde, int $hasta): Collection
    {
        // Del ALCANCE COMPLETO de empresa, no de la sucursal activa: un
        // consecutivo del rango es del rango, y buscarlo con un filtro
        // más estrecho que el que lo emitió haría aparecer huecos donde
        // solo hay una factura de otra sede.
        $facturas = Invoice::withoutGlobalScopes()
            ->where('prefix', $rango->prefix)
            ->whereBetween('number', [$desde, $hasta])
            ->with('contract.client')
            ->get()
            ->keyBy('number');

        $estados = ElectronicDocument::withoutGlobalScopes()
            ->whereIn('invoice_id', $facturas->pluck('id'))
            ->pluck('status', 'invoice_id');

        return collect(range($desde, $hasta))->map(function (int $numero) use ($facturas, $estados, $rango) {
            $factura = $facturas->get($numero);

            return [
                'numero' => $numero,
                'completo' => $rango->formatearNumero($numero),
                'factura' => $factura,
                'estado' => $this->estadoDe($factura, $factura ? $estados->get($factura->id) : null),
            ];
        });
    }

    /**
     * En qué acabó un consecutivo.
     *
     * El orden importa: una factura anulada Y rechazada se cuenta como
     * rechazada, porque es lo que la DIAN vio.
     */
    private function estadoDe(?Invoice $factura, ?string $estadoDian): string
    {
        if (!$factura) {
            return self::SIN_DOCUMENTO;
        }

        if ($estadoDian === ElectronicDocument::RECHAZADO) {
            return self::RECHAZADO;
        }

        if ($factura->status === InvoiceStatus::Anulada->value) {
            return self::ANULADO;
        }

        if ($estadoDian === ElectronicDocument::ACEPTADO) {
            return self::OK;
        }

        // Emitida y todavía en camino: firmada, enviada, o sin
        // transmitir. No es un problema salvo que lleve días así, y eso
        // ya lo dice el log de documentos.
        return self::PENDIENTE;
    }

    /** Etiquetas legibles, para la pantalla y para el PDF. */
    public static function etiquetas(): array
    {
        return [
            self::OK => 'Aceptado por la DIAN',
            self::PENDIENTE => 'Emitido, esperando validación',
            self::RECHAZADO => 'Rechazado por la DIAN',
            self::ANULADO => 'Anulado',
            self::SIN_DOCUMENTO => 'Sin documento',
        ];
    }
}
