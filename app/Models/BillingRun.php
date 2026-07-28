<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una corrida de facturación.
 *
 * Lo escribe MonthlyBillingRun al terminar cada generación:
 * conteos y totales facturados de la sucursal en ese período.
 * Alimenta el reporte gerencial de facturación.
 */
class BillingRun extends Model
{
    protected $fillable = [
        'branch_id',
        'user_id',
        'billed_year_month',
        'contracts_count',
        'generated_count',
        'skipped_count',
        'total_subtotal',
        'total_tax',
        'total_billed',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
        'total_subtotal' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_billed' => 'decimal:2',
    ];

    /** Sucursal de la corrida */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que ejecutó la generación */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Facturas generadas en esta corrida */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Facturas de la corrida, con todo lo que necesita el reporte.
     *
     * Las corridas anteriores a que existiera el enlace no tienen
     * facturas asociadas; en ese caso se recuperan por sucursal y
     * período, que es como se agrupaban antes. Así el detalle
     * también funciona con el historial ya existente.
     */
    public function facturasDelReporte()
    {
        $relaciones = [
            'contract.client',
            'contract.plan',
            'invoice_items',
        ];

        if ($this->invoices()->exists()) {
            return $this->invoices()->with($relaciones)->orderBy('id')->get();
        }

        return Invoice::with($relaciones)
            ->where('branch_id', $this->branch_id)
            ->where('billed_year_month', $this->billed_year_month)
            ->orderBy('id')
            ->get();
    }

    /** ¿El detalle se está deduciendo por período (corrida antigua)? */
    public function getDetalleDeducidoAttribute(): bool
    {
        return !$this->invoices()->exists();
    }

    /** Etiqueta legible del período: "202607" → "Julio 2026". */
    public function getPeriodoLegibleAttribute(): string
    {
        $valor = (string) $this->billed_year_month;

        if (strlen($valor) !== 6) {
            return $valor;
        }

        try {
            return ucfirst(
                \Carbon\Carbon::createFromFormat('Ym', $valor)->translatedFormat('F Y')
            );
        } catch (\Throwable $e) {
            return $valor;
        }
    }
}
