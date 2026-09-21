<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use App\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Billing\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        // Número visible del contrato (consecutivo por sucursal:
        // ENG000123). El id sigue siendo el identificador interno.
        'contract_number',
        'branch_id',
        // Como se factura este contrato: electronica o interna. Lo
        // decide el grupo, no el contrato (ver AffinityGroup).
        'affinity_group_id',
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
        // Desde cuando se le factura. Lo usa la cesion: el mes en que
        // ocurre lo paga el cedente, y sin esta fecha la corrida se lo
        // cobraria tambien al contrato nuevo.
        'billing_start_date',
        // Descuento con vigencia (promociones). Ver descuentoVigente().
        'discount_type',
        'discount_value',
        'discount_months',
        'discount_applied',
        'discount_reason',
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
        'billing_start_date' => 'date',
        'discount_type' => DiscountType::class,
        'discount_value' => 'decimal:2',
        'discount_months' => 'integer',
        'discount_applied' => 'integer',
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

    /**
     * El grupo al que pertenece: quien decide como se factura.
     *
     * Puede venir nulo en contratos creados por un camino que no lo
     * asigne. La columna admite nulo a proposito —ver la migracion—,
     * asi que todo lo que lo lea tiene que contar con ello.
     */
    public function affinityGroup()
    {
        return $this->belongsTo(AffinityGroup::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    //Relacion con ont
    /**
     * ¿A este contrato le queda descuento por aplicar?
     *
     * Sin meses declarados el descuento no caduca: dura hasta que
     * alguien lo quite. Con meses, se agota solo — que es de lo que se
     * trata: «dos meses» no puede depender de que alguien se acuerde
     * de entrar a quitarlo el tercero.
     */
    public function descuentoVigente(): bool
    {
        if (!$this->discount_type || (float) $this->discount_value <= 0) {
            return false;
        }

        return $this->discount_months === null
            || (int) $this->discount_applied < (int) $this->discount_months;
    }

    /** Cuántas facturas más llevará el descuento (null = sin límite). */
    public function descuentosRestantes(): ?int
    {
        if ($this->discount_months === null) {
            return null;
        }

        return max(0, (int) $this->discount_months - (int) $this->discount_applied);
    }

    /**
     * La cesion con la que este contrato paso a otro titular, si la hubo.
     *
     * Desde el contrato del CEDENTE: dice a quien y cuando.
     */
    public function cesionSaliente()
    {
        return $this->hasOne(ContractCession::class, 'from_contract_id');
    }

    /**
     * La cesion de la que nacio este contrato, si nacio de una.
     *
     * Desde el contrato del CESIONARIO: dice de quien lo recibio.
     */
    public function cesionEntrante()
    {
        return $this->hasOne(ContractCession::class, 'to_contract_id');
    }

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
