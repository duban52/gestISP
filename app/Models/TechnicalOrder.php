<?php

namespace App\Models;

use App\Support\Geolocation;
use App\Support\OrderLocationCheck;
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
 *
 * Dónde se cerró (closing_*): el punto que reportó el dispositivo del
 * técnico al procesar la orden. Se compara contra la ubicación de la
 * vivienda para saber si el trabajo se hizo donde debía; ver
 * distanceToService().
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
        // Dónde estaba el técnico al cerrar la orden
        'closing_latitude',
        'closing_longitude',
        'closing_accuracy_m',
        'closing_located_at',
        'closing_location_error',
    ];

    protected $casts = [
        'closing_latitude' => 'decimal:7',
        'closing_longitude' => 'decimal:7',
        'closing_accuracy_m' => 'integer',
        'closing_located_at' => 'datetime',
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

    // ==================== Devoluciones del supervisor ====================

    /**
     * La última revisión del supervisor, sea cierre o devolución.
     *
     * Se ordena por id y no por created_at porque una orden puede ir y
     * volver varias veces el mismo minuto, y entonces las marcas de
     * tiempo empatan.
     */
    public function lastVerification(): ?TechnicalOrderVerification
    {
        return $this->verifications->sortByDesc('id')->first();
    }

    /**
     * Por qué le devolvieron la orden al técnico, o null si no viene
     * de una devolución.
     *
     * Se mira SOLO la última revisión: una orden que se devolvió, se
     * corrigió y se cerró no debe seguir mostrando aquel motivo como
     * si estuviera pendiente.
     */
    public function returnReason(): ?string
    {
        $ultima = $this->lastVerification();

        if (!$ultima || $ultima->status !== 'Pendiente') {
            return null;
        }

        return $ultima->comments ?: 'Sin motivo anotado';
    }

    // ==================== Ubicación del cierre ====================

    /** ¿El dispositivo del técnico llegó a dar un punto? */
    public function hasClosingLocation(): bool
    {
        return $this->closing_latitude !== null && $this->closing_longitude !== null;
    }

    /**
     * Metros entre donde se cerró la orden y donde está el servicio.
     *
     * Devuelve null cuando falta cualquiera de los dos puntos: no es lo
     * mismo "el técnico estaba lejos" que "no hay con qué comparar", y
     * confundirlos llevaría a marcar como sospechosas las órdenes de
     * todos los contratos que aún no se han ubicado.
     */
    public function distanceToService(): ?float
    {
        $contract = $this->contract;

        if (!$this->hasClosingLocation() || !$contract?->isGeolocated()) {
            return null;
        }

        return Geolocation::distanceInMeters(
            (float) $contract->latitude,
            (float) $contract->longitude,
            (float) $this->closing_latitude,
            (float) $this->closing_longitude,
        );
    }

    /** Contraste entre el punto de cierre y el del servicio. */
    public function locationCheck(): OrderLocationCheck
    {
        return OrderLocationCheck::for($this);
    }
}
