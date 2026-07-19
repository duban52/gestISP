<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'nit',
        'name',
        'country',
        'department',
        'municipality',
        'address',
        'number_phone',
        'additional_number',
        'image',
        'moving_price',
        'reconnection_price',
        'message_custom_invoice',
        'observation',
    ];

    //Relación con usuarios
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_branch')->withPivot('role_id');
    }

    //Relación con Clientes
    public function clients()
    {
        return $this->hasMany(Client::class);
    }

    //Relación con Clientes
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    //Relación con Clientes
    public function plans()
    {
        return $this->hasMany(Plan::class);
    }

    //Relación con Contratos
    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    //Relacion con cajas
    public function cashRegister()
    {
        return $this->hasMany(CashRegister::class);
    }
    //Relacion con almacenes
    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    //Relación con olts
    public function olts(){
        return $this->hasMany(Olt::class);
    }

    //Relación con onts
    public function onts(){
        return $this->hasMany(Ont::class);
    }

    /**
     * Configuración de facturación de la sucursal. Usar
     * BranchBillingSetting::forBranch() cuando se necesite con
     * defaults garantizados.
     */
    public function billingSettings()
    {
        return $this->hasOne(BranchBillingSetting::class);
    }

}
