<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * Un tipo de orden técnica: Servicio, Incidencia, Administrativa…
 *
 * `technical_orders.type` sigue guardando el NOMBRE, igual que antes.
 * Esto es el catálogo que alimenta el formulario.
 */
class TechnicalOrderType extends Model
{
    protected $fillable = ['name', 'description', 'active', 'sort_order'];

    protected $casts = [
        'active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const CACHE = 'catalogo:tipos-de-orden';

    public function details(): HasMany
    {
        return $this->hasMany(TechnicalOrderDetail::class);
    }

    /**
     * Los tipos activos con sus detalles activos, cacheados.
     *
     * Es lo que pinta el formulario de crear orden: dos listas
     * dependientes.
     */
    public static function catalogo()
    {
        return Cache::rememberForever(
            self::CACHE,
            fn () => self::with(['details' => fn ($q) => $q->where('active', true)->orderBy('sort_order')])
                ->where('active', true)
                ->orderBy('sort_order')
                ->get(),
        );
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE));
        static::deleted(fn () => Cache::forget(self::CACHE));
    }
}
