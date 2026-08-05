<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una salida de un splitter.
 *
 * Se crean todas al montar el splitter, con o sin hilo conectado: un
 * 1:8 con seis salidas en uso es lo normal, y poder ver las dos libres
 * es justo lo que se pregunta al planear una derivación.
 */
class SplitterOutput extends Model
{
    protected $fillable = [
        'splitter_id',
        'number',
        'strand_id',
        'notes',
    ];

    protected $casts = [
        'number' => 'integer',
    ];

    public function splitter()
    {
        return $this->belongsTo(Splitter::class);
    }

    public function strand()
    {
        return $this->belongsTo(CableStrand::class, 'strand_id');
    }

    public function estaConectada(): bool
    {
        return $this->strand_id !== null;
    }
}
