<?php

namespace App\Billing\Concerns;

use App\Models\Audit;

/**
 * Auditoría automática de modelos.
 *
 * Cada created/updated/deleted del modelo genera un registro en
 * la tabla audits con el usuario, la IP, la acción y los valores
 * antes/después (solo los atributos que cambiaron, para que el
 * historial sea legible).
 *
 * Uso: `use Auditable;` en el modelo. Nada más.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAudit('created', [], $model->getAttributes());
        });

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $model->writeAudit(
                'updated',
                array_intersect_key($model->getOriginal(), $changes),
                $changes
            );
        });

        static::deleted(function ($model) {
            $model->writeAudit('deleted', $model->getOriginal(), []);
        });
    }

    /**
     * Escribe el registro de auditoría del cambio.
     */
    public function writeAudit(string $action, array $old, array $new): void
    {
        Audit::create([
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'user_id' => auth()->id(),
            'ip' => request()?->ip(),
            'action' => $action,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    /** Historial de auditoría del modelo */
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable')->latest();
    }
}
