<?php

namespace App\Models;

use App\Services\Numbering\SerieNumerable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rango de numeración autorizado por la DIAN.
 *
 * ES LA SERIE FISCAL
 * ------------------
 * Lo que la resolución autoriza: un prefijo y un rango de consecutivos
 * que se pueden emitir. Pasado el rango no se puede seguir: serían
 * números que nadie autorizó.
 *
 * IMPLEMENTA `SerieNumerable`, Y ESO ES TODO LO QUE HACE FALTA
 * -----------------------------------------------------------
 * El bloqueo, el incremento y la comprobación de rango ya existen
 * desde la fase 6, en `DocumentNumberService::reservarEn()`. Esta
 * clase no los reescribe: declara qué es su prefijo, su rango y su
 * contador, y el servicio hace el resto.
 *
 * Es la razón por la que aquella fase separó el algoritmo de la tabla.
 * La comprobación de rango —la que impide emitir con números no
 * autorizados, que es lo más delicado de todo esto— es exactamente la
 * misma que ya lleva probada desde entonces, no una copia nueva.
 *
 * UN SOLO PREFIJO ACTIVO POR EMPRESA
 * ----------------------------------
 * Lo garantiza la base con la técnica de siempre: una columna generada
 * que vale cuando el rango está activo y NULL cuando no.
 *
 * Sin eso, dos sucursales del mismo NIT podrían usar el mismo prefijo
 * y producir consecutivos duplicados ante la DIAN, aunque la base los
 * viera como filas distintas. Es el índice que el plan marcaba como
 * crítico.
 */
class NumberingRange extends Model implements SerieNumerable
{
    protected $fillable = [
        'company_id', 'dian_resolution_id', 'branch_id',
        'prefix', 'range_start', 'range_end', 'current_number', 'active',
    ];

    protected $casts = [
        'range_start' => 'integer',
        'range_end' => 'integer',
        'current_number' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'current_number' => 0,
        'active' => true,
    ];

    protected static function booted(): void
    {
        // `company_id` se desnormaliza desde la resolución porque el
        // índice de «un prefijo activo por empresa» lo necesita en esta
        // tabla, y una columna generada no puede leer de otra tabla.
        //
        // Se rellena aquí y no se le pide a quien crea el rango: si
        // dependiera de que alguien lo pase, el día que se olvide el
        // índice deja de proteger nada.
        static::saving(function (self $rango) {
            if (empty($rango->company_id) && $rango->dian_resolution_id) {
                $rango->company_id = DianResolution::withoutGlobalScopes()
                    ->whereKey($rango->dian_resolution_id)
                    ->value('company_id');
            }
        });
    }

    // ==================== Relaciones ====================

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(DianResolution::class, 'dian_resolution_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // ==================== Consultas ====================

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    // ==================== SerieNumerable ====================

    public function consecutivoActual(): int
    {
        return (int) $this->current_number;
    }

    public function rangoDesde(): ?int
    {
        return $this->range_start;
    }

    public function rangoHasta(): ?int
    {
        return $this->range_end;
    }

    /**
     * El formato de la factura electrónica: PREFIJO + consecutivo, sin
     * separador y sin relleno.
     *
     * Así es como la DIAN espera el número en el XML y como se calcula
     * el CUFE: SETP990000001, no SETP-990000001. Un separador de más
     * cambia el CUFE y lo invalida.
     */
    public function formatearNumero(int $consecutivo): string
    {
        return $this->prefix . $consecutivo;
    }

    public function nombreDeSerie(): string
    {
        return $this->prefix;
    }

    public function avanzarA(int $consecutivo): void
    {
        $this->update(['current_number' => $consecutivo]);
    }

    // ==================== Lectura ====================

    /**
     * Cuántos consecutivos quedan.
     *
     * Se avisa antes de agotarlo: pedir una resolución nueva a la DIAN
     * no es inmediato, y quedarse sin rango detiene la facturación.
     */
    public function restantes(): int
    {
        return max(0, $this->range_end - max($this->current_number, $this->range_start - 1));
    }

    /** ¿Queda poco? El umbral es del 10%, con un mínimo de cien. */
    public function porAgotarse(): bool
    {
        $total = $this->range_end - $this->range_start + 1;

        return $this->restantes() <= max(100, (int) ($total * 0.1));
    }
}
