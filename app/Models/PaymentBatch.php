<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Lote de cobro: varios pagos recibidos en una sola operación.
 *
 * Agrupa los pagos que entraron juntos (alguien que paga el servicio
 * de varios familiares) sin mezclarlos: cada pago sigue siendo suyo,
 * con su factura, su contrato y su recibo.
 */
class PaymentBatch extends Model
{
    use Auditable;

    protected $fillable = [
        'branch_id',
        'user_id',
        'cash_register_id',
        'payer_name',
        'payer_document',
        'payer_phone',
        'payment_method',
        'reference_number',
        'total_amount',
        'total_retentions',
        'payments_count',
        'contracts_count',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'total_retentions' => 'decimal:2',
    ];

    /** Pagos que se recibieron en este lote. */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * Número visible del lote. No lleva consecutivo propio: el lote es
     * un agrupador operativo, no un documento fiscal. Los documentos
     * son los recibos de cada pago.
     */
    public function getNumeroVisibleAttribute(): string
    {
        return 'L-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /** Quién entregó el dinero (o "—" si no se anotó). */
    public function getPagadorAttribute(): string
    {
        return $this->payer_name ?: '—';
    }
}
