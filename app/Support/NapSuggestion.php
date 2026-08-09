<?php

namespace App\Support;

use App\Models\NapBox;
use App\Models\NapPort;

/**
 * Una caja NAP propuesta para conectar un servicio, con el puerto
 * concreto que habría que usar.
 *
 * Es un dato de solo lectura calculado al vuelo: NADA de esto se
 * guarda. El puerto libre de ahora puede estar ocupado dentro de diez
 * minutos, así que persistir la sugerencia sería guardar una mentira
 * con fecha de caducidad.
 */
final class NapSuggestion
{
    public function __construct(
        public readonly NapBox $napBox,
        public readonly float $distanceM,
        public readonly ?NapPort $nextFreePort,
        public readonly int $freePorts,
    ) {
    }

    /** ¿Se puede instalar hoy en esta caja? */
    public function hasRoom(): bool
    {
        return $this->nextFreePort !== null;
    }

    public function humanDistance(): string
    {
        return Geolocation::humanize($this->distanceM);
    }

    /** "NAP012 / P4", como se escribe en la ficha del contrato. */
    public function portLabel(): ?string
    {
        return $this->nextFreePort
            ? $this->napBox->code . ' / P' . $this->nextFreePort->number
            : null;
    }
}
