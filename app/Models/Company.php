<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * La empresa: el contribuyente que factura.
 *
 * Es la raíz del sistema. De ella cuelgan las sucursales y, a través
 * de ellas, todo lo demás. Lo que la distingue de una sucursal es que
 * aquí vive lo FISCAL —identidad tributaria, régimen, y más adelante
 * el certificado digital, las resoluciones de numeración y la
 * configuración DIAN—, mientras que la sucursal guarda lo OPERATIVO.
 *
 * La regla para decidir dónde va un dato nuevo: si la DIAN lo
 * relaciona con el NIT, es de la empresa; si describe cómo trabaja una
 * sede concreta, es de la sucursal.
 */
class Company extends Model
{
    use HasFactory;
    use Auditable;

    /** Cada sucursal es un contexto propio. Es el modo de siempre. */
    public const MODO_INDEPENDIENTE = 'independent';

    /** Todas las sucursales se trabajan desde un panel único. */
    public const MODO_CONSOLIDADO = 'consolidated';

    protected $fillable = [
        'legal_name',
        'trade_name',
        'document_type_code',
        'document_number',
        'verification_digit',
        'organization_type_code',
        'tax_regime_code',
        'address',
        'department_dane_code',
        'municipality_dane_code',
        'postal_code',
        'email',
        'phone',
        'logo',
        'operation_mode',
        'electronic_invoicing_enabled',
        'active',
    ];

    protected $casts = [
        'electronic_invoicing_enabled' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * Valores por defecto EN EL MODELO, no solo en la base.
     *
     * Sin esto, una empresa recien creada devuelve null en estos
     * campos hasta que se relee: la base tiene su default, pero el
     * objeto en memoria no lo conoce. Eso hace que el mismo modelo se
     * comporte distinto segun venga de create() o de una consulta, que
     * es la clase de diferencia que se descubre tarde y en produccion.
     */
    protected $attributes = [
        'document_type_code' => '31',
        'operation_mode' => self::MODO_INDEPENDIENTE,
        'electronic_invoicing_enabled' => false,
        'active' => true,
    ];

    // ==================== Relaciones ====================

    /**
     * Los grupos de afinidad de la empresa.
     *
     * Cuelgan de aqui y no de la sucursal porque son una
     * clasificacion del CONTRIBUYENTE: el mismo grupo se usa en
     * todas sus sedes.
     */
    public function affinityGroups()
    {
        return $this->hasMany(AffinityGroup::class);
    }

    /** El grupo con el que nacen sus contratos si nadie elige otro. */
    public function grupoPorDefecto(): ?AffinityGroup
    {
        return AffinityGroup::porDefectoDe($this->id);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function taxResponsibilities()
    {
        return $this->hasMany(CompanyTaxResponsibility::class);
    }

    /**
     * Usuarios con acceso a alguna sucursal de esta empresa.
     *
     * No hay una tabla usuario–empresa: la pertenencia se DEDUCE de
     * las sucursales, que es donde de verdad se concede el acceso y el
     * rol. Guardarla aparte crearía una segunda verdad que puede
     * divergir de user_branch.
     */
    public function users()
    {
        return User::whereHas(
            'branches',
            fn ($q) => $q->where('branches.company_id', $this->id),
        );
    }

    // ==================== Estado ====================

    /**
     * ¿Trabaja todas sus sucursales desde un panel único?
     */
    public function esConsolidada(): bool
    {
        return $this->operation_mode === self::MODO_CONSOLIDADO;
    }

    /**
     * Identificación completa, con dígito de verificación si lo tiene.
     *
     * Es lo que se imprime y lo que se compara con lo que el cliente
     * tiene en su RUT: 900123456-7.
     */
    public function identificacion(): string
    {
        return $this->verification_digit
            ? $this->document_number . '-' . $this->verification_digit
            : (string) $this->document_number;
    }

    /**
     * Nombre para mostrar: el comercial si lo hay, el legal si no.
     */
    public function nombreVisible(): string
    {
        return $this->trade_name ?: $this->legal_name;
    }
}
