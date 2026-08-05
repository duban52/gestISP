<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Caja NAP / CTO: donde se conectan los clientes.
 *
 * Es la unidad de la que vive el día a día de un ISP: el instalador
 * pregunta "¿en qué caja y qué puerto?", y el de ventas "¿hay cupo
 * cerca de esta dirección?". Por eso lleva coordenadas además de
 * dirección: sin el punto en el mapa, la segunda pregunta no tiene
 * respuesta rápida.
 */
class NapBox extends Model
{
    public const OPERATIVA = 'operativa';
    public const MANTENIMIENTO = 'mantenimiento';
    public const RETIRADA = 'retirada';

    /** Capacidades habituales de una CTO en campo. */
    public const CAPACIDADES = [4, 8, 12, 16, 24, 32];

    protected $fillable = [
        'optical_network_id',
        'pon_port_id',
        'network_zone_id',
        'code',
        'name',
        'capacity',
        'splitter_ratio',
        'address',
        'reference',
        'latitude',
        'longitude',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function network()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    public function ponPort()
    {
        return $this->belongsTo(PonPort::class);
    }

    public function zone()
    {
        return $this->belongsTo(NetworkZone::class, 'network_zone_id');
    }

    public function ports()
    {
        return $this->hasMany(NapPort::class)->orderBy('number');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Contratos instalados en esta caja, a través de sus puertos. */
    public function contracts()
    {
        return $this->hasManyThrough(
            Contract::class,
            NapPort::class,
            'nap_box_id',
            'nap_port_id',
        );
    }

    // ==================== Ocupación ====================

    /** Puertos con un cliente conectado. */
    public function puertosOcupados(): int
    {
        return $this->ports->filter(fn (NapPort $p) => $p->estaOcupado())->count();
    }

    /** Puertos donde se puede instalar hoy mismo. */
    public function puertosDisponibles(): int
    {
        return $this->ports->filter(fn (NapPort $p) => $p->estaDisponible())->count();
    }

    /**
     * Resumen de la caja.
     *
     * El porcentaje se calcula sobre la CAPACIDAD TOTAL, no sobre los
     * puertos utilizables: una caja de 8 con dos quemados y seis
     * ocupados está al 100% de lo que puede dar, pero sigue siendo una
     * caja de 8 que rinde 6. Mezclar ambas cifras escondería el daño.
     *
     * @return array{capacidad: int, ocupados: int, disponibles: int, inutilizables: int, porcentaje: float}
     */
    public function ocupacion(): array
    {
        $ocupados = $this->puertosOcupados();
        $disponibles = $this->puertosDisponibles();
        $capacidad = max($this->capacity, 1);

        return [
            'capacidad' => $this->capacity,
            'ocupados' => $ocupados,
            'disponibles' => $disponibles,
            // Dañados o reservados: ni ocupados ni ofrecibles
            'inutilizables' => max($this->capacity - $ocupados - $disponibles, 0),
            'porcentaje' => round($ocupados / $capacidad * 100, 1),
        ];
    }

    /** ¿Queda dónde conectar a alguien? */
    public function tieneCupo(): bool
    {
        return $this->puertosDisponibles() > 0;
    }

    /** Color del indicador según lo llena que esté. */
    public function getColorOcupacionAttribute(): string
    {
        return PonPort::colorDeOcupacion($this->ocupacion()['porcentaje']);
    }

    /** ¿Está ubicada en el mapa? */
    public function estaGeorreferenciada(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getEstadoLegibleAttribute(): string
    {
        return match ($this->status) {
            self::MANTENIMIENTO => 'En mantenimiento',
            self::RETIRADA => 'Retirada',
            default => 'Operativa',
        };
    }

    public static function estados(): array
    {
        return [
            self::OPERATIVA => 'Operativa',
            self::MANTENIMIENTO => 'En mantenimiento',
            self::RETIRADA => 'Retirada',
        ];
    }

    // ==================== Consultas ====================

    /** Cajas de la sucursal activa. */
    public function scopeDeSucursal(Builder $query, ?int $branchId = null): Builder
    {
        return $query->whereHas(
            'network',
            fn ($q) => $q->where('branch_id', $branchId ?? session('branch_id')),
        );
    }

    /**
     * Cajas ordenadas por cercanía a un punto.
     *
     * Usa la fórmula del haversine en SQL para no traerse todas las
     * cajas a memoria. 6371 es el radio de la Tierra en kilómetros,
     * así que la distancia sale en km.
     *
     * Es lo que responde "¿este prospecto tiene cobertura?" sin que
     * nadie tenga que mirar el mapa a ojo.
     */
    public function scopeCercanasA(Builder $query, float $lat, float $lng, float $radioKm = 1.0): Builder
    {
        $haversine = '(6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) *
            cos(radians(longitude) - radians(?)) +
            sin(radians(?)) * sin(radians(latitude))
        ))';

        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("nap_boxes.*, {$haversine} AS distancia_km", [$lat, $lng, $lat])
            ->having('distancia_km', '<=', $radioKm)
            ->orderBy('distancia_km');
    }
}
