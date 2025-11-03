<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SrvProfile extends Model
{
    use HasFactory;
    protected $fillable = [
        'olt_id',
        'id_srv_profile',
        'name',
        'description'
    ];

    //Relación con la OLT, un srvProfile pertenece a una OLT

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }
}
