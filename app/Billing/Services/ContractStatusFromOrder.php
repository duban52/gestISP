<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\TechnicalOrder;
use App\Reports\Support\OrderDetailMap;
use App\Services\ContractDecommissioner;

/**
 * En qué estado queda el contrato al cerrarse una orden.
 *
 * POR QUÉ UN SERVICIO Y NO CUATRO LÍNEAS EN EL CONTROLADOR
 * --------------------------------------------------------
 * Porque ahí estaba, y comparaba LITERALES:
 *
 *     $activationDetails = ['Instalacion de servicio', 'Reconexión', ...]
 *     if (in_array($technicalOrder->detail, $activationDetails))
 *
 * Con esa forma, «Instalación de servicio» —con tilde, que es como la
 * escribe media aplicación— no activaba nada: la orden se cerraba y el
 * contrato se quedaba en «Por Instalar». El sistema ya tenía
 * `OrderDetailMap` para normalizar tildes, sufijos y paréntesis
 * exactamente por esto, y este camino no lo usaba.
 *
 * LAS DOS VÍAS
 * ------------
 * · Por el DETALLE, en las órdenes de campo: lo que se hizo decide el
 *   estado. Instalar activa, cortar suspende, retirar da de baja.
 * · Por el ESTADO PEDIDO, en las administrativas: no hay trabajo de
 *   campo del que deducir nada, así que se dice explícitamente al
 *   crearla.
 *
 * SI NO CORRESPONDE NINGUNO, NO SE TOCA
 * -------------------------------------
 * Una incidencia resuelta, una configuración, un traslado: cierran sin
 * cambiar el estado. Devolver null y no tocar es distinto de devolver
 * «Activo» por defecto — eso reactivaría contratos suspendidos cada vez
 * que se les resuelve una avería.
 */
class ContractStatusFromOrder
{
    /** @var array{hechos: string[], pendientes: string[]}|null */
    private ?array $ultimoParte = null;

    /**
     * Detalle normalizado => estado que deja en el contrato.
     *
     * Las claves son las de `OrderDetailMap`, ya sin tildes ni
     * paréntesis.
     */
    private const POR_DETALLE = [
        'instalacion de servicio' => ContractStatus::Activo,
        'reconexion' => ContractStatus::Activo,
        'corte de servicio' => ContractStatus::Suspendido,
        'suspension temporal' => ContractStatus::Suspendido,
        'retiro de servicio' => ContractStatus::Retirado,
    ];

    /**
     * El estado que corresponde, o null si la orden no cambia ninguno.
     */
    public function para(TechnicalOrder $orden): ?ContractStatus
    {
        if ($orden->esAdministrativa()) {
            return ContractStatus::tryFrom((string) $orden->target_contract_status);
        }

        return self::POR_DETALLE[OrderDetailMap::clave($orden->detail)] ?? null;
    }

    /**
     * Aplica el cambio al contrato, si lo hay.
     *
     * LA FECHA DE ACTIVACIÓN SE MANTIENE COMO ESTABA: se pisa en cada
     * activación, incluidas las reconexiones. No es evidente que esté
     * bien —una reconexión a mitad de mes hace que ese mes se prorratee
     * como si el contrato fuera nuevo, cuando el cliente estuvo cortado
     * por no pagar— pero cambiarlo aquí sería mover una regla de
     * facturación de refilón, dentro de un cambio que va de otra cosa.
     * Queda señalado.
     *
     * @return ContractStatus|null el estado aplicado, para poder
     *         contarlo en la trazabilidad de quien llama
     */
    public function aplicar(TechnicalOrder $orden): ?ContractStatus
    {
        $contrato = $orden->contract;
        $nuevo = $contrato ? $this->para($orden) : null;

        if (!$nuevo || $contrato->status === $nuevo->value) {
            return null;
        }

        $cambios = ['status' => $nuevo->value];

        if ($nuevo === ContractStatus::Activo) {
            $cambios['activation_date'] = now();
        }

        $contrato->update($cambios);

        // DAR DE BAJA ES TAMBIÉN SOLTAR LO QUE OCUPABA.
        //
        // Va aquí y no en el controlador porque son DOS caminos los que
        // dan de baja —cerrar una orden de retiro y una orden
        // administrativa— y en cuanto esto se escriba en los dos, un día
        // uno de ellos se olvidará. El estado es lo que dispara la
        // liberación, no el tipo de orden.
        //
        // El parte de lo ocurrido se guarda para que quien cerró la
        // orden pueda verlo: si la OLT estaba caída, hay algo que
        // terminar a mano.
        $this->ultimoParte = ContractStatus::esFinal($nuevo->value)
            ? app(ContractDecommissioner::class)->liberar($contrato->fresh())
            : null;

        return $nuevo;
    }

    /**
     * Qué se liberó —y qué quedó pendiente— en la última baja aplicada.
     *
     * Se expone así, y no devolviéndolo, para no cambiarle la firma a
     * `aplicar()`: a casi todos los que llaman solo les importa el
     * estado. Quien tenga que avisar al usuario lo pide aquí.
     *
     * @return array{hechos: string[], pendientes: string[]}|null
     */
    public function ultimoParte(): ?array
    {
        return $this->ultimoParte;
    }
}
