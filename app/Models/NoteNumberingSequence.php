<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Consecutivo de las notas crédito/débito de una sucursal.
 *
 * Una secuencia por sucursal y tipo de nota: las notas crédito y las
 * débito llevan numeraciones independientes, como exige la normativa.
 */
class NoteNumberingSequence extends Model
{
    protected $fillable = [
        'branch_id',
        'type',
        'prefix',
        'current_number',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
