<?php

namespace App\Models;

use App\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'client_id',
        'plan_id',
        'neighborhood',
        'address',
        'home_type',
        'nap_port',
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
    ];



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

    //Relación con la tabla sucursal
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
