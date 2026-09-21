<?php

namespace App\Models;

use App\Reports\Support\OrderDetailMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Qué se hace en una orden, y qué le hace eso al contrato.
 *
 * Aquí vive lo que antes estaba escrito en dos sitios del código:
 *
 *  - A qué estado lleva el contrato al cerrar la orden
 *    (ContractStatusFromOrder::POR_DETALLE).
 *  - Con qué color y bajo qué etiqueta sale en los informes
 *    (OrderDetailMap::CATEGORIAS).
 *
 * Y lo que no existía: qué le hace a los equipos. Un corte deshabilita
 * la cuenta PPPoE y la ONT; una reconexión las vuelve a habilitar.
 */
class TechnicalOrderDetail extends Model
{
    public const SIN_ACCION = 'ninguna';
    public const DESHABILITAR = 'deshabilitar';
    public const HABILITAR = 'habilitar';

    protected $fillable = [
        'technical_order_type_id', 'name', 'key', 'target_contract_status',
        'pppoe_action', 'ont_action', 'color', 'active', 'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public const CACHE = 'catalogo:detalles-de-orden';

    public function type(): BelongsTo
    {
        return $this->belongsTo(TechnicalOrderType::class, 'technical_order_type_id');
    }

    /**
     * El detalle que corresponde a lo que guardó una orden.
     *
     * Se busca por la CLAVE NORMALIZADA y no por el texto: en la base
     * conviven «Instalacion de servicio» sin tilde, «Suspensión
     * temporal» con ella y «Instalación de servicio (creación
     * automática)» con sufijo. Normalizar es lo que hace que las tres
     * encuentren su fila.
     */
    public static function paraDetalle(?string $detalle): ?self
    {
        $normalizado = OrderDetailMap::normalizar($detalle);

        if ($normalizado === '') {
            return null;
        }

        // Primero la coincidencia exacta con el catalogo: es la unica
        // que encuentra un detalle CREADO HOY. Buscarlo antes por el
        // mapa de informes no serviria, porque ese mapa solo conoce los
        // doce originales.
        if ($fila = self::catalogo()->get($normalizado)) {
            return $fila;
        }

        // Y despues el mapa, que resuelve las variantes historicas:
        // «Sin servicio de TV» contiene «sin servicio», y hay ordenes
        // antiguas con el detalle escrito a mano.
        $clave = OrderDetailMap::clave($detalle);

        return $clave === null ? null : self::catalogo()->get($clave);
    }

    /**
     * Todos los detalles, por clave, cacheados.
     *
     * @return \Illuminate\Support\Collection<string, self>
     */
    public static function catalogo()
    {
        return Cache::rememberForever(self::CACHE, fn () => self::orderBy('sort_order')->get()->keyBy('key'));
    }

    /** ¿Este detalle toca los equipos del cliente? */
    public function tocaEquipos(): bool
    {
        return $this->pppoe_action !== self::SIN_ACCION || $this->ont_action !== self::SIN_ACCION;
    }

    protected static function booted(): void
    {
        $olvidar = function () {
            Cache::forget(self::CACHE);
            // El formulario pinta los detalles dentro de su tipo, asi
            // que su cache tambien deja de valer.
            Cache::forget(TechnicalOrderType::CACHE);
        };

        static::saved($olvidar);
        static::deleted($olvidar);
    }
}
