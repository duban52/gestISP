<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use App\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo de afinidad.
 *
 * QUÉ DECIDE
 * ----------
 * Cómo se factura un contrato. Es la pieza que, contrato a contrato,
 * dice si emite factura electrónica —con firma digital y numeración
 * autorizada— o documento interno.
 *
 * Hoy esa decisión todavía no tiene consecuencias: el camino
 * electrónico no existe hasta la fase 9. El grupo se implanta antes a
 * propósito, para que cuando llegue esa fase los contratos ya estén
 * clasificados y no haya que clasificarlos a la carrera.
 *
 * DE LA EMPRESA, NO DE LA SUCURSAL
 * --------------------------------
 * Es una clasificación fiscal y comercial del contribuyente. El mismo
 * grupo se usa en todas sus sedes: «corporativo» o «cortesía» no
 * cambian de significado al cruzar de ciudad. Por eso lleva el trait
 * BelongsToCompany y no `branch_id`.
 *
 * UNA ADVERTENCIA QUE VIENE DEL ANÁLISIS FISCAL
 * ---------------------------------------------
 * Un documento interno **no puede llamarse factura** ni parecerlo en
 * su representación impresa: lleva su propia numeración y ninguno de
 * los elementos de una factura electrónica. Esa distinción visual es
 * lo que protege a quien lo emite, y de ahí también que la asignación
 * de grupo y todo cambio queden auditados (lo hace la auditoría
 * global; ver AuditLabels).
 */
class AffinityGroup extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /** Código del grupo que crea la migración para lo que ya existía. */
    public const CODIGO_GENERAL = 'GEN';

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'description',
        'requires_electronic_invoicing',
        'requires_client_tax_data',
        'dian_operation_type_code',
        'default_payment_means_code',
        'default_payment_method_code',
        'active',
        'is_default',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'requires_electronic_invoicing' => 'boolean',
        'requires_client_tax_data' => 'boolean',
        'active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Valores por defecto en memoria.
     *
     * Sin esto, `new AffinityGroup()` devuelve null donde la base
     * guarda false, y una comprobación como
     * `if (!$grupo->requires_electronic_invoicing)` se comporta
     * distinto según venga el objeto de la base o de un create().
     * Es el mismo problema que ya apareció con Company.
     */
    protected $attributes = [
        'requires_electronic_invoicing' => false,
        'requires_client_tax_data' => false,
        'active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ];

    // ==================== Relaciones ====================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    // ==================== Consultas ====================

    /** Solo los grupos que se pueden asignar hoy. */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** En el orden en que se quieren ver y ofrecer. */
    public function scopeOrdenados(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * El grupo por defecto de una empresa, si lo tiene.
     *
     * Es el que reciben los contratos que no eligen ninguno. La base
     * garantiza que no haya dos por empresa (índice único sobre una
     * columna generada), así que aquí basta con pedir el primero.
     *
     * Se consulta SIN el scope de empresa y filtrando a mano por
     * company_id: se usa también desde la importación y desde comandos,
     * donde puede no haber contexto establecido.
     */
    public static function porDefectoDe(int $companyId): ?self
    {
        return static::withoutGlobalScope('empresa')
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * El grupo con el que nace un contrato si nadie dice otra cosa.
     *
     * Devuelve null cuando la empresa no tiene ninguno marcado: quien
     * llame decide qué hacer con eso. No se inventa uno aquí porque
     * crear un grupo como efecto secundario de dar de alta un contrato
     * escondería un problema de configuración.
     */
    public static function porDefectoDelContexto(): ?self
    {
        $companyId = app(CurrentContext::class)->companyId();

        return $companyId ? static::porDefectoDe($companyId) : null;
    }

    // ==================== Lectura ====================

    /** Cómo se nombra en desplegables e informes: «GEN — General». */
    public function etiqueta(): string
    {
        return $this->code . ' — ' . $this->name;
    }

    /**
     * Cómo se factura este grupo, en una palabra.
     *
     * Se usa en los listados y en la ficha del contrato. Dice
     * «Electrónica» o «Interna» y nunca «Sí/No», porque lo que importa
     * al leerlo de un vistazo es POR QUÉ CAMINO sale la factura.
     */
    public function modalidadFacturacion(): string
    {
        return $this->requires_electronic_invoicing ? 'Electrónica' : 'Interna';
    }

    /**
     * ¿Se puede borrar?
     *
     * No, si tiene contratos —se perdería la clasificación de todos—
     * ni si es el de por defecto —la empresa se quedaría sin uno y los
     * contratos nuevos nacerían sin grupo. En ambos casos se desactiva,
     * que deja de ofrecerlo sin tocar lo ya clasificado.
     */
    public function sePuedeEliminar(): bool
    {
        return !$this->is_default && !$this->contracts()->exists();
    }
}
