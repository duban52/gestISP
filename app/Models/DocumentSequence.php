<?php

namespace App\Models;

use App\Services\Numbering\SerieNumerable;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una serie de numeración interna.
 *
 * De aquí salen los consecutivos que se inventa la empresa: contratos
 * y notas hoy, y lo que venga después. La numeración FISCAL —la que
 * autoriza la DIAN con una resolución y un rango— no vive aquí.
 *
 * Quien la usa es DocumentNumberService, y solo él: el bloqueo y el
 * incremento tienen que estar en un único sitio o vuelven a aparecer
 * tres copias de la misma lógica, que es lo que esta fase deshace.
 */
class DocumentSequence extends Model implements SerieNumerable
{
    use BelongsToCompany;
    use HasFactory;

    /** Tipos de documento que numera. */
    public const CONTRATO = 'contract';
    public const NOTA_CREDITO = 'credit_note';
    public const NOTA_DEBITO = 'debit_note';

    protected $fillable = [
        'company_id',
        'branch_id',
        'document_type',
        'prefix',
        'padding',
        'current_number',
        'range_start',
        'range_end',
        'active',
        'notes',
    ];

    protected $casts = [
        'padding' => 'integer',
        'current_number' => 'integer',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * Valores por defecto en memoria.
     *
     * Sin esto, `new DocumentSequence()` devuelve null donde la base
     * guarda 0 o false, y una comprobacion se comporta distinto segun
     * venga el objeto de la base o de un create(). Ya paso con Company
     * y con AffinityGroup.
     */
    protected $attributes = [
        'padding' => 0,
        'current_number' => 0,
        'active' => true,
    ];

    // ==================== Relaciones ====================

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    // ==================== Consultas ====================

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeDeTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('document_type', $tipo);
    }

    // ==================== Lectura ====================

    /**
     * Como se ve un consecutivo de esta serie.
     *
     * El relleno es un dato de la serie y no una constante del
     * servicio: los contratos van a seis digitos (ENG000001) y las
     * notas sin relleno (NC-1). Antes eso vivia en dos sitios
     * distintos como dos constantes distintas.
     */
    public function formatear(int $consecutivo): string
    {
        return $this->prefix . str_pad(
            (string) $consecutivo,
            $this->padding,
            '0',
            STR_PAD_LEFT,
        );
    }

    /** El ultimo numero que entrego, tal y como se ve. */
    public function ultimoEntregado(): ?string
    {
        return $this->current_number > 0
            ? $this->formatear($this->current_number)
            : null;
    }

    // ==================== SerieNumerable ====================

    public function consecutivoActual(): int
    {
        return (int) $this->current_number;
    }

    public function rangoDesde(): ?int
    {
        return $this->range_start;
    }

    public function rangoHasta(): ?int
    {
        return $this->range_end;
    }

    public function formatearNumero(int $consecutivo): string
    {
        return $this->formatear($consecutivo);
    }

    public function nombreDeSerie(): string
    {
        return $this->prefix;
    }

    public function avanzarA(int $consecutivo): void
    {
        $this->update(['current_number' => $consecutivo]);
    }

    /** Cuantos quedan antes de agotar el rango, si lo hay. */
    public function restantes(): ?int
    {
        return $this->range_end === null
            ? null
            : max(0, $this->range_end - $this->current_number);
    }
}
