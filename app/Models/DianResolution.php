<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una resolucion de numeracion de la DIAN.
 *
 * Es lo que autoriza a emitir: un numero de resolucion, una vigencia y
 * uno o varios rangos de consecutivos.
 *
 * LA CLAVE TECNICA
 * ----------------
 * Entra en el calculo del CUFE y NO viaja en ningun XML. Es lo que
 * hace que un CUFE no se pueda falsificar conociendo solo los datos de
 * la factura: sin la clave, el numero no sale.
 *
 * Por eso va cifrada, igual que el PIN del software y la contraseña
 * del certificado.
 */
class DianResolution extends Model
{
    use BelongsToCompany;

    /** Catalogo TipoDocumento. */
    public const FACTURA = '01';
    public const NOTA_CREDITO = '91';
    public const NOTA_DEBITO = '92';

    protected $fillable = [
        'company_id', 'resolution_number', 'document_type_code',
        'valid_from', 'valid_until', 'technical_key', 'active', 'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'technical_key' => 'encrypted',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'document_type_code' => self::FACTURA,
        'active' => true,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ranges(): HasMany
    {
        return $this->hasMany(NumberingRange::class, 'dian_resolution_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * ¿Sirve hoy?
     *
     * Una resolucion fuera de vigencia no autoriza nada, por muchos
     * consecutivos que le queden. Es una comprobacion de fecha, no de
     * rango: son dos formas distintas de quedarse sin poder emitir.
     */
    public function vigente(): bool
    {
        if (!$this->active) {
            return false;
        }

        $hoy = now()->startOfDay();

        return $this->valid_from->lte($hoy) && $this->valid_until->gte($hoy);
    }

    /** Dias que quedan de vigencia. */
    public function diasDeVigencia(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->valid_until, false);
    }
}
