<?php

namespace App\Models;

use App\Services\Audit\AuditLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de trazabilidad del sistema.
 *
 * Cada fila responde: QUIÉN hizo QUÉ, CUÁNDO, DESDE DÓNDE y con qué
 * resultado. Las escribe automáticamente AuditServiceProvider (cambios
 * en cualquier modelo), el middleware AuditRequests (acciones sin
 * cambio de datos) y el trait Auditable.
 */
class Audit extends Model
{
    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'user_id',
        'user_name',
        'branch_id',
        'role_name',
        'ip',
        'route_name',
        'method',
        'url',
        'user_agent',
        'request_id',
        'trace_session_id',
        'action',
        'description',
        'category',
        'old_values',
        'new_values',
        'context',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'context' => 'array',
    ];

    /** Modelo auditado (si la acción afectó a uno) */
    public function auditable()
    {
        return $this->morphTo();
    }

    /** Usuario que realizó la acción (null en procesos de consola) */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Sucursal en la que se estaba trabajando */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Nombre legible de la acción */
    public function getAccionLegibleAttribute(): string
    {
        return AuditLabels::accion($this->action);
    }

    /** Nombre legible del módulo */
    public function getCategoriaLegibleAttribute(): string
    {
        return AuditLabels::categorias()[$this->category] ?? ($this->category ?? '—');
    }

    /**
     * Campos que cambiaron, con su valor anterior y el nuevo.
     *
     * @return array<int, array{campo: string, antes: mixed, despues: mixed}>
     */
    public function getCambiosAttribute(): array
    {
        $nuevos = $this->new_values ?? [];
        $viejos = $this->old_values ?? [];

        $campos = array_unique(array_merge(array_keys($nuevos), array_keys($viejos)));
        $filas = [];

        foreach ($campos as $campo) {
            $filas[] = [
                'campo' => $campo,
                'antes' => $viejos[$campo] ?? null,
                'despues' => $nuevos[$campo] ?? null,
            ];
        }

        return $filas;
    }

    /** ¿La acción falló? */
    public function getFalloAttribute(): bool
    {
        return ($this->context['resultado'] ?? 'ok') === 'error';
    }

    // ==================== Filtros ====================

    public function scopeDelUsuario(Builder $query, $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDeCategoria(Builder $query, string $categoria): Builder
    {
        return $query->where('category', $categoria);
    }

    public function scopeEntreFechas(Builder $query, ?string $desde, ?string $hasta): Builder
    {
        return $query
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde . ' 00:00:00'))
            ->when($hasta, fn ($q) => $q->where('created_at', '<=', $hasta . ' 23:59:59'));
    }
}
