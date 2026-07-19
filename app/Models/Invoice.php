<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use App\Billing\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Factura
 *
 * Representa una factura de un contrato: servicios del plan,
 * cargos adicionales y (patrón heredado, a eliminar en fase 4)
 * facturas vencidas absorbidas.
 *
 * Los estados válidos viven en App\Billing\Enums\InvoiceStatus;
 * nunca escribir strings de estado a mano.
 *
 * La generación de facturas vive en InvoiceController (fase 2 la
 * moverá a un servicio de dominio). Este modelo tuvo un segundo
 * generador (generateInvoices) distinto y roto — se eliminó junto
 * con los métodos muertos hasOverdueInvoices, canReceivePayment y
 * markForServiceSuspension.
 */
class Invoice extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'contract_id',
        'branch_id',
        'type',
        'prefix',
        'number',
        'full_number',
        'numbering_sequence_id',
        'user_id',
        'billed_period',
        'billed_period_short',
        'billed_month_name',
        'billed_year_month',
        'period_start',
        'period_end',
        'issue_date',
        'due_date',
        'suspension_date',
        'subtotal',
        'discount',
        'pending_invoice_amount',
        'tax',
        'total',
        'status',
        'service_suspension_warning',
        'service_suspension_date',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
        'issue_date' => 'date',
        'due_date' => 'date',
        'suspension_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'pending_invoice_amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        'service_suspension_warning' => 'boolean',
        'service_suspension_date' => 'datetime',
    ];

    /** Usuario que generó la factura */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Sucursal a la que pertenece la factura */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Ítems (conceptos) de la factura */
    public function invoice_items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    /** Contrato facturado */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Pagos aplicados a la factura */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /** Secuencia con la que se numeró la factura */
    public function numberingSequence()
    {
        return $this->belongsTo(InvoiceNumberingSequence::class, 'numbering_sequence_id');
    }

    /** Usuario que anuló la factura */
    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Número para mostrar: el formal si existe, o el id para las
     * facturas históricas anteriores a la numeración.
     */
    public function displayNumber(): string
    {
        return $this->full_number ?? (string) $this->id;
    }

    /**
     * Saldo pendiente real: total menos los pagos completados.
     * (pending_invoice_amount se recalcula con esta misma regla en
     * Payment::updateInvoiceBalance; este método es la fuente de
     * verdad al validar un pago.)
     */
    public function getPendingAmount()
    {
        return $this->total - $this->payments()
                ->where('status', PaymentStatus::Completed->value)
                ->sum('amount');
    }
}
