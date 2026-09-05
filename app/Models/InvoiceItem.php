<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
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
        'percentage_tax',
        'tax',
        'total'
    ];

    //Relación con factura
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
