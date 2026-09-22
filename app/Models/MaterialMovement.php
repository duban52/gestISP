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
        // Los renglones de una misma operación. Vale el id del primero:
        // un equipo con serial genera un renglón por serial, pero el
        // movimiento es uno solo (ver la migración que lo introdujo).
        'operation_id',
        'warehouse_origin_id',
        'warehouse_destination_id',
        'material_id',
        'quantity',
        'unit_of_measurement',
        'type',
        'serial_number',
        'user_id',
        'reason',
        // Lo que costo cada unidad en ESTE ingreso. Es el historico que
        // permite auditar o rehacer el promedio ponderado del
        // inventario, que solo guarda el resultado.
        'purchase_unit_value',
        // De quién se compró y con qué factura entró. Solo en las
        // entradas: en un traslado o una salida no hay compra.
        'supplier',
        'invoice_number',
        'invoice_date',
    ];

    protected $casts = [
        'purchase_unit_value' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    protected static function booted(): void
    {
        // Un renglón sin operación sería un renglón que el historial
        // —que agrupa por operación— no enseñaría. Si nadie se la puso,
        // el movimiento es su propia operación.
        static::created(function (self $movimiento) {
            if (!$movimiento->operation_id) {
                $movimiento->forceFill(['operation_id' => $movimiento->id])->saveQuietly();
            }
        });
    }

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
