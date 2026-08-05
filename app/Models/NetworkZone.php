<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zona de una red: un sector que agrupa varios puertos PON.
 *
 * Es la unidad con la que se PLANEA la expansión. Una NAP llena se
 * resuelve poniendo otra caja; una zona llena se resuelve tirando otro
 * puerto PON, que es una obra distinta y con otros tiempos. Sin esta
 * capa la segunda no se ve venir hasta que ya no hay dónde conectar.
 *
 * Es opcional: una caja puede existir sin zona mientras se documenta
 * una red que ya está tendida.
 */
class NetworkZone extends Model
{
    protected $fillable = [
        'optical_network_id',
        'name',
        'description',
        'color',
    ];

    public function network()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    public function ponPorts()
    {
        return $this->hasMany(PonPort::class);
    }

    public function napBoxes()
    {
        return $this->hasMany(NapBox::class);
    }

    /**
     * Capacidad y ocupación de toda la zona, sumando sus cajas.
     *
     * @return array{capacidad: int, ocupados: int, libres: int, porcentaje: float}
     */
    public function ocupacion(): array
    {
        $cajas = $this->napBoxes()->with('ports.contract')->get();

        $capacidad = (int) $cajas->sum('capacity');
        $ocupados = $cajas->sum(fn (NapBox $caja) => $caja->puertosOcupados());

        return [
            'capacidad' => $capacidad,
            'ocupados' => $ocupados,
            'libres' => max($capacidad - $ocupados, 0),
            'porcentaje' => $capacidad > 0 ? round($ocupados / $capacidad * 100, 1) : 0.0,
        ];
    }
}
