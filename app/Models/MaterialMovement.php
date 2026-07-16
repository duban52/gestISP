<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de Movimiento de Material
 *
 * Registro histórico de cada movimiento de inventario entre
 * almacenes o hacia/desde el exterior. Es el libro de trazabilidad
 * del material: quién movió qué, cuánto, desde dónde, hacia dónde
 * y por qué motivo.
 *
 * Según el tipo de movimiento, los almacenes pueden ser null:
 * - Entrada (compra/ingreso): solo warehouse_destination_id
 * - Salida (baja/instalación): solo warehouse_origin_id
 * - Traslado: ambos almacenes presentes
 *
 * Para equipos, serial_number identifica la unidad exacta movida.
 */
class MaterialMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_origin_id',
        'warehouse_destination_id',
        'material_id',
        'quantity',
        'unit_of_measurement',
        'type',
        'serial_number',
        'user_id',
        'reason',
    ];

    /** Material movido */
    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /** Almacén de origen (null en entradas externas) */
    public function warehouseOrigin()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_origin_id');
    }

    /** Almacén de destino (null en salidas/bajas) */
    public function warehouseDestination()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_destination_id');
    }

    /** Usuario que realizó el movimiento */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
