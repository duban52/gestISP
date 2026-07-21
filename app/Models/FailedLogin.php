<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Intento de inicio de sesión fallido.
 *
 * user_id puede ser nulo: si alguien intenta entrar con un correo
 * que no existe, igual se registra el intento con ese correo, que es
 * la señal de seguridad que interesa vigilar.
 */
class FailedLogin extends Model
{
    protected $fillable = [
        'user_id', 'email', 'ip_address', 'user_agent', 'attempted_at',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
