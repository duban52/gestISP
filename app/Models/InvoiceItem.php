<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        // De que cargo adicional salio este renglon, si vino de uno.
        // Es lo que permite devolver el cargo si la factura se anula.
        'aditional_charge_id',
        'description',
        // Copiados del servicio AL EMITIR, no leidos al vuelo: una
        // factura emitida tiene que seguir diciendo lo que decia
        // aunque manana se corrija el codigo del servicio.
        'product_code',
        'product_code_type',
        'unit_measure_code',
        'tax_classification',
        'quantity',
        'unit_price',
        // Descuento de esta linea. En el XML va como AllowanceCharge y
        // es lo que separa el precio de lista de la base gravable.
        'discount',
        'percentage_tax',
        'tax',
        'total'
    ];

    /** El cargo adicional del que salió este renglón, si vino de uno. */
    public function aditionalCharge()
    {
        return $this->belongsTo(AditionalCharge::class, 'aditional_charge_id');
    }

    //Relación con factura
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
