<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de mandarle un documento a la DIAN.
 *
 * Hay uno por CADA intento, no uno por documento: el anexo obliga a
 * reintentar y a «mantener o archivar las evidencias del error»
 * (§12.2). Con una sola fila por documento cada reintento pisaria al
 * anterior y no quedaria ninguna evidencia.
 */
class DocumentTransmission extends Model
{
    use BelongsToCompany;

    /** Los cuatro finales posibles de un intento. */
    public const ACEPTADO = 'accepted';
    public const RECHAZADO = 'rejected';
    public const ERROR = 'error';
    public const DEMORA = 'timeout';

    protected $fillable = [
        'electronic_document_id', 'company_id', 'attempt',
        'outcome', 'http_status', 'track_id', 'response', 'errors', 'duration_ms',
    ];

    protected $casts = [
        'errors' => 'array',
        'attempt' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ElectronicDocument::class, 'electronic_document_id');
    }

    public function estadoLegible(): string
    {
        return match ($this->outcome) {
            self::ACEPTADO => 'Aceptado',
            self::RECHAZADO => 'Rechazado',
            self::ERROR => 'Error de comunicación',
            self::DEMORA => 'Sin respuesta a tiempo',
            default => $this->outcome,
        };
    }

    /** El primer mensaje de error, para enseñarlo en una tabla. */
    public function primerError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
