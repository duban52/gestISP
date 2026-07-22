<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Orden Técnica
 *
 * Representa un trabajo de campo asociado a un contrato: instalación,
 * reparación, traslado, reconexión, etc. Es el eje del flujo operativo
 * entre oficina y técnicos:
 *
 * - Se crea manualmente (soporte/oficina) o automáticamente por el
 *   sistema (ej: orden de "Reconexión" generada al registrar el pago
 *   de un contrato suspendido, ver PaymentController::store).
 * - Se asigna a un técnico (user_assigned) que la ejecuta y reporta
 *   solución, observaciones y material usado (relación materials,
 *   que descuenta trazablemente de inventario por serial).
 * - Puede pasar por verificación de un supervisor (relación
 *   verifications) como control de calidad.
 *
 * Campos de texto del flujo:
 * - initial_comment:        contexto al crear la orden
 * - detail:                 detalle del trabajo solicitado
 * - observations_technical: reporte del técnico en campo
 * - client_observation:     comentario del cliente
 * - solution:               solución aplicada al cierre
 * - rejection_reason:       motivo si la orden fue rechazada
 * - images:                 evidencia fotográfica del trabajo
 */
class TechnicalOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'branch_id',
        'user_assigned',
        'type',
        'status',
        'rejection_reason',
        'detail',
        'observations_technical',
        'client_observation',
        'solution',
        'initial_comment',
        'images',
        'client_signature',
        'created_by',
    ];

    /** Contrato (cliente) al que pertenece el trabajo */
    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    /** Sucursal donde se ejecuta la orden */
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** Técnico asignado para ejecutar la orden */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'user_assigned');
    }

    /** Usuario que creó la orden (oficina/soporte) */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Materiales consumidos en la ejecución.
     * Para equipos, cada registro incluye el serial instalado.
     */
    public function materials()
    {
        return $this->hasMany(TechnicalOrderMaterial::class, 'technical_order_id');
    }

    /** Verificaciones de supervisión posteriores al cierre */
    public function verifications()
    {
        return $this->hasMany(TechnicalOrderVerification::class, 'technical_order_id');
    }
}
