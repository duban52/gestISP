<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Material de Orden Técnica
 *
 * Registro del material consumido en la ejecución de una orden
 * técnica (instalación, reparación, traslado). Vincula la orden
 * con el material del catálogo, la cantidad usada y, para equipos,
 * el número de serie exacto instalado.
 *
 * Esta tabla es la trazabilidad entre bodega y campo: permite
 * saber qué serial quedó instalado en qué orden (y por tanto en
 * qué cliente/contrato).
 */
class TechnicalOrderMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'technical_order_id',
        'material_id',
        'quantity',
        'serial_number',
    ];

    /** Orden técnica en la que se usó el material */
    public function technicalOrder()
    {
        return $this->belongsTo(TechnicalOrder::class, 'technical_order_id');
    }

    /** Material del catálogo que se consumió */
    public function material()
    {
        return $this->belongsTo(Material::class, 'material_id');
    }
}
