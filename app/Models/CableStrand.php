<?php

namespace App\Models;

use App\Support\FiberColors;
use Illuminate\Database\Eloquent\Model;

/**
 * Un hilo de fibra dentro de un cable.
 *
 * Se identifica por su posición física —buffer y color— porque es como
 * habla de él el técnico por radio: «el naranja del buffer verde». El
 * número corrido está para ordenar y para el que prefiere contarlos.
 *
 * Su `status` guarda SOLO lo que no se puede deducir: dañado o
 * reservado. Que el hilo esté ocupado se deduce de si participa en una
 * fusión, alimenta un splitter o entra a una caja NAP; guardarlo sería
 * repetir un dato que ya existe y que empezaría a mentir en cuanto algo
 * se desconectara por otro lado. Es la misma regla de los puertos NAP.
 */
class CableStrand extends Model
{
    public const LIBRE = 'libre';
    public const DANADO = 'danado';
    public const RESERVADO = 'reservado';

    protected $fillable = [
        'fiber_cable_id',
        'number',
        'buffer_number',
        'buffer_color',
        'strand_number',
        'strand_color',
        'status',
        'notes',
    ];

    protected $casts = [
        'number' => 'integer',
        'buffer_number' => 'integer',
        'strand_number' => 'integer',
    ];

    public function cable()
    {
        return $this->belongsTo(FiberCable::class, 'fiber_cable_id');
    }

    /** Fusiones en las que participa este hilo (una por cada extremo). */
    public function splices()
    {
        return Splice::where('strand_a_id', $this->id)->orWhere('strand_b_id', $this->id);
    }

    /** Si este hilo es la entrada de un splitter. */
    public function splitterEntrada()
    {
        return $this->hasOne(Splitter::class, 'input_strand_id');
    }

    /** Si este hilo es la salida de un splitter. */
    public function splitterSalida()
    {
        return $this->hasOne(SplitterOutput::class, 'strand_id');
    }

    /** Si este hilo alimenta una caja NAP. */
    public function napBox()
    {
        return $this->hasOne(NapBox::class, 'feed_strand_id');
    }

    // ==================== Estado ====================

    /**
     * Cuántas conexiones tiene el hilo.
     *
     * UN HILO TIENE DOS EXTREMOS, y esto es lo que hay que contar. Lo
     * normal es que use los dos: sale de un splitter por un lado y
     * alimenta una caja NAP por el otro; o está fusionado en la mufla
     * de entrada y en la de salida, que es lo que hace un tramo de
     * paso.
     *
     * Tratarlo como un interruptor de sí/no —«en cuanto se conecta a
     * algo ya no se puede usar»— hace imposible documentar la red real:
     * ningún hilo llegaría nunca al cliente.
     */
    public function conexiones(): int
    {
        return $this->splices()->count()
            + ($this->splitterEntrada()->exists() ? 1 : 0)
            + ($this->splitterSalida()->exists() ? 1 : 0)
            + ($this->napBox()->exists() ? 1 : 0);
    }

    /** ¿Está conectado a algo, aunque sea por un solo extremo? */
    public function estaEnUso(): bool
    {
        return $this->conexiones() > 0;
    }

    /** Sin tocar por ninguno de los dos extremos. */
    public function estaLibre(): bool
    {
        return $this->status === self::LIBRE && $this->conexiones() === 0;
    }

    /**
     * ¿Se le puede conectar algo más?
     *
     * Sí mientras le quede un extremo suelto. Es lo que se comprueba
     * antes de fusionarlo o de colgarle una caja.
     */
    public function estaDisponible(): bool
    {
        return $this->status === self::LIBRE && $this->conexiones() < 2;
    }

    /** Estado efectivo, mezclando lo guardado con lo deducido. */
    public function getEstadoAttribute(): string
    {
        if ($this->status !== self::LIBRE) {
            return $this->status;
        }

        return $this->estaEnUso() ? 'en_uso' : self::LIBRE;
    }

    public function getEstadoLegibleAttribute(): string
    {
        return match ($this->estado) {
            'en_uso' => 'En uso',
            self::DANADO => 'Dañado',
            self::RESERVADO => 'Reservado',
            default => 'Libre',
        };
    }

    public function getEstadoColorAttribute(): string
    {
        return match ($this->estado) {
            'en_uso' => 'primary',
            self::DANADO => 'danger',
            self::RESERVADO => 'warning',
            default => 'success',
        };
    }

    /** Estados que puede fijar una persona (en uso no es uno). */
    public static function estadosEditables(): array
    {
        return [
            self::LIBRE => 'Libre',
            self::RESERVADO => 'Reservado',
            self::DANADO => 'Dañado',
        ];
    }

    // ==================== Presentación ====================

    /** "B2 Naranja / H2 Naranja" — como se pide por radio. */
    public function getPosicionLegibleAttribute(): string
    {
        return sprintf(
            'B%d %s / H%d %s',
            $this->buffer_number,
            $this->buffer_color,
            $this->strand_number,
            $this->strand_color,
        );
    }

    /** Nombre completo con su cable, para listados fuera de la ficha. */
    public function getEtiquetaCompletaAttribute(): string
    {
        return ($this->cable?->code ?? 'cable?') . ' · ' . $this->posicion_legible;
    }

    public function getColorHexAttribute(): string
    {
        return FiberColors::hex($this->strand_number);
    }

    public function getBufferHexAttribute(): string
    {
        return FiberColors::hex($this->buffer_number);
    }
}
