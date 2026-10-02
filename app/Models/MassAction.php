<?php

namespace App\Models;

use App\MassActions\Enums\MassActionStatus;
use App\MassActions\Enums\MassActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una operación que tocó muchos registros de una vez.
 *
 * Qué se ejecutó, quién, cuándo, sobre qué, cómo acabó cada registro y
 * —lo que de verdad justifica todo esto— si se puede deshacer.
 *
 * NO LLEVA `BelongsToCompany`
 * ---------------------------
 * Y es a propósito. Esta pantalla es del superadministrador, que ve el
 * sistema entero: filtrar por la empresa del contexto activo le
 * escondería justo las operaciones de las que tiene que responder. La
 * empresa y la sucursal se GUARDAN —para poder filtrarlas a mano y
 * para saber dónde pasó— pero no se imponen.
 */
class MassAction extends Model
{
    protected $fillable = [
        'type', 'status', 'description',
        'user_id', 'branch_id', 'company_id',
        'total_items', 'ok_items', 'skipped_items', 'failed_items',
        'reverted_items', 'conflict_items',
        'summary', 'source_type', 'source_id',
        'reverses_mass_action_id', 'reverted_by_mass_action_id',
        'request_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'type' => MassActionType::class,
        'status' => MassActionStatus::class,
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ==================== Relaciones ====================

    public function items(): HasMany
    {
        return $this->hasMany(MassActionItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** El registro propio del proceso: la corrida, la tanda de cortes… */
    public function source()
    {
        return $this->morphTo();
    }

    /** La acción que esta deshace. */
    public function revierteA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_mass_action_id');
    }

    /** La reversión que deshizo esta. */
    public function revertidaPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverted_by_mass_action_id');
    }

    // ==================== Preguntas de la pantalla ====================

    /**
     * ¿Se puede ofrecer el botón de revertir?
     *
     * TRES CONDICIONES, Y LAS TRES HACEN FALTA:
     *
     *  1. Que el tipo sepa deshacerse. Una corrida de facturación no se
     *     borra: se anula lo anulable, y eso es otra estrategia.
     *  2. Que la acción esté terminada y no revertida ya. Es el candado
     *     contra la doble reversión: no basta con esconder el botón,
     *     porque la URL se puede escribir a mano.
     *  3. Que quede algo que revertir. Una acción de 500 omitidos no
     *     cambió nada.
     *
     * El permiso —solo superadministrador— lo comprueba la política,
     * que es su sitio.
     */
    public function sePuedeRevertir(): bool
    {
        return app(\App\MassActions\MassActionRegistry::class)->esReversible($this->type)
            && $this->status->admiteReversion()
            && $this->reverted_by_mass_action_id === null
            && $this->items()->where('status', \App\MassActions\Enums\MassActionItemStatus::Ok->value)->exists();
    }

    /** Cuánto tardó, en texto. */
    public function duracion(): ?string
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        $segundos = $this->started_at->diffInSeconds($this->finished_at);

        return $segundos < 60
            ? $segundos . ' s'
            : intdiv($segundos, 60) . ' min ' . ($segundos % 60) . ' s';
    }

    /** ¿Esta acción ES una reversión de otra? */
    public function esUnaReversion(): bool
    {
        return $this->reverses_mass_action_id !== null;
    }
}
