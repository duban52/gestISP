<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'material_id',
        'quantity',
        'unit_of_measurement',
        'serial_number',
        // Lo que se pago DE VERDAD por estas existencias. En equipos es
        // exacto (una fila por serial); en consumibles es el promedio
        // ponderado de las compras. Ver App\Services\InventoryCosting.
        'purchase_unit_value',
    ];

    protected $casts = [
        'purchase_unit_value' => 'decimal:2',
    ];

    public function warehouse() {
        return $this->belongsTo(Warehouse::class);
    }

    public function material() {
        return $this->belongsTo(Material::class);
    }
}
