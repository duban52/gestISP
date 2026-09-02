<?php

namespace App\Models;

use App\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        // El NIT sigue aqui por compatibilidad: se imprime en siete
        // plantillas (facturas, recibo termico, correos, layout de
        // PDF). La fuente de verdad pasa a ser companies.document_number
        // y esta columna se retira cuando esas plantillas se hayan
        // migrado. Ver docs/Plan-Empresa-Sucursales-Grupos.md.
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
        /**
         * Una sucursal solo se ve desde su propia empresa.
         *
         * Va aqui y no con el trait BelongsToCompany porque ese trait
         * DERIVA company_id de branch_id al guardar, y una sucursal no
         * tiene branch_id: acabaria tomando la empresa del contexto y
         * pisando la que resuelve el puente del NIT, que corre en
         * `creating` —despues de `saving`—.
         */
        static::addGlobalScope('empresa', function (Builder $query) {
            $contexto = app(CurrentContext::class);

            if ($contexto->activo()) {
                $query->where($query->getModel()->qualifyColumn('company_id'), $contexto->companyId());
            }
        });

        static::creating(function (self $sucursal) {
            if (empty($sucursal->contract_prefix)) {
                $sucursal->contract_prefix = self::prefijoDesdeNombre($sucursal->name);
            }

            if (empty($sucursal->company_id)) {
                $sucursal->company_id = self::empresaDelNit($sucursal)?->id;
            }
        });

        // El NIT deja de escribirse a mano: sale de la empresa.
        //
        // La columna sigue existiendo porque se imprime en SIETE
        // plantillas (PDF de facturas y de pendientes, recibo termico,
        // correos, layout de PDF y el CRUD de sucursales). Mantenerla
        // sincronizada las deja funcionando sin tocarlas, y la fuente
        // de verdad pasa a ser companies.document_number.
        static::saving(function (self $sucursal) {
            if ($sucursal->company_id) {
                $nit = Company::whereKey($sucursal->company_id)->value('document_number');

                if ($nit) {
                    $sucursal->nit = $nit;
                }
            }
        });
    }

    /**
     * Empresa que corresponde al NIT de la sucursal, creandola si no
     * existe todavia.
     *
     * PUENTE DE COMPATIBILIDAD
     * ------------------------
     * El formulario de sucursales sigue pidiendo el NIT, porque la
     * pantalla de empresas aun no existe. Hasta que exista, este
     * enganche traduce ese NIT a una empresa: si ya hay una con ese
     * numero la reutiliza —que es lo que hace que varias sucursales de
     * la misma empresa queden agrupadas—, y si no la crea.
     *
     * Cuando la administracion de empresas este montada, la sucursal
     * pasara a elegir su empresa de una lista y esto se retira.
     *
     * Sin NIT no se puede resolver nada: se deja que falle la
     * restriccion de la base en vez de inventar una empresa vacia que
     * despues nadie sabria de quien es.
     */
    private static function empresaDelNit(self $sucursal): ?Company
    {
        if (empty($sucursal->nit)) {
            return null;
        }

        return Company::firstOrCreate(
            [
                'document_type_code' => '31',
                'document_number' => $sucursal->nit,
            ],
            [
                'legal_name' => $sucursal->name,
                'address' => $sucursal->address,
                'phone' => $sucursal->number_phone,
                'operation_mode' => Company::MODO_INDEPENDIENTE,
                'electronic_invoicing_enabled' => false,
                'active' => true,
            ],
        );
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

    /**
     * El logo, que ahora es de la EMPRESA.
     *
     * Se expone con el mismo nombre que tenia la columna para que los
     * ocho sitios que lo imprimen —PDF de facturas y de pendientes,
     * recibo termico, correos, layout de PDF, panel y las fichas de
     * sucursal— sigan funcionando sin tocarlos.
     *
     * La columna branches.image se conserva como respaldo hasta que
     * todas las empresas tengan el suyo; se retira despues.
     */
    public function getImageAttribute($valor)
    {
        return $this->company?->logo ?: $valor;
    }

    /**
     * Consulta sin la barrera de empresa.
     *
     * Hace falta en un sitio muy concreto: al resolver a que contextos
     * puede entrar un usuario. Ahi hay que ver las sucursales de TODAS
     * sus empresas, y el scope —que filtra por la empresa activa— las
     * escondaria justo cuando se necesitan.
     *
     * Fuera de eso, usarla es una decision consciente que hay que
     * poder justificar.
     */
    public function scopeSinFiltroDeEmpresa(Builder $query): Builder
    {
        return $query->withoutGlobalScope('empresa');
    }

    /**
     * La empresa a la que pertenece.
     *
     * Siempre tiene una: una sucursal huerfana obligaria a preguntar
     * "¿y si no tiene empresa?" en cada consulta del sistema.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
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
