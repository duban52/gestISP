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
