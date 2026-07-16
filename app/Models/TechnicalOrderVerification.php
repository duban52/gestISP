<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Verificación de Orden Técnica
 *
 * Registro de la revisión/aprobación de una orden técnica ejecutada:
 * quién la verificó, el estado resultante y las observaciones.
 * Funciona como control de calidad posterior al trabajo de campo
 * (por ejemplo, un supervisor validando que la instalación quedó
 * bien hecha y el material reportado coincide).
 */
class TechnicalOrderVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'technical_order_id',
        'verified_by',
        'status',
        'comments',
    ];

    /** Orden técnica verificada */
    public function technicalOrder()
    {
        return $this->belongsTo(TechnicalOrder::class, 'technical_order_id');
    }

    /** Usuario (supervisor) que realizó la verificación */
    public function verifiedByUser()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
