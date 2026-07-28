<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use App\Billing\Enums\NoteType;
use Illuminate\Database\Eloquent\Model;

/**
 * Nota crédito o débito sobre una factura.
 *
 * Documento con el que se corrige una factura ya emitida sin
 * modificarla: la crédito baja el saldo, la débito lo sube. Queda
 * como comprobante independiente, con su consecutivo, el motivo
 * normativo y la explicación de quien la emitió.
 */
class CreditDebitNote extends Model
{
    use Auditable;

    protected $fillable = [
        'branch_id',
        'invoice_id',
        'contract_id',
        'user_id',
        'type',
        'prefix',
        'number',
        'full_number',
        'concept_code',
        'concept_label',
        'reason',
        'issue_date',
        'subtotal',
        'tax',
        'total',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public const EMITIDA = 'Emitida';
    public const ANULADA = 'Anulada';

    // ==================== Relaciones ====================

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que la emitió */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // ==================== Ayudas ====================

    public function tipo(): NoteType
    {
        return NoteType::from($this->type);
    }

    public function getEtiquetaTipoAttribute(): string
    {
        return $this->tipo()->etiqueta();
    }

    /** ¿Sigue surtiendo efecto sobre la factura? */
    public function getVigenteAttribute(): bool
    {
        return $this->status === self::EMITIDA;
    }

    /**
     * Efecto sobre el saldo: negativo si lo disminuye.
     */
    public function getEfectoAttribute(): float
    {
        return $this->tipo()->disminuye()
            ? -1 * (float) $this->total
            : (float) $this->total;
    }
}
