<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El registro de una cesión de contrato.
 *
 * Guarda los dos clientes aunque se puedan deducir de los contratos:
 * es un acto con consecuencias legales y su registro no puede depender
 * de que nadie edite esos contratos después.
 */
class ContractCession extends Model
{
    protected $fillable = [
        'from_contract_id', 'to_contract_id',
        'from_client_id', 'to_client_id',
        'user_id', 'reason', 'summary', 'ceded_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'ceded_at' => 'datetime',
    ];

    /** El contrato que se cedió (queda «Cedido»). */
    public function fromContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'from_contract_id');
    }

    /** El contrato nuevo, del cesionario. */
    public function toContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'to_contract_id');
    }

    /** Quien cedió. */
    public function fromClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'from_client_id');
    }

    /** Quien recibió. */
    public function toClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'to_client_id');
    }

    /** Quien la autorizó en el sistema. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
