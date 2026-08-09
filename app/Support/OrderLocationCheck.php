<?php

namespace App\Support;

use App\Models\TechnicalOrder;

/**
 * ¿La orden se cerró donde está el servicio?
 *
 * PARA QUÉ SIRVE
 * --------------
 * Una orden cerrada desde el sofá de la casa del técnico y otra cerrada
 * en la acometida del cliente se ven exactamente igual en el sistema.
 * Comparar el punto de cierre con el de la vivienda es lo único que las
 * distingue, y es lo que mira el supervisor al verificar.
 *
 * POR QUÉ NO ES UN SIMPLE "SÍ O NO"
 * ---------------------------------
 * El GPS de un celular miente, y miente distinto en cada sitio: bajo
 * techo, entre edificios o en zona rural sin cobertura el margen de
 * error pasa de diez metros a varios cientos. Acusar a un técnico
 * porque su teléfono reportó 200 m de desvío con 300 m de margen de
 * error sería injusto y, peor, haría que nadie se fiara del indicador.
 *
 * Por eso se descuenta el margen que reportó el propio dispositivo
 * antes de juzgar, y hay un estado intermedio ("por revisar") en vez de
 * un veredicto tajante. El sistema señala, no sentencia.
 *
 * TAMBIÉN SE DISTINGUE "NO SE SABE"
 * ---------------------------------
 * Que el contrato no esté ubicado, o que el dispositivo no diera
 * posición, NO es una orden sospechosa: es una orden sin datos. Se
 * dicen con estados propios para que nadie confunda una cosa con otra.
 */
final class OrderLocationCheck
{
    /** No hay punto de cierre: el dispositivo no dio posición. */
    public const WITHOUT_LOCATION = 'sin_ubicacion';

    /** El contrato todavía no está georreferenciado: nada que comparar. */
    public const WITHOUT_REFERENCE = 'sin_referencia';

    /** El cierre cae dentro del margen razonable de la vivienda. */
    public const MATCHES = 'coincide';

    /** Se pasa del margen, pero poco: conviene mirarlo. */
    public const TO_REVIEW = 'por_revisar';

    /** Muy lejos de la vivienda para que sea un error de medición. */
    public const FAR = 'lejana';

    /**
     * Radio en metros dentro del cual se da por bueno el cierre.
     *
     * 150 m es aproximadamente una manzana urbana con holgura: cubre
     * que el técnico cerrara la orden desde el poste, la caja NAP o la
     * acera de enfrente, que es lo normal.
     */
    public const TOLERANCE_M = 150;

    /**
     * A partir de aquí ya no se puede explicar por la medición.
     *
     * Entre la tolerancia y este límite el caso queda "por revisar":
     * puede ser un GPS malo en interior o puede ser que la dirección
     * del contrato esté mal ubicada en el mapa, que también pasa.
     */
    public const FAR_THRESHOLD_M = 500;

    private function __construct(
        public readonly string $status,
        public readonly ?float $distanceM,
        public readonly ?int $accuracyM,
        public readonly ?float $adjustedDistanceM,
    ) {
    }

    public static function for(TechnicalOrder $order): self
    {
        if (!$order->hasClosingLocation()) {
            return new self(self::WITHOUT_LOCATION, null, null, null);
        }

        $distance = $order->distanceToService();

        if ($distance === null) {
            return new self(self::WITHOUT_REFERENCE, null, $order->closing_accuracy_m, null);
        }

        $accuracy = $order->closing_accuracy_m;

        // Se le concede al técnico todo el margen de error que reportó
        // su propio dispositivo: se juzga la distancia que NO se puede
        // explicar por la medición.
        $adjusted = max(0.0, $distance - (float) ($accuracy ?? 0));

        $status = match (true) {
            $adjusted <= self::TOLERANCE_M => self::MATCHES,
            $adjusted <= self::FAR_THRESHOLD_M => self::TO_REVIEW,
            default => self::FAR,
        };

        return new self($status, $distance, $accuracy, $adjusted);
    }

    /** Frase corta para la pantalla. */
    public function label(): string
    {
        return match ($this->status) {
            self::MATCHES => 'Cerrada en el sitio del servicio',
            self::TO_REVIEW => 'Cerrada cerca, conviene revisar',
            self::FAR => 'Cerrada lejos del servicio',
            self::WITHOUT_REFERENCE => 'El contrato no tiene ubicación registrada',
            default => 'El técnico cerró sin ubicación',
        };
    }

    /** Color de Bootstrap del indicador. */
    public function color(): string
    {
        return match ($this->status) {
            self::MATCHES => 'success',
            self::TO_REVIEW => 'warning',
            self::FAR => 'danger',
            default => 'secondary',
        };
    }

    public function icon(): string
    {
        return match ($this->status) {
            self::MATCHES => 'fa-check-circle',
            self::TO_REVIEW => 'fa-exclamation-circle',
            self::FAR => 'fa-times-circle',
            default => 'fa-question-circle',
        };
    }

    /** ¿Hay dos puntos que dibujar en el mapa? */
    public function isComparable(): bool
    {
        return $this->distanceM !== null;
    }

    /** Distancia tal como se le muestra a una persona. */
    public function humanDistance(): string
    {
        return Geolocation::humanize($this->distanceM);
    }
}
