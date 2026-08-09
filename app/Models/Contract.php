<?php

namespace App\Models;

use App\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        // Número visible del contrato (consecutivo por sucursal:
        // ENG000123). El id sigue siendo el identificador interno.
        'contract_number',
        'branch_id',
        'client_id',
        'plan_id',
        'neighborhood',
        'address',
        // Punto exacto de la vivienda. Es opcional: hay contratos
        // vivos desde antes de que existiera el mapa.
        'latitude',
        'longitude',
        'located_at',
        'located_by',
        'location_source',
        'home_type',
        'nap_port',
        'nap_port_id',
        'cpe_sn',
        'user_pppoe',
        'password_pppoe',
        'status',
        'social_stratum',
        'permanence_clause',
        'ssid_wifi',
        'password_wifi',
        'comment',
        'activation_date',
        'overdue_invoices_count', //Me cuenta las facturas vencidas
        // Fecha de aviso de suspensión. Antes NO estaba en el
        // fillable y cada intento de guardarla se descartaba en
        // silencio por el filtro de mass assignment.
        'suspension_warning_date',
        'user_id',
        'municipality',
        'department'
    ];

    protected $casts = [
        'activation_date' => 'date',
        'suspension_warning_date' => 'datetime',
        // 7 decimales: ~1 cm, la misma escala con la que se guardan
        // las cajas NAP, para que las distancias entre ambos cuadren.
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'located_at' => 'datetime',
    ];

    // ==================== Ubicación ====================

    /** El punto se marcó sobre el mapa desde una pantalla. */
    public const LOCATION_SOURCE_MAP = 'mapa';

    /** El punto lo dio el GPS del dispositivo de quien lo registró. */
    public const LOCATION_SOURCE_DEVICE = 'dispositivo';

    /** El punto se heredó del cierre de una orden técnica en sitio. */
    public const LOCATION_SOURCE_ORDER = 'orden';

    /**
     * Cómo se obtuvo el punto, en lenguaje llano.
     *
     * Importa al leer la ficha: un punto tomado con el GPS del técnico
     * parado en la puerta merece más confianza que uno marcado a ojo
     * sobre el mapa desde la oficina.
     */
    public static function locationSources(): array
    {
        return [
            self::LOCATION_SOURCE_MAP => 'Marcada en el mapa',
            self::LOCATION_SOURCE_DEVICE => 'Tomada con el GPS del dispositivo',
            self::LOCATION_SOURCE_ORDER => 'Tomada al cerrar una orden en sitio',
        ];
    }

    /** ¿Se sabe dónde queda físicamente este servicio? */
    public function isGeolocated(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getLocationSourceLabelAttribute(): ?string
    {
        return self::locationSources()[$this->location_source] ?? null;
    }

    /** Quién dejó marcada la ubicación. */
    public function locatedBy()
    {
        return $this->belongsTo(User::class, 'located_by');
    }

    /**
     * Contratos con o sin punto en el mapa.
     *
     * Lo usa el listado para sacar la lista de pendientes por ubicar,
     * que es como se avanza en la georreferenciación de la base vieja.
     */
    public function scopeGeolocated($query, bool $geolocated = true)
    {
        return $geolocated
            ? $query->whereNotNull('latitude')->whereNotNull('longitude')
            : $query->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'));
    }

    /**
     * Número que se le muestra al cliente.
     *
     * Los contratos creados antes de existir la numeración por
     * sucursal no tienen número propio; en ese caso se muestra el id
     * para que la pantalla nunca quede vacía.
     */
    public function getNumeroVisibleAttribute(): string
    {
        return $this->contract_number ?: (string) $this->id;
    }

    /**
     * Relación con la tabla Clients (Clientes)
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Relación con la tabla Plans
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Relación con la tabla Users
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Relación con cargos adicionales

    public function additionalCharges()
    {
        return $this->hasMany(AditionalCharge::class);
    }

    /** Movimientos del saldo a favor del cliente */
    public function accountCredits()
    {
        return $this->hasMany(AccountCredit::class)->latest('id');
    }

    /**
     * Saldo a favor disponible: lo que el cliente tiene abonado y
     * todavía no se ha consumido en facturas.
     */
    public function saldoAFavor(): float
    {
        $entradas = $this->accountCredits()
            ->where('movement', AccountCredit::ENTRADA)->sum('amount');

        $aplicados = $this->accountCredits()
            ->where('movement', AccountCredit::APLICACION)->sum('amount');

        return round((float) $entradas - (float) $aplicados, 2);
    }

    /**
     * Comentarios/notas internas sobre el contrato (más recientes
     * primero).
     */
    public function comments()
    {
        return $this->hasMany(ContractComment::class)->latest();
    }

    //Relación con la tabla sucursal
    /**
     * Puerto de la caja NAP donde esta instalado el servicio.
     *
     * La columna de texto `nap_port` se conserva como historico:
     * lo que se anoto ahi antes del modulo de redes no se puede
     * traducir a una caja concreta sin adivinar.
     */
    public function napPort()
    {
        return $this->belongsTo(NapPort::class, 'nap_port_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    //Relacion con ont
    public function ont()
    {
        return $this->hasOne(Ont::class);
    }

    /**
     * Cuentas PPPoE del contrato.
     *
     * Es hasMany y no hasOne a propósito: el esquema no impide que
     * un cliente tenga más de un servicio, y el informe de
     * aprovisionamiento necesita contarlas.
     */
    public function pppoeAccounts()
    {
        return $this->hasMany(PppoeAccount::class);
    }

    /**
     * Relación con las facturas del contrato
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id');
    }

    /**
     * Relación con facturas vencidas específicamente
     */
    public function overdueInvoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id')
            ->where('status', InvoiceStatus::Vencida->value);
    }

    /**
     * Relación con facturas pendientes
     */
    public function pendingInvoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id')
            ->whereIn('status', [
                InvoiceStatus::Pendiente->value,
                InvoiceStatus::PendienteRiesgoCorte->value,
            ]);
    }

    /**
     * Facturas abiertas (admiten pago): pendientes, parciales,
     * con riesgo de corte y vencidas.
     */
    public function openInvoices()
    {
        return $this->hasMany(Invoice::class, 'contract_id')
            ->whereIn('status', InvoiceStatus::payable());
    }

    /**
     * Saldo total adeudado del contrato: la suma de los saldos de
     * sus facturas abiertas. Es el estado de cuenta que reemplaza
     * al patrón de absorción de vencidas (fase 4): las facturas
     * quedan abiertas e independientes y la deuda se lee aquí.
     */
    public function outstandingBalance(): float
    {
        return (float) $this->openInvoices()->sum('pending_invoice_amount');
    }


}
