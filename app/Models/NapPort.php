<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un puerto concreto de una caja NAP.
 *
 * "OCUPADO" NO SE GUARDA
 * ----------------------
 * El estado guardado solo distingue libre / dañado / reservado. Que
 * un puerto esté ocupado se deduce de si hay un contrato apuntando a
 * él. Guardarlo además en una columna crearía un segundo lugar donde
 * la verdad puede quedar desalineada: bastaría con dar de baja un
 * contrato sin acordarse de liberar el puerto para que la caja
 * apareciera llena teniendo espacio.
 */
class NapPort extends Model
{
    /** El puerto está disponible para instalar. */
    public const LIBRE = 'libre';

    /** Quemado, con el conector roto o el splitter sin salida. */
    public const DANADO = 'danado';

    /** Apartado para una instalación ya comprometida. */
    public const RESERVADO = 'reservado';

    protected $fillable = [
        'nap_box_id',
        'number',
        'status',
        'notes',
    ];

    protected $casts = [
        'number' => 'integer',
    ];

    public function napBox()
    {
        return $this->belongsTo(NapBox::class);
    }

    /** Contrato instalado en este puerto, si lo hay. */
    public function contract()
    {
        return $this->hasOne(Contract::class, 'nap_port_id');
    }

    /** ¿Hay un cliente conectado aquí? */
    public function estaOcupado(): bool
    {
        return $this->contract !== null;
    }

    /**
     * ¿Se puede instalar un cliente en este puerto?
     *
     * Un puerto dañado o reservado no está disponible aunque esté
     * libre de clientes.
     */
    public function estaDisponible(): bool
    {
        return !$this->estaOcupado() && $this->status === self::LIBRE;
    }

    /** Estado efectivo, mezclando lo guardado con lo deducido. */
    public function getEstadoAttribute(): string
    {
        return $this->estaOcupado() ? 'ocupado' : $this->status;
    }

    public function getEstadoLegibleAttribute(): string
    {
        return match ($this->estado) {
            'ocupado' => 'Ocupado',
            self::DANADO => 'Dañado',
            self::RESERVADO => 'Reservado',
            default => 'Libre',
        };
    }

    public function getEstadoColorAttribute(): string
    {
        return match ($this->estado) {
            'ocupado' => 'primary',
            self::DANADO => 'danger',
            self::RESERVADO => 'warning',
            default => 'success',
        };
    }

    /** Estados que puede fijar una persona (ocupado no es uno). */
    public static function estadosEditables(): array
    {
        return [
            self::LIBRE => 'Libre',
            self::RESERVADO => 'Reservado',
            self::DANADO => 'Dañado',
        ];
    }
}
