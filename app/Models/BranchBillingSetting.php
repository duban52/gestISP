<?php

namespace App\Models;

use App\Billing\Enums\ProrationMode;
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
        'due_days',
        'suspension_threshold',
        'suspension_days',
    ];

    protected $casts = [
        'proration_mode' => ProrationMode::class,
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
}
