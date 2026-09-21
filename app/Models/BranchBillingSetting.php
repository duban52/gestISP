<?php

namespace App\Models;

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
        'billing_mode',
        'billing_day',
        'due_days',
        'suspension_threshold',
        'suspension_days',
    ];

    protected $casts = [
        'proration_mode' => ProrationMode::class,
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

    /** ¿La sucursal prorratea el primer mes? */
    public function prorates(): bool
    {
        return $this->proration_mode === ProrationMode::Prorated;
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
