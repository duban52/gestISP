<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Auditoría de Pagos
 *
 * Registro inmutable de cada acción sobre un pago (created,
 * updated, deleted). Guarda una fotografía de los valores antes
 * y después del cambio (casteados a array desde JSON) y el
 * usuario responsable.
 *
 * Estos registros se crean automáticamente desde los hooks del
 * modelo Payment y no deben modificarse ni eliminarse: son la
 * evidencia contable ante cualquier disputa o revisión.
 */
class PaymentAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'action',
        'old_values',
        'new_values',
        'user_id',
    ];

    /**
     * old_values y new_values se almacenan como JSON en la base
     * de datos y se exponen como arrays en PHP.
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /** Pago auditado */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /** Usuario que realizó la acción */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
