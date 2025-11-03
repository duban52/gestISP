<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LineProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'olt_id',
        'id_line_profile',
        'name',
        'description'
    ];

    //Relación con la OLT, un lineProfile pertenece a una OLT

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

}
