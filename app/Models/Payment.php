<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modelo de Pago
 *
 * Representa un pago aplicado a una factura. Usa SoftDeletes: los
 * pagos nunca se eliminan físicamente, se marcan con deleted_at,
 * preservando la trazabilidad contable.
 *
 * Auditoría automática: los eventos created/updated/deleted del
 * modelo generan un registro en payment_audits con los valores
 * anteriores y nuevos, y recalculan el saldo pendiente de la
 * factura asociada.
 */
class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'contract_id',
        // Lote del cobro múltiple: agrupa los pagos que entraron en
        // una sola entrega de dinero.
        'payment_batch_id',
        'type', 'user_id', 'cash_register_id', 'payment_date',
        'amount', 'payment_method', 'status', 'reference_number',
        'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /**
     * Hooks del ciclo de vida del modelo.
     *
     * Cada cambio en un pago (crear, editar, eliminar) dispara:
     * 1. Un registro de auditoría con el antes y el después
     * 2. El recálculo del saldo pendiente de la factura
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($payment) {
            $payment->createAuditLog('created');
            $payment->updateInvoiceBalance();
        });

        static::updated(function ($payment) {
            $payment->createAuditLog('updated');
            $payment->updateInvoiceBalance();
        });

        static::deleted(function ($payment) {
            $payment->createAuditLog('deleted');
            $payment->updateInvoiceBalance();
        });
    }

    /** Factura sobre la que se aplicó el pago */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Caja registradora donde se recibió el pago (null si fue remoto) */
    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    /** Usuario que registró el pago */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crea el registro de auditoría del pago.
     *
     * Guarda los valores originales y los actuales del modelo,
     * junto con el usuario que realizó la acción.
     */
    public function createAuditLog(string $action): void
    {
        PaymentAudit::create([
            'payment_id' => $this->id,
            'action'     => $action,
            'old_values' => $this->getOriginal(),
            'new_values' => $this->getAttributes(),
            'user_id'    => auth()->id(),
        ]);
    }

    /**
     * Recalcula el saldo pendiente de la factura asociada.
     *
     * El cálculo vive en Invoice::recalcularSaldo() porque no solo
     * intervienen los pagos: las retenciones también abonan la
     * factura aunque no entren a caja.
     */
    public function updateInvoiceBalance(): void
    {
        $this->invoice?->recalcularSaldo();
    }

    /** Retenciones que llegaron con este pago. */
    public function retentions()
    {
        return $this->hasMany(PaymentRetention::class);
    }

    /** Lote de cobro al que pertenece (null si fue un cobro suelto). */
    public function batch()
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    /** Contrato del pago (directo en anticipos, vía factura si no). */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Suma de las retenciones que acompañaron este pago. */
    public function totalRetenciones(): float
    {
        return round((float) $this->retentions()->sum('amount'), 2);
    }

    /**
     * Valor total que canceló el cliente con esta operación:
     * el efectivo recibido más lo que retuvo para el Estado.
     */
    public function totalCancelado(): float
    {
        return round((float) $this->amount + $this->totalRetenciones(), 2);
    }
}
