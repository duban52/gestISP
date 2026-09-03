<?php

namespace App\Models;

use App\Services\Numbering\SerieNumerable;
use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Secuencia de numeración de facturas de una sucursal.
 *
 * Soporta los datos de una resolución DIAN (número, vigencia,
 * rango autorizado) aunque la numeración inicial sea interna.
 * El consecutivo SOLO debe incrementarse a través de
 * App\Billing\Services\InvoiceNumerator, que bloquea la fila.
 */
class InvoiceNumberingSequence extends Model implements SerieNumerable
{
    use BelongsToCompany;

    protected $fillable = [
        'branch_id',
        // 'internal' o 'electronic': lo que impide que un
        // documento interno gaste un consecutivo autorizado.
        'kind',
        'prefix',
        'resolution_number',
        'valid_from',
        'valid_until',
        'range_start',
        'range_end',
        'current_number',
        'active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'range_start' => 'integer',
        'range_end' => 'integer',
        'current_number' => 'integer',
        'active' => 'boolean',
    ];

    /** Sucursal dueña de la secuencia */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Facturas numeradas con esta secuencia */
    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'numbering_sequence_id');
    }

    // ==================== SerieNumerable ====================
    //
    // Esta tabla NO se muda a `document_sequences`: nacio con
    // resolucion, vigencia y rango porque se diseño anticipando la
    // DIAN, y sus consecutivos se moveran a la tabla fiscal cuando esa
    // exista. Moverlos ahora a la interna seria moverlos dos veces.
    //
    // Lo que si se comparte es el ALGORITMO: sumar uno, comprobar el
    // rango y formatear los hace DocumentNumberService::reservarEn(),
    // el mismo que usan contratos y notas. Esa comprobacion de rango
    // es la que no puede tener dos versiones.

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

    /**
     * El formato de las facturas: PREFIJO-N, sin relleno.
     *
     * El separador va aqui y no en el prefijo porque `invoices.prefix`
     * guarda el prefijo a secas y se imprime asi en el documento.
     */
    public function formatearNumero(int $consecutivo): string
    {
        return $this->prefix . '-' . $consecutivo;
    }

    public function nombreDeSerie(): string
    {
        return $this->prefix;
    }

    public function avanzarA(int $consecutivo): void
    {
        $this->update(['current_number' => $consecutivo]);
    }
}
