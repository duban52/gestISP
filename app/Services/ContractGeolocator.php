<?php

namespace App\Services;

use App\Models\Contract;
use App\Services\Audit\AuditLogger;
use App\Support\Geolocation;
use RuntimeException;

/**
 * Único sitio por donde se fija o se quita la ubicación de un contrato.
 *
 * POR QUÉ UN SERVICIO Y NO UN update() SUELTO
 * -------------------------------------------
 * Mover el punto de un servicio no es cambiar un campo de texto: es
 * decir que la casa del cliente está en otro lado. De eso dependen la
 * sugerencia de caja NAP, el reparto de trabajo de los técnicos y la
 * comprobación de si una orden se cerró donde debía.
 *
 * Concentrarlo aquí garantiza tres cosas que se olvidarían repartidas
 * por los controladores:
 *
 *  1. Que la coordenada sea usable (nada de (0,0) ni de valores fuera
 *     de rango que llegan cuando un GPS responde sin haber fijado).
 *  2. Que quede constancia de QUIÉN la puso y CON QUÉ, porque un punto
 *     tomado con el GPS en la puerta vale más que uno marcado a ojo.
 *  3. Que la trazabilidad diga cuánto se movió respecto de lo que
 *     había: un ajuste de veinte metros es afinar, uno de tres
 *     kilómetros es un error o un traslado sin documentar.
 */
class ContractGeolocator
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Deja el contrato ubicado en ese punto.
     *
     * @param  string  $source  Una de las Contract::LOCATION_SOURCE_*
     *
     * @throws RuntimeException Si la coordenada no es utilizable.
     */
    public function locate(
        Contract $contract,
        float $latitude,
        float $longitude,
        string $source = Contract::LOCATION_SOURCE_MAP,
        ?int $userId = null,
    ): void {
        if (!Geolocation::isUsable($latitude, $longitude)) {
            throw new RuntimeException(
                'La ubicación recibida no es válida. Marque el punto sobre el mapa o vuelva a tomar la posición del dispositivo.'
            );
        }

        if (!array_key_exists($source, Contract::locationSources())) {
            $source = Contract::LOCATION_SOURCE_MAP;
        }

        // Cuánto se movió respecto de lo que había: es el dato que
        // convierte la entrada de auditoría en algo revisable.
        $movedMeters = $contract->isGeolocated()
            ? Geolocation::distanceInMeters(
                (float) $contract->latitude,
                (float) $contract->longitude,
                $latitude,
                $longitude,
            )
            : null;

        $wasGeolocated = $contract->isGeolocated();

        $contract->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'located_at' => now(),
            'located_by' => $userId ?? auth()->id(),
            'location_source' => $source,
        ]);

        $this->auditLogger->action(
            'contracts.located',
            $wasGeolocated
                ? sprintf(
                    'Ajustó la ubicación del contrato %s (se movió %s)',
                    $contract->numero_visible,
                    Geolocation::humanize($movedMeters),
                )
                : sprintf('Georreferenció el contrato %s', $contract->numero_visible),
            [
                'contrato' => $contract->numero_visible,
                'coordenadas' => $latitude . ', ' . $longitude,
                'origen' => Contract::locationSources()[$source],
                'desplazamiento_m' => $movedMeters,
            ],
            $contract,
            'contratos',
        );
    }

    /**
     * Quita el punto del contrato.
     *
     * Hace falta cuando se descubre que estaba mal puesto: es preferible
     * "sin ubicar" a una ubicación falsa, porque con la falsa se manda
     * un técnico a la dirección equivocada.
     */
    public function clear(Contract $contract): void
    {
        if (!$contract->isGeolocated()) {
            return;
        }

        $previous = $contract->latitude . ', ' . $contract->longitude;

        $contract->update([
            'latitude' => null,
            'longitude' => null,
            'located_at' => null,
            'located_by' => null,
            'location_source' => null,
        ]);

        $this->auditLogger->action(
            'contracts.location_cleared',
            sprintf('Quitó la ubicación del contrato %s', $contract->numero_visible),
            ['contrato' => $contract->numero_visible, 'coordenadas_anteriores' => $previous],
            $contract,
            'contratos',
        );
    }
}
