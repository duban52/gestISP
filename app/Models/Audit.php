<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de auditoría polimórfico.
 *
 * Lo escriben automáticamente los modelos que usan el trait
 * App\Billing\Concerns\Auditable en cada created/updated/deleted:
 * usuario, IP, acción y valores antes/después.
 */
class Audit extends Model
{
    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'user_id',
        'ip',
        'action',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /** Modelo auditado */
    public function auditable()
    {
        return $this->morphTo();
    }

    /** Usuario que realizó la acción (null en procesos de consola) */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
