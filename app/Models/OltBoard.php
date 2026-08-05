<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tarjeta de una OLT.
 *
 * Existe para poder agrupar: una MA5800 puede pasar de los doscientos
 * puertos PON, y una rejilla plana con doscientas casillas no se lee.
 * Por tarjeta sí, porque es como está el equipo en el rack y como habla
 * de él la gente de planta ("la tarjeta del slot 3").
 *
 * Se rellena sola desde el equipo y no guarda nada que alguien haya
 * escrito: se puede borrar y volver a descubrir sin perder nada.
 */
class OltBoard extends Model
{
    protected $fillable = [
        'olt_id',
        'frame',
        'slot',
        'name',
        'type',
        'port_count',
        'status',
        'discovered_at',
    ];

    protected $casts = [
        'frame' => 'integer',
        'slot' => 'integer',
        'port_count' => 'integer',
        'discovered_at' => 'datetime',
    ];

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    /** Puertos PON de esta tarjeta. */
    public function ponPorts()
    {
        return $this->hasMany(PonPort::class, 'olt_id', 'olt_id')
            ->where('frame', $this->frame)
            ->where('slot', $this->slot)
            ->orderBy('port');
    }

    /** Uplinks de esta tarjeta. */
    public function uplinks()
    {
        return $this->hasMany(OltUplink::class, 'olt_id', 'olt_id')
            ->where('frame', $this->frame)
            ->where('slot', $this->slot)
            ->orderBy('port');
    }

    /** Cómo se llama la tarjeta en pantalla. */
    public function getEtiquetaAttribute(): string
    {
        return $this->name ?: "Tarjeta {$this->frame}/{$this->slot}";
    }

    public function getPosicionAttribute(): string
    {
        return "{$this->frame}/{$this->slot}";
    }

    public function getTipoLegibleAttribute(): string
    {
        return match ($this->type) {
            'pon' => 'Acceso GPON',
            'uplink' => 'Subida',
            'control' => 'Control',
            default => 'Sin clasificar',
        };
    }
}
