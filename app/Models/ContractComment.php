<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Comentario/nota interna sobre un contrato.
 *
 * Bitácora de oficina y soporte: cada registro guarda el texto, el
 * contrato al que pertenece y el autor que lo escribió.
 */
class ContractComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'user_id',
        'body',
    ];

    /** Contrato sobre el que se hizo el comentario */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /** Usuario que escribió el comentario */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
