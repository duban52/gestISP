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

    /** ¿Este detalle dice EXPRESAMENTE qué hacer con algún equipo? */
    public function tocaEquipos(): bool
    {
        return $this->pppoe_action !== self::SIN_ACCION || $this->ont_action !== self::SIN_ACCION;
    }

    /**
     * Lo que hará con la cuenta y la ONT al cerrarse, ya resuelto.
     *
     * Lo explícito del detalle manda; lo que dice «según el estado» se
     * resuelve con el estado al que lleva el contrato.
     *
     * @return array{pppoe: string, ont: string}
     */
    public function accionesResueltas(?string $estado = null): array
    {
        $porEstado = ContractStatusOption::accionDeEquipos($estado ?? $this->target_contract_status);

        return [
            'pppoe' => $this->pppoe_action !== self::SIN_ACCION ? $this->pppoe_action : $porEstado,
            'ont' => $this->ont_action !== self::SIN_ACCION ? $this->ont_action : $porEstado,
        ];
    }

    /** En una frase, para el formulario de crear orden. Null si no hace nada. */
    public function descripcionDelEfecto(): ?string
    {
        $acciones = $this->accionesResueltas();
        $frases = [];

        if ($this->target_contract_status) {
            $frases[] = 'el contrato pasa a ' . $this->target_contract_status;
        }

        foreach (['pppoe' => 'la cuenta PPPoE', 'ont' => 'la ONT'] as $clave => $equipo) {
            if ($acciones[$clave] !== self::SIN_ACCION) {
                $frases[] = ($acciones[$clave] === self::HABILITAR ? 'se habilita ' : 'se deshabilita ') . $equipo;
            }
        }

        return $frases === [] ? null : 'Al cerrarla, ' . implode(', ', $frases) . '.';
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
