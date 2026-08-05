<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mufla o caja de empalme.
 *
 * Es donde se unen los cables: dentro van las bandejas con las
 * fusiones y, a veces, un splitter. Operativamente es el punto más
 * delicado de la red — abrir una mufla deja sin servicio a todo lo que
 * cuelga de ella mientras dure el trabajo—, y hasta ahora era lo único
 * que no estaba documentado en ninguna parte.
 */
class SpliceClosure extends Model
{
    public const OPERATIVA = 'operativa';
    public const MANTENIMIENTO = 'mantenimiento';
    public const RETIRADA = 'retirada';

    /** Dónde va montada. Cambia cómo se llega a ella en campo. */
    public const TIPOS = [
        'aerea' => 'Aérea (en poste)',
        'subterranea' => 'Subterránea (en cámara)',
        'pedestal' => 'Pedestal',
        'pared' => 'En pared o fachada',
    ];

    protected $fillable = [
        'optical_network_id',
        'network_zone_id',
        'code',
        'name',
        'type',
        'tray_count',
        'splices_per_tray',
        'address',
        'reference',
        'latitude',
        'longitude',
        'status',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'tray_count' => 'integer',
        'splices_per_tray' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function network()
    {
        return $this->belongsTo(OpticalNetwork::class, 'optical_network_id');
    }

    public function zone()
    {
        return $this->belongsTo(NetworkZone::class, 'network_zone_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function splices()
    {
        return $this->hasMany(Splice::class)->orderBy('tray')->orderBy('position');
    }

    public function splitters()
    {
        return $this->hasMany(Splitter::class);
    }

    /** Cables que entran o salen de esta mufla. */
    public function cables()
    {
        return FiberCable::where(function (Builder $q) {
            $q->where(fn ($s) => $s->where('from_type', self::class)->where('from_id', $this->id))
                ->orWhere(fn ($s) => $s->where('to_type', self::class)->where('to_id', $this->id));
        });
    }

    // ==================== Capacidad ====================

    /** Cuántas fusiones caben en total. */
    public function capacidadFusiones(): int
    {
        return $this->tray_count * $this->splices_per_tray;
    }

    /**
     * Ocupación de la mufla.
     *
     * @return array{capacidad: int, usadas: int, libres: int, porcentaje: float}
     */
    public function ocupacion(): array
    {
        $capacidad = max($this->capacidadFusiones(), 1);
        $usadas = $this->splices()->count();

        return [
            'capacidad' => $this->capacidadFusiones(),
            'usadas' => $usadas,
            'libres' => max($this->capacidadFusiones() - $usadas, 0),
            'porcentaje' => round($usadas / $capacidad * 100, 1),
        ];
    }

    public function getColorOcupacionAttribute(): string
    {
        return PonPort::colorDeOcupacion($this->ocupacion()['porcentaje']);
    }

    public function getTipoLegibleAttribute(): string
    {
        return self::TIPOS[$this->type] ?? 'Sin clasificar';
    }

    public function getEstadoLegibleAttribute(): string
    {
        return match ($this->status) {
            self::MANTENIMIENTO => 'En mantenimiento',
            self::RETIRADA => 'Retirada',
            default => 'Operativa',
        };
    }

    /** @return array<string, string> */
    public static function estados(): array
    {
        return [
            self::OPERATIVA => 'Operativa',
            self::MANTENIMIENTO => 'En mantenimiento',
            self::RETIRADA => 'Retirada',
        ];
    }

    /** Muflas de la sucursal activa. */
    public function scopeDeSucursal(Builder $query, ?int $branchId = null): Builder
    {
        return $query->whereHas(
            'network',
            fn ($q) => $q->where('branch_id', $branchId ?? session('branch_id')),
        );
    }
}
