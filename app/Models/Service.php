<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'name',
        'base_price',
        'tax_percentage',
        'user_id',
        'branch_id',

        // ---- Datos fiscales (deuda de la fase 8): las creo la
        // migracion pero no eran ni fillable, y ningun formulario las
        // ofrecia. El informe de completitud fiscal las exige sin que
        // hubiera manera de completarlas. ----
        'product_code',
        'product_code_type',
        'unit_measure_code',
        'tax_code',
        ];

    //Relación con usuarios
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Relación con planes
    public function plans()
    {
        return $this->belongsToMany(Plan::class);
    }
    //Relación con sucursales
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
