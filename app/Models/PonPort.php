<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Puerto PON de una OLT: el troncal del que cuelga la red.
 *
 * POR QUÉ ES UNA ENTIDAD Y NO UN DATO SUELTO
 * ------------------------------------------
 * Antes los puertos solo se conocían de rebote, mirando el slot/port
 * de las ONTs. Eso responde "cuántas ONTs hay ahí" pero no las
 * preguntas que importan al planear: qué splitter tiene, a qué zona
 * alimenta, cuánta holgura queda. Registrarlo permite documentar la
 * red, no solo observarla.
 *
 * La ocupación sí se sigue midiendo contra las ONTs reales: es el
 * único dato que no miente, porque lo reporta el equipo.
 */
class PonPort extends Model
{
    protected $fillable = [
        'optical_network_id',
        'olt_id',
        'network_zone_id',
        'frame',
        'slot',
        'port',
        'description',
        'splitter_ratio',
        'max_onts',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'frame' => 'integer',
        'slot' => 'integer',
        'port' => 'integer',
        'max_onts' => 'integer',
    ];

    public function network()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    public function olt()
    {
        return $this->belongsTo(Olt::class);
    }

    public function zone()
    {
        return $this->belongsTo(NetworkZone::class, 'network_zone_id');
    }

    public function napBoxes()
    {
        return $this->hasMany(NapBox::class);
    }

    /**
     * ONTs conectadas a este puerto.
     *
     * El vínculo es por slot/port y no por una clave foránea porque
     * las ONTs se importan de la OLT sin saber nada de este módulo:
     * el equipo solo reporta dónde está conectada cada una.
     */
    public function onts()
    {
        return $this->hasMany(Ont::class, 'olt_id', 'olt_id')
            ->where('slot', $this->slot)
            ->where('port', $this->port);
    }

    /** Nombre tal como lo escribe la OLT: 0/1/1 */
    public function getEtiquetaAttribute(): string
    {
        return "{$this->frame}/{$this->slot}/{$this->port}";
    }

    /** Cuántas ONTs cuelgan realmente del puerto. */
    public function ontsConectadas(): int
    {
        return Ont::where('olt_id', $this->olt_id)
            ->where('slot', $this->slot)
            ->where('port', $this->port)
            ->count();
    }

    /**
     * Ocupación del troncal.
     *
     * @return array{conectadas: int, maximo: int, porcentaje: float, libres: int}
     */
    public function ocupacion(): array
    {
        $conectadas = $this->ontsConectadas();
        $maximo = max($this->max_onts, 1);

        return [
            'conectadas' => $conectadas,
            'maximo' => $this->max_onts,
            'libres' => max($this->max_onts - $conectadas, 0),
            'porcentaje' => round($conectadas / $maximo * 100, 1),
        ];
    }

    /**
     * Color con el que se pinta la ocupación.
     *
     * Los umbrales no son arbitrarios: por encima del 80% ya hay que
     * estar gestionando el siguiente puerto, porque tirar fibra no se
     * hace en una tarde.
     */
    public static function colorDeOcupacion(float $porcentaje): string
    {
        return match (true) {
            $porcentaje >= 90 => 'danger',
            $porcentaje >= 80 => 'warning',
            $porcentaje >= 50 => 'info',
            default => 'success',
        };
    }
}
