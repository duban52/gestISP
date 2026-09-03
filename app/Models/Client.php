<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Client extends Model
{
    use BelongsToCompany;

    use HasFactory, Notifiable;

    protected $fillable = [
        'company_id',
        // Sucursal de ORIGEN: donde se dio de alta. Opcional, porque
        // en panel consolidado no hay una activa. NO acota quien lo
        // ve: eso lo hace company_id.
        'branch_id',
        // Texto libre historico. Se conserva como red de seguridad
        // durante la transicion, igual que branches.nit; lo que vale
        // para la DIAN es document_type_code.
        'type_document',
        'document_type_code',
        'identity_number',
        'verification_digit',
        'name',
        'last_name',
        'type_client',
        'number_phone',
        'aditional_phone',
        'email',
        'birthday',
        'user_id',

        // ---- Datos fiscales (fase 8) ----
        'organization_type_code',
        // La direccion FISCAL, que no es la del contrato: esa es la
        // del servicio —donde esta instalada la antena— y un cliente
        // con tres contratos tiene tres de esas y una sola de esta.
        'fiscal_address',
        'department_dane_code',
        'municipality_dane_code',
        'postal_code',
        'country_code',
    ];

    /**
     * Responsabilidades fiscales del cliente.
     *
     * Tabla aparte y no una columna con comas: puede tener varias, el
     * XML las lista una a una, y una columna con separadores es lo que
     * hace que dentro de un año nadie sepa si el separador era coma o
     * punto y coma.
     */
    public function taxResponsibilities()
    {
        return $this->hasMany(ClientTaxResponsibility::class);
    }

    /** El nombre legible del tipo de documento, del catalogo. */
    public function tipoDocumento(): ?string
    {
        return \App\Models\FiscalCatalog::nombre(
            \App\Models\FiscalCatalog::TIPO_DOCUMENTO,
            $this->document_type_code,
        ) ?? $this->type_document;
    }

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
