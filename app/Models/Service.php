<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use App\Tenancy\SharedAcrossBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use BelongsToCompany;
    use SharedAcrossBranches;

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
        'tax_classification',
        ];

    protected $casts = [
        'tax_classification' => \App\Billing\Enums\TaxClassification::class,
    ];

    /**
     * Como trata el IVA este servicio.
     *
     * Nunca null: los servicios anteriores a la clasificacion quedaron
     * migrados a partir de su tarifa, y el valor por defecto es
     * gravado.
     */
    public function clasificacion(): \App\Billing\Enums\TaxClassification
    {
        return $this->tax_classification ?? \App\Billing\Enums\TaxClassification::Gravado;
    }

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
