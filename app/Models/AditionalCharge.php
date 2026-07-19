<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cargo adicional de un contrato.
 *
 * Puede facturarse de dos formas:
 *
 * - De contado (installments_total NULL): el monto completo entra
 *   en la siguiente factura mensual y el cargo pasa a Facturado.
 *
 * - Diferido a cuotas (installments_total = N): cada generación
 *   mensual incluye una cuota ("cuota X/N") hasta completarlas.
 *   El cargo permanece en estado "pendiente" mientras queden
 *   cuotas, y pasa a Facturado con la última. La última cuota
 *   ajusta el redondeo para que la suma sea exacta.
 */
class AditionalCharge extends Model
{
    use HasFactory;

    protected $fillable = [
      'contract_id',
      'user_id',
      'description',
      'amount',
      'installments_total',
      'installments_billed',
      'status'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'installments_total' => 'integer',
        'installments_billed' => 'integer',
    ];

    //Relación con usuario
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Relación con contrato
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** ¿El cargo está diferido a cuotas? */
    public function isDeferred(): bool
    {
        return $this->installments_total !== null && $this->installments_total > 1;
    }

    /**
     * Valor de la cuota regular (todas menos la última).
     */
    public function installmentAmount(): float
    {
        return round($this->amount / $this->installments_total, 2);
    }

    /**
     * Valor de la cuota número $n. La última absorbe la diferencia
     * de redondeo para que la suma de cuotas sea exactamente el
     * monto del cargo.
     */
    public function amountForInstallment(int $n): float
    {
        $regular = $this->installmentAmount();

        if ($n < $this->installments_total) {
            return $regular;
        }

        return round($this->amount - $regular * ($this->installments_total - 1), 2);
    }
}
