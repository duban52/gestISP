<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Client extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'branch_id',
        'type_document',
        'identity_number',
        'name',
        'last_name',
        'type_client',
        'number_phone',
        'aditional_phone',
        'email',
        'birthday',
        'user_id',
    ];

    //Relación con la tabla sucursales
    public function branch(){
        return $this->belongsTo(Branch::class);
    }

    //Relación con usuarios
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Relación con contratos
    public function contracts(){
        return $this->hasMany(Contract::class);
    }

    // ==================== Notificaciones ====================

    /**
     * Nombre completo del cliente, para los saludos.
     */
    public function fullName(): string
    {
        return trim($this->name . ' ' . $this->last_name);
    }

    /**
     * Dirección de correo a la que se le notifica.
     */
    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * Número al que se le envía WhatsApp (el principal; el
     * adicional queda de respaldo por si el principal está vacío).
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->number_phone ?: $this->aditional_phone;
    }
}
