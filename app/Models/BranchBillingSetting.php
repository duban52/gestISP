<?php

namespace App\Models;

use App\Billing\Enums\BillingCycle;
use App\Billing\Enums\BillingMode;
use App\Billing\Enums\ProrationMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de facturación de una sucursal.
 *
 * Los servicios de facturación (InvoiceGenerator, OverdueProcessor)
 * SIEMPRE obtienen la configuración vía forBranch(), que crea la
 * fila con los defaults históricos si aún no existe — ninguna
 * sucursal necesita configuración previa para facturar.
 *
 * Editable desde el módulo de sucursales (branches.edit).
 */
class BranchBillingSetting extends Model
{
    protected $fillable = [
        'branch_id',
        'proration_mode',
        'proration_day',
        'billing_cycle',
        'billing_mode',
        'billing_day',
        'due_days',
        'suspension_threshold',
        'suspension_days',
    ];

    protected $casts = [
        'proration_mode' => ProrationMode::class,
        'proration_day' => 'integer',
        'billing_cycle' => BillingCycle::class,
        'billing_mode' => BillingMode::class,
        'billing_day' => 'integer',
        'due_days' => 'integer',
        'suspension_threshold' => 'integer',
        'suspension_days' => 'integer',
    ];

    /**
     * Defaults que reproducen el comportamiento histórico del
     * sistema (los valores que estaban como constantes en los
     * servicios antes de la fase 3).
     */
    public const DEFAULTS = [
        'proration_mode' => 'prorated',
        // 31 = siempre se prorratea, que es lo que el sistema venía
        // haciendo. Regalar días es una decisión comercial y la toma
        // alguien, no una actualización.
        'proration_day' => 31,
        // El mes en el que se corre: es lo que el sistema venía
        // haciendo, y actualizar no puede cambiarle a nadie el período
        // que le cobra a sus clientes.
        'billing_cycle' => 'current',
        // Manual: migrar no puede cambiarle el comportamiento a quien
        // ya venía facturando con el botón.
        'billing_mode' => 'manual',
        'billing_day' => null,
        'due_days' => 20,
        'suspension_threshold' => 2,
        'suspension_days' => 24,
    ];

    /** Sucursal a la que pertenece la configuración */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Configuración de una sucursal, creándola con los defaults
     * si no existe todavía.
     */
    public static function forBranch(int $branchId): self
    {
        return self::firstOrCreate(
            ['branch_id' => $branchId],
            self::DEFAULTS
        );
    }

    /**
     * El PRIMER DÍA del mes que hay que facturar hoy.
     *
     * Todo el período —el nombre del mes, las fechas de inicio y fin,
     * la clave `billed_year_month` que impide facturar dos veces— sale
     * de aquí y no del día de la corrida. Es lo que permite que una
     * sucursal cobre por adelantado sin tocar código.
     */
    public function mesFacturado(CarbonInterface $hoy): CarbonInterface
    {
        return ($this->billing_cycle ?? BillingCycle::EnCurso)->mesFacturado($hoy);
    }

    /** ¿La sucursal prorratea el primer mes? */
    public function prorates(): bool
    {
        return $this->proration_mode === ProrationMode::Prorated;
    }

    /** El último día del mes en que todavía se cobra la fracción. */
    public function diaLimiteDeProrrateo(): int
    {
        return (int) ($this->proration_day ?: 31);
    }

    /**
     * ¿A un contrato activado ESTE día le tocan días de cortesía?
     *
     * La regla vive aquí y no en el generador porque manda en DOS
     * sitios: en si se le factura el mes de su alta, y en si después
     * se le arrastran esos días. Escrita dos veces, acabarían
     * contradiciéndose y el regalo se cobraría el mes siguiente.
     *
     * Con mes completo no hay cortesía que valga: ahí se cobra el mes
     * entero, entre el día que entre.
     */
    public function haceCortesia(CarbonInterface $activacion): bool
    {
        return $this->prorates() && $activacion->day > $this->diaLimiteDeProrrateo();
    }

    /**
     * El primer día que se le puede cobrar a un contrato.
     *
     * Su activación, o —si le tocan días de cortesía— el primero del
     * mes siguiente: lo que va de la activación al fin de SU mes no se
     * cobra nunca, ni en esa factura ni arrastrado en la siguiente.
     */
    public function primerDiaCobrable(CarbonInterface $activacion): CarbonInterface
    {
        return $this->haceCortesia($activacion)
            ? $activacion->copy()->endOfMonth()->addDay()->startOfDay()
            : $activacion->copy()->startOfDay();
    }

    /** ¿Esta sucursal factura sola? */
    public function facturaSola(): bool
    {
        return $this->billing_mode instanceof BillingMode
            && $this->billing_mode->esAutomatico()
            && $this->billing_day !== null;
    }

    /**
     * ¿Hoy le toca correr la facturación a esta sucursal?
     *
     * EL DÍA 31 NO SE SALTA FEBRERO. Quien configura «el 31» está
     * diciendo «el último día del mes»; comparar el número a secas
     * dejaría sin facturar todos los meses cortos, y nadie se daría
     * cuenta hasta que el cliente reclamara. Por eso, si el día
     * configurado no existe en este mes, corre el último día que sí.
     */
    public function facturaHoy(CarbonInterface $dia): bool
    {
        if (!$this->facturaSola()) {
            return false;
        }

        $diaEfectivo = min($this->billing_day, $dia->daysInMonth);

        return $dia->day === $diaEfectivo;
    }
}
