<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use App\Billing\Enums\RetentionType;
use Illuminate\Database\Eloquent\Model;

/**
 * Retención practicada por el cliente sobre una factura.
 *
 * Ver App\Billing\Enums\RetentionType para el porqué contable. En
 * resumen: es parte del pago de la factura que el cliente consignó al
 * Estado en vez de a nosotros, y que después nos descontamos de
 * nuestros propios impuestos.
 */
class PaymentRetention extends Model
{
    use Auditable;

    protected $fillable = [
        'branch_id',
        'payment_id',
        'invoice_id',
        'contract_id',
        'user_id',
        'type',
        'concept_code',
        'concept_label',
        'base',
        'rate',
        'amount',
        'certificate_number',
        'notes',
    ];

    protected $casts = [
        'base' => 'decimal:2',
        'rate' => 'decimal:3',
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Tipo como enum (null si la fila trae un valor desconocido). */
    public function tipo(): ?RetentionType
    {
        return RetentionType::tryFrom((string) $this->type);
    }

    /** Nombre completo del tipo, para pantallas y reportes. */
    public function getTipoLegibleAttribute(): string
    {
        return $this->tipo()?->etiqueta() ?? (string) $this->type;
    }

    /** Nombre corto, para el recibo térmico donde el ancho manda. */
    public function getTipoCortoAttribute(): string
    {
        return $this->tipo()?->abreviatura() ?? strtoupper((string) $this->type);
    }

    /**
     * Descripción de una línea: "RteFuente 4% — Servicios en general".
     */
    public function getDescripcionAttribute(): string
    {
        $tarifa = rtrim(rtrim(number_format((float) $this->rate, 3, ',', '.'), '0'), ',');

        return trim(sprintf(
            '%s %s%%%s',
            $this->tipo_corto,
            $tarifa,
            $this->concept_label ? ' — ' . $this->concept_label : '',
        ));
    }
}
