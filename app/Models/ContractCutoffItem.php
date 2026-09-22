<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un contrato de una tanda de corte masivo, y lo que pasó con él.
 */
class ContractCutoffItem extends Model
{
    /** En la cola, todavía sin cortar. */
    public const PENDIENTE = 'pendiente';

    /** Suspendido y con sus equipos deshabilitados. */
    public const CORTADO = 'cortado';

    /** Suspendido, pero algún equipo no respondió: hay que terminarlo a mano. */
    public const INCOMPLETO = 'incompleto';

    /** No se cortó: no debía, ya estaba cortado, no existe… (ver message). */
    public const OMITIDO = 'omitido';

    /** Falló por algo inesperado (ver message). */
    public const ERROR = 'error';

    /** Cómo se dice cada resultado —pantalla, Excel y PDF— y su color. */
    public const ETIQUETAS = [
        self::PENDIENTE => ['En cola', 'info'],
        self::CORTADO => ['Cortado', 'success'],
        self::INCOMPLETO => ['Incompleto', 'warning'],
        self::OMITIDO => ['No se cortó', 'secondary'],
        self::ERROR => ['Error', 'danger'],
    ];

    protected $fillable = [
        'contract_cutoff_id', 'contract_id', 'contract_number', 'status', 'message',
        'overdue_count', 'overdue_amount', 'technical_order_id', 'processed_at',
    ];

    protected $casts = [
        'overdue_amount' => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function cutoff(): BelongsTo
    {
        return $this->belongsTo(ContractCutoff::class, 'contract_cutoff_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function technicalOrder(): BelongsTo
    {
        return $this->belongsTo(TechnicalOrder::class);
    }
}
