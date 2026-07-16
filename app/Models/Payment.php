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
        'invoice_id', 'user_id', 'cash_register_id', 'payment_date',
        'amount', 'payment_method', 'status', 'reference_number',
        'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
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
     * Suma solo los pagos con estado "completed" (excluye anulados
     * o en proceso) y actualiza pending_invoice_amount de la factura.
     */
    public function updateInvoiceBalance(): void
    {
        if ($this->invoice) {
            $totalPaid = $this->invoice->payments()
                ->where('status', 'completed')
                ->sum('amount');

            $this->invoice->pending_invoice_amount = $this->invoice->total - $totalPaid;
            $this->invoice->save();
        }
    }
}
