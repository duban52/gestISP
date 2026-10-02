<?php

namespace App\Models;

use App\MassActions\Enums\MassActionItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un registro dentro de una acción masiva.
 *
 * Guarda QUÉ se tocó, CÓMO estaba antes, CÓMO quedó después y si ya se
 * revirtió. Esos cuatro datos son los que permiten deshacer la
 * operación sin pisar a nadie.
 *
 * `before` Y `after` LLEVAN SOLO LOS CAMPOS QUE CAMBIARON
 * ------------------------------------------------------
 * No un volcado del registro. Con cincuenta mil ítems, copiar el
 * contrato entero en cada uno multiplica la base para guardar
 * cuarenta columnas que nadie va a mirar. Y `after` tiene una segunda
 * función, que es la importante: es contra lo que se compara el estado
 * ACTUAL antes de revertir.
 */
class MassActionItem extends Model
{
    protected $fillable = [
        'mass_action_id', 'subject_type', 'subject_id', 'label',
        'status', 'message', 'before', 'after',
        'reverted_at', 'conflict_reason',
    ];

    protected $casts = [
        'status' => MassActionItemStatus::class,
        'before' => 'array',
        'after' => 'array',
        'reverted_at' => 'datetime',
    ];

    public function massAction(): BelongsTo
    {
        return $this->belongsTo(MassAction::class);
    }

    /** El registro afectado: un contrato, una factura, una cuenta… */
    public function subject()
    {
        return $this->morphTo();
    }

    /**
     * ¿Hay que intentar revertir este ítem?
     *
     * Lo que ya se revirtió no se vuelve a tocar: es el candado que
     * hace que reintentar una reversión interrumpida no haga daño. Un
     * ítem omitido o fallido no cambió nada que deshacer.
     */
    public function pendienteDeRevertir(): bool
    {
        return $this->status->seRevierte() && $this->reverted_at === null;
    }

    /**
     * El valor que la acción dejó en un campo.
     *
     * Es con lo que se compara el estado actual: si no coincide, es que
     * alguien lo cambió después y revertir sería sobrescribirlo.
     */
    public function valorDespues(string $campo): mixed
    {
        return $this->after[$campo] ?? null;
    }

    /** El valor que tenía antes de la acción. */
    public function valorAntes(string $campo): mixed
    {
        return $this->before[$campo] ?? null;
    }
}
