<?php

namespace App\Models;

use App\Billing\Concerns\NotAudited;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Un codigo de un catalogo fiscal.
 *
 * NO lleva el trait de empresa a proposito: los catalogos son de la
 * DIAN, no de nadie. Son los mismos para todas las empresas de la
 * instalacion, y acotarlos por empresa obligaria a sembrarlos otra vez
 * por cada NIT.
 *
 * COMO SE USAN
 * ------------
 * Los codigos se guardan como TEXTO en cada documento (`clients
 * .document_type_code`, `invoices.payment_means_code`…), no como
 * clave foranea. Es lo que pide el XML, y ademas evita que retirar un
 * codigo del catalogo rompa documentos ya emitidos.
 *
 * Esta clase sirve para dos cosas: ofrecer las opciones en los
 * formularios y traducir un codigo guardado a su nombre legible.
 */
class FiscalCatalog extends Model
{
    // Datos de referencia de la DIAN, no actividad de nadie: los
    // escribe FiscalCatalogSeeder desde los JSON versionados, y el
    // controlador solo los lee para los desplegables.
    //
    // Cada `db:seed --class=FiscalCatalogSeeder` recorre ~2.000 codigos
    // con updateOrCreate. Auditarlos metia 2.000 filas por ejecucion:
    // el 90% de la tabla en esta instalacion.
    use NotAudited;

    /** Los catalogos que usa el sistema, por su nombre corto. */
    public const TIPO_DOCUMENTO = 'tipo_documento';
    public const TIPO_ORGANIZACION = 'tipo_organizacion';
    public const RESPONSABILIDAD = 'responsabilidad_fiscal';
    public const DEPARTAMENTO = 'departamento';
    public const MUNICIPIO = 'municipio';
    public const PAIS = 'pais';
    public const FORMA_PAGO = 'forma_pago';
    public const MEDIO_PAGO = 'medio_pago';
    public const TARIFA_IVA = 'tarifa_iva';
    public const TIPO_IMPUESTO = 'tipo_impuesto';
    public const UNIDAD_MEDIDA = 'unidad_medida';
    public const MONEDA = 'moneda';
    public const TIPO_OPERACION_FACTURA = 'tipo_operacion_factura';
    public const CONCEPTO_NOTA_CREDITO = 'concepto_nota_credito';
    public const CONCEPTO_NOTA_DEBITO = 'concepto_nota_debito';
    public const TIPO_CODIGO_PRODUCTO = 'tipo_codigo_producto';
    public const TIPO_DOCUMENTO_ELECTRONICO = 'tipo_documento_electronico';
    public const AMBIENTE = 'ambiente';

    protected $fillable = [
        'catalog', 'code', 'name', 'parent_code', 'extra', 'active', 'sort_order',
    ];

    protected $casts = [
        'extra' => 'array',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'active' => true,
        'sort_order' => 0,
    ];

    // ==================== Consultas ====================

    public function scopeDe(Builder $query, string $catalogo): Builder
    {
        return $query->where('catalog', $catalogo);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Opciones de un catalogo, listas para un desplegable.
     *
     * @return Collection<string, string>  codigo => nombre
     */
    public static function opciones(string $catalogo, ?string $padre = null): Collection
    {
        return static::de($catalogo)
            ->activos()
            ->when($padre !== null, fn ($q) => $q->where('parent_code', $padre))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'code');
    }

    /**
     * El nombre de un codigo guardado.
     *
     * Devuelve el propio codigo si no esta en el catalogo, en vez de
     * vacio: un documento viejo con un codigo retirado tiene que
     * seguir diciendo algo.
     */
    public static function nombre(string $catalogo, ?string $codigo): ?string
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        return static::de($catalogo)->where('code', $codigo)->value('name') ?? $codigo;
    }

    /** ¿Ese codigo existe y sigue vigente? */
    public static function vigente(string $catalogo, ?string $codigo): bool
    {
        return $codigo !== null
            && $codigo !== ''
            && static::de($catalogo)->activos()->where('code', $codigo)->exists();
    }
}
