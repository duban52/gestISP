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
     *
     * Delega en AuditLogger para que estas filas lleven el mismo
     * contexto (sucursal, rol, ruta, sesión) y la misma limpieza de
     * datos sensibles que el resto del sistema. Antes escribía
     * directamente en la tabla y quedaban más pobres que las demás.
     */
    public function writeAudit(string $action, array $old, array $new): void
    {
        app(\App\Services\Audit\AuditLogger::class)->model($this, $action, $old, $new);
    }

    /** Historial de auditoría del modelo */
    public function audits()
    {
        return $this->morphMany(Audit::class, 'auditable')->latest();
    }
}
