<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimiento del saldo a favor de un contrato.
 *
 * Cada fila es un hecho: entró dinero a favor (un anticipo o el
 * excedente de una nota crédito) o se usó para pagar una factura.
 * El saldo disponible no se guarda: se calcula sumando el libro, así
 * nunca puede quedar desalineado con su historia.
 */
class AccountCredit extends Model
{
    use Auditable;

    /** Entra dinero a favor del cliente. */
    public const ENTRADA = 'entrada';

    /** Se consume para pagar una factura. */
    public const APLICACION = 'aplicacion';

    /** Orígenes posibles del saldo. */
    public const ORIGEN_ANTICIPO = 'anticipo';
    public const ORIGEN_NOTA_CREDITO = 'nota_credito';
    public const ORIGEN_AJUSTE = 'ajuste';

    protected $fillable = [
        'branch_id',
        'contract_id',
        'user_id',
        'movement',
        'origin',
        'amount',
        'invoice_id',
        'payment_id',
        'credit_debit_note_id',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function note()
    {
        return $this->belongsTo(CreditDebitNote::class, 'credit_debit_note_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** ¿Suma o resta saldo a favor? */
    public function getEsEntradaAttribute(): bool
    {
        return $this->movement === self::ENTRADA;
    }

    /** Valor con signo: positivo si suma, negativo si se consume. */
    public function getValorConSignoAttribute(): float
    {
        return $this->es_entrada ? (float) $this->amount : -1 * (float) $this->amount;
    }

    /** Nombre legible del origen. */
    public function getOrigenLegibleAttribute(): string
    {
        return match ($this->origin) {
            self::ORIGEN_ANTICIPO => 'Pago por adelantado',
            self::ORIGEN_NOTA_CREDITO => 'Excedente de nota crédito',
            self::ORIGEN_AJUSTE => 'Ajuste manual',
            default => $this->origin,
        };
    }
}
