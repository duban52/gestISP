<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una corrida de facturación.
 *
 * Lo escribe MonthlyBillingRun al terminar cada generación:
 * conteos y totales facturados de la sucursal en ese período.
 * Alimenta el reporte gerencial de facturación.
 */
class BillingRun extends Model
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'billed_year_month',
        'contracts_count',
        'generated_count',
        'skipped_count',
        'total_subtotal',
        'total_tax',
        'total_billed',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'total_subtotal' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_billed' => 'decimal:2',
    ];

    /** Sucursal de la corrida */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que ejecutó la generación */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
