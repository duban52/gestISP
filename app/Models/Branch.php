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
        // Numeración de contratos de esta sucursal: letras que
        // anteceden al consecutivo (ENG → ENG000001) y último
        // número entregado.
        'contract_prefix',
        'contract_next_number',
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

    /**
     * Al crear una sucursal se le propone un prefijo de contrato a
     * partir de su nombre.
     *
     * La migración se lo puso a las sucursales que ya existían, pero
     * sin esto las que se crearan después quedarían sin prefijo y sus
     * contratos saldrían con el genérico CTR. Es solo una propuesta:
     * se puede cambiar al editar la sucursal.
     */
    protected static function booted(): void
    {
        static::creating(function (self $sucursal) {
            if (empty($sucursal->contract_prefix)) {
                $sucursal->contract_prefix = self::prefijoDesdeNombre($sucursal->name);
            }
        });
    }

    /**
     * Iniciales del nombre: "EasyNet Gómez Plata" produce EGP.
     */
    public static function prefijoDesdeNombre(?string $nombre): string
    {
        $ignoradas = ['de', 'del', 'la', 'las', 'los', 'el', 'y'];
        $letras = '';

        foreach (preg_split('/\s+/', trim((string) $nombre)) as $palabra) {
            if ($palabra === '' || in_array(mb_strtolower($palabra), $ignoradas, true)) {
                continue;
            }

            $letras .= mb_substr($palabra, 0, 1);
        }

        $letras = strtr(mb_strtoupper($letras), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);

        $letras = preg_replace('/[^A-Z0-9]/', '', $letras);

        return mb_substr($letras ?: 'CTR', 0, 5);
    }

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
