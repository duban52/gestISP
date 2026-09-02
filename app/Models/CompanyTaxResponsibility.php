<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una responsabilidad fiscal de la empresa (O-13, O-15, O-23...).
 *
 * Son varias por empresa, por eso van en tabla aparte y no en una
 * columna. Se guarda el CODIGO oficial y no su descripcion: el texto
 * cambia entre versiones del anexo tecnico y el codigo no.
 */
class CompanyTaxResponsibility extends Model
{
    protected $fillable = [
        'company_id',
        'responsibility_code',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
