<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Un estado de contrato del catálogo.
 *
 * NO es la columna `contracts.status`: ahí se sigue guardando el
 * NOMBRE. Esto es el catálogo que dice qué significa cada nombre —si
 * factura, si tiene servicio, si es una baja—, que es lo que antes
 * estaba repartido en listas dentro del enum.
 *
 * Los `is_system` son los que el código nombra por su valor
 * (ContractStatus::Activo y compañía). Se pueden describir, colorear y
 * desactivar, pero renombrarlos o borrarlos dejaría sin funcionar la
 * facturación, la suspensión por mora y el cierre de órdenes.
 */
class ContractStatusOption extends Model
{
    protected $table = 'contract_statuses';

    protected $fillable = [
        'name', 'description', 'bills', 'auto_bills', 'has_service',
        'is_final', 'color', 'active', 'sort_order',
    ];

    protected $casts = [
        'bills' => 'boolean',
        'auto_bills' => 'boolean',
        'has_service' => 'boolean',
        'is_final' => 'boolean',
        'active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** La clave de caché del catálogo entero. */
    public const CACHE = 'catalogo:estados-de-contrato';

    /**
     * El catálogo completo, cacheado.
     *
     * Se consulta en cada factura de una corrida mensual —cientos de
     * veces seguidas—, así que leerlo de la base cada vez sería una
     * consulta por contrato para preguntar algo que no cambia.
     *
     * @return \Illuminate\Support\Collection<string, self>
     */
    public static function catalogo()
    {
        return Cache::rememberForever(self::CACHE, fn () => self::orderBy('sort_order')->get()->keyBy('name'));
    }

    /** El estado con ese nombre, o null si no está en el catálogo. */
    public static function porNombre(?string $nombre): ?self
    {
        return $nombre === null ? null : self::catalogo()->get($nombre);
    }

    /**
     * Qué le pide un estado a los equipos del cliente.
     *
     * Con servicio, habilitar; sin servicio, deshabilitar. Una BAJA no
     * pide nada: la baja definitiva la hace ContractDecommissioner, que
     * además desvincula la cuenta y retira la ONT, y deshabilitar antes
     * sería mandarle dos órdenes al router. Sin estado, o con uno que no
     * está en el catálogo, tampoco: no se adivina.
     *
     * Es LA regla: la usan el cierre de órdenes y la pantalla que lo
     * describe, para que no puedan decir cosas distintas.
     */
    public static function accionDeEquipos(?string $nombre): string
    {
        $estado = self::porNombre($nombre);

        if (!$estado || $estado->is_final) {
            return TechnicalOrderDetail::SIN_ACCION;
        }

        return $estado->has_service
            ? TechnicalOrderDetail::HABILITAR
            : TechnicalOrderDetail::DESHABILITAR;
    }

    /** Los que se pueden elegir al cambiar el estado de un contrato. */
    public static function activos()
    {
        return self::catalogo()->filter(fn (self $estado) => $estado->active)->values();
    }

    protected static function booted(): void
    {
        // El catálogo cambia poco y se lee mucho: se rehace entero
        // cuando alguien lo toca, en vez de intentar afinar qué fila
        // caducar.
        static::saved(fn () => Cache::forget(self::CACHE));
        static::deleted(fn () => Cache::forget(self::CACHE));
    }
}
