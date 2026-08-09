<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Support\Geolocation;
use App\Support\NapSuggestion;
use Illuminate\Support\Collection;

/**
 * "¿A qué caja conecto esta casa, y en qué puerto?"
 *
 * Es la pregunta que hoy se responde llamando por radio al que conoce
 * la zona. Con la vivienda georreferenciada y las cajas documentadas se
 * puede responder sola: la caja más cercana con cupo, y el primer
 * puerto libre que tiene.
 *
 * DOS CAUTELAS QUE DAN SENTIDO AL RESULTADO
 * -----------------------------------------
 *  · Es una SUGERENCIA, no una asignación. La fibra no vuela en línea
 *    recta: entre la casa y la caja más cercana puede haber un río o
 *    una avenida sin cruce. Por eso se ofrecen varias candidatas
 *    ordenadas por distancia y el técnico decide.
 *  · La ocupación se calcula en el momento, nunca se guarda. Es la
 *    misma regla del módulo de redes: el puerto libre de ahora puede
 *    estar ocupado dentro de diez minutos (ver NapPort).
 *
 * Se apoya en NapBox::scopeCercanasA, que hace el haversine dentro de
 * SQL para no traerse cientos de cajas a memoria.
 */
class NapFinder
{
    /**
     * Radio de búsqueda por defecto, en kilómetros.
     *
     * Un kilómetro es lo que da un drop de acometida con holgura: más
     * allá ya no es "la caja de al lado", es tender red nueva.
     */
    public const DEFAULT_RADIUS_KM = 1.0;

    /**
     * Cajas cercanas a un punto, de la más próxima a la más lejana.
     *
     * @return Collection<int, NapSuggestion>
     */
    public function nearestTo(
        float $latitude,
        float $longitude,
        float $radiusKm = self::DEFAULT_RADIUS_KM,
        int $limit = 5,
        ?int $branchId = null,
    ): Collection {
        if (!Geolocation::isUsable($latitude, $longitude)) {
            return collect();
        }

        return NapBox::deSucursal($branchId)
            ->cercanasA($latitude, $longitude, $radiusKm)
            // Una caja en mantenimiento o retirada no se le ofrece a
            // nadie: sugerirla sería mandar al técnico a una caja que
            // se sabe que no sirve.
            ->where('status', NapBox::OPERATIVA)
            // Los puertos y sus contratos hacen falta para saber cuál
            // está libre; sin precargarlos serían decenas de consultas.
            ->with(['ports.contract', 'zone', 'ponPort.olt'])
            ->limit($limit)
            ->get()
            ->map(fn (NapBox $napBox) => new NapSuggestion(
                napBox: $napBox,
                // scopeCercanasA devuelve kilómetros; en la calle se
                // habla en metros.
                distanceM: round((float) $napBox->distancia_km * 1000, 1),
                nextFreePort: $this->nextFreePort($napBox),
                freePorts: $napBox->puertosDisponibles(),
            ));
    }

    /**
     * Cajas cercanas a la vivienda de un contrato.
     *
     * Vacío si el contrato no está georreferenciado: sin punto no hay
     * nada que medir, y devolver "las cajas de la sucursal" sin más
     * sería una sugerencia inventada.
     *
     * @return Collection<int, NapSuggestion>
     */
    public function forContract(
        Contract $contract,
        float $radiusKm = self::DEFAULT_RADIUS_KM,
        int $limit = 5,
    ): Collection {
        if (!$contract->isGeolocated()) {
            return collect();
        }

        return $this->nearestTo(
            (float) $contract->latitude,
            (float) $contract->longitude,
            $radiusKm,
            $limit,
            (int) $contract->branch_id,
        );
    }

    /**
     * Primer puerto donde se puede instalar hoy mismo.
     *
     * "Primero" es el de menor número, que es el orden en el que se
     * llenan las cajas en campo: así los libres quedan juntos al final
     * y el siguiente instalador no tiene que buscar hueco.
     */
    private function nextFreePort(NapBox $napBox): ?NapPort
    {
        return $napBox->ports
            ->sortBy('number')
            ->first(fn (NapPort $port) => $port->estaDisponible());
    }
}
