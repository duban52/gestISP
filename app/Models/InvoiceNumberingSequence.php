<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Secuencia de numeración de facturas de una sucursal.
 *
 * Soporta los datos de una resolución DIAN (número, vigencia,
 * rango autorizado) aunque la numeración inicial sea interna.
 * El consecutivo SOLO debe incrementarse a través de
 * App\Billing\Services\InvoiceNumerator, que bloquea la fila.
 */
class InvoiceNumberingSequence extends Model
{
    protected $fillable = [
        'branch_id',
        'prefix',
        'resolution_number',
        'valid_from',
        'valid_until',
        'range_start',
        'range_end',
        'current_number',
        'active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'current_number' => 'integer',
        'active' => 'boolean',
    ];

    /** Sucursal dueña de la secuencia */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Facturas numeradas con esta secuencia */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'numbering_sequence_id');
    }
}
