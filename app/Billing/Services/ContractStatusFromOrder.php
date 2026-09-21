<?php

namespace App\Billing\Services;

use App\Billing\Enums\ContractStatus;
use App\Billing\Services\ContractLiquidator;
use App\Models\Contract;
use App\Models\ContractStatusOption;
use App\Models\TechnicalOrderDetail;
use App\Models\TechnicalOrder;
use App\Services\ContractDecommissioner;
use App\Services\ContractServiceSwitch;

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
     * El estado que corresponde, o null si la orden no cambia ninguno.
     *
     * DEVUELVE EL NOMBRE, NO UN CASO DEL ENUM. Los estados ya no son
     * una lista fija: el catálogo deja crear «Exonerado» sin tocar
     * código, y un estado nuevo no tiene caso en el enum. Devolver el
     * enum obligaría a que todo estado configurable fuera además una
     * línea de PHP, que es justo lo que se quitó de en medio.
     */
    public function para(TechnicalOrder $orden): ?string
    {
        if ($orden->esAdministrativa()) {
            $pedido = (string) $orden->target_contract_status;

            // Solo si existe en el catálogo: un nombre escrito a mano
            // dejaría el contrato en un estado que nada sabe interpretar.
            return ContractStatusOption::porNombre($pedido) ? $pedido : null;
        }

        return $this->detalleDe($orden)?->target_contract_status;
    }

    /** La fila del catálogo que corresponde al detalle de la orden. */
    private function detalleDe(TechnicalOrder $orden): ?TechnicalOrderDetail
    {
        return TechnicalOrderDetail::paraDetalle($orden->detail);
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
     * @return string|null el nombre del estado aplicado, para poder
     *         contarlo en la trazabilidad de quien llama
     */
    public function aplicar(TechnicalOrder $orden): ?string
    {
        $contrato = $orden->contract;
        $nuevo = $contrato ? $this->para($orden) : null;

        // LOS EFECTOS SOBRE LOS EQUIPOS VAN AUNQUE EL ESTADO NO CAMBIE.
        //
        // Un corte sobre un contrato que ya figuraba suspendido tiene
        // que cortar igual: el estado dice lo que el sistema cree, y
        // los equipos dicen lo que el cliente tiene. Son dos cosas, y
        // esta orden se cerró para arreglar la segunda.
        $efectos = $contrato ? $this->aplicarEnLosEquipos($orden, $contrato, $nuevo) : null;

        if (!$nuevo || $contrato->status === $nuevo) {
            $this->ultimoParte = $efectos;

            return null;
        }

        $cambios = ['status' => $nuevo];

        if ($nuevo === ContractStatus::Activo->value) {
            $cambios['activation_date'] = now();
        }

        // LA LIQUIDACION VA ANTES DEL CAMBIO DE ESTADO.
        //
        // Un contrato retirado no es facturable —y esta bien que no lo
        // sea—, asi que si se emitiera despues, el propio sistema la
        // rechazaria. Aqui todavia esta vivo.
        $liquidacion = ContractStatus::esFinal($nuevo)
            ? app(ContractLiquidator::class)->liquidar($contrato)
            : null;

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
        $baja = ContractStatus::esFinal($nuevo)
            ? app(ContractDecommissioner::class)->liberar($contrato->fresh())
            : null;

        // El parte junta las dos cosas: lo que se hizo en los equipos
        // por el detalle de la orden y lo que se liberó por ser baja.
        $this->ultimoParte = $this->juntar($efectos, $baja);

        // Quien cerro la orden tiene que enterarse de que se emitio una
        // factura mas: es plata que el cliente debe y alguien va a
        // tener que cobrarle.
        if ($liquidacion && $this->ultimoParte) {
            $this->ultimoParte['hechos'][] = sprintf(
                'Se emitió la factura de liquidación %s por $%s con los cargos pendientes',
                $liquidacion->displayNumber(),
                number_format((float) $liquidacion->total, 2, ',', '.'),
            );
        }

        return $nuevo;
    }

    /**
     * Que la navegación diga lo mismo que el estado.
     *
     * DOS FUENTES, Y UNA MANDA SOBRE LA OTRA
     * --------------------------------------
     * · El DETALLE de la orden, si dice qué hacer con un equipo: es una
     *   decisión explícita de quien configuró el catálogo, y gana.
     * · El ESTADO al que queda el contrato, para todo lo demás: si tiene
     *   servicio se habilita, si no lo tiene se deshabilita.
     *
     * Por el estado es por donde entran las órdenes administrativas —que
     * no tienen detalle— y cualquier estado nuevo del catálogo: un
     * «Exonerado» con servicio habilita, un «Suspensión por fraude» sin
     * servicio corta, sin tocar código.
     *
     * Se mira el estado PEDIDO aunque el contrato ya estuviera en él: si
     * una orden lo deja «Activo» y la cuenta seguía cortada, hay que
     * habilitarla. El estado dice lo que el sistema cree; los equipos,
     * lo que el cliente tiene. Lo que ya está como se pide no se toca
     * (ver ContractServiceSwitch).
     *
     * @return array{hechos: string[], pendientes: string[]}|null
     */
    private function aplicarEnLosEquipos(TechnicalOrder $orden, Contract $contrato, ?string $nuevo): ?array
    {
        $detalle = $this->detalleDe($orden);

        // Sin detalle —una administrativa— manda solo el estado. Sin
        // estado —una incidencia, un traslado— no se pide nada: resolver
        // una avería no reactiva a un suspendido.
        $acciones = $detalle
            ? $detalle->accionesResueltas($nuevo)
            : array_fill_keys(['pppoe', 'ont'], ContractStatusOption::accionDeEquipos($nuevo));

        if ($acciones['pppoe'] === TechnicalOrderDetail::SIN_ACCION
            && $acciones['ont'] === TechnicalOrderDetail::SIN_ACCION) {
            return null;
        }

        return app(ContractServiceSwitch::class)->aplicar($contrato, $acciones['pppoe'], $acciones['ont']);
    }

    /**
     * @param  array{hechos: string[], pendientes: string[]}|null  $uno
     * @param  array{hechos: string[], pendientes: string[]}|null  $otro
     * @return array{hechos: string[], pendientes: string[]}|null
     */
    private function juntar(?array $uno, ?array $otro): ?array
    {
        if (!$uno) {
            return $otro;
        }

        if (!$otro) {
            return $uno;
        }

        return [
            'hechos' => array_merge($uno['hechos'], $otro['hechos']),
            'pendientes' => array_merge($uno['pendientes'], $otro['pendientes']),
        ];
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
