<?php

namespace App\MassActions;

use App\Models\MassActionItem;

/**
 * Lo que tiene que saber hacer un proceso para poder deshacerse.
 *
 * UNA ESTRATEGIA POR TIPO, Y NADA MÁS QUE AÑADIR
 * ---------------------------------------------
 * Meter un proceso masivo nuevo en el sistema es: un caso en
 * `MassActionType`, una clase que implemente esto, y una línea en el
 * registro. Ni el motor de reversión ni la pantalla se enteran.
 *
 * EL CONTRATO SON DOS PREGUNTAS SEPARADAS, Y LA SEPARACIÓN ES EL
 * NÚCLEO DE TODO ESTO
 * -------------------------------------------------------------------
 * `revisar()` responde «¿puedo tocar esto sin pisar a nadie?» SIN
 * tocar nada. `revertir()` lo deshace, y solo se le llama si lo
 * primero dijo que sí.
 *
 * Tenerlas juntas —comprobar y escribir en el mismo método— es como
 * nacen las reversiones que sobrescriben el trabajo de otro: basta un
 * `return` mal puesto para que la comprobación se salte y el cambio
 * no.
 */
interface RevierteUnaAccionMasiva
{
    /**
     * ¿Se puede revertir este ítem?
     *
     * Devuelve `null` si sí, o el MOTIVO si no. El motivo se le enseña
     * tal cual a quien mira la pantalla, así que tiene que explicar
     * algo: «el contrato está Retirado y la acción lo dejó Suspendido»
     * sirve; «conflicto» no.
     *
     * NO PUEDE ESCRIBIR NADA. Se le llama para contar cuántos ítems
     * están en conflicto antes de que nadie confirme la reversión.
     */
    public function revisar(MassActionItem $item): ?string;

    /**
     * Deshace lo que hizo este ítem.
     *
     * Se le llama DENTRO de una transacción y solo cuando `revisar()`
     * ha devuelto null. Si lanza, el ítem queda como error y los demás
     * siguen: un registro que no se pudo deshacer no puede bloquear a
     * los otros ochocientos.
     */
    public function revertir(MassActionItem $item): void;

    /**
     * Qué se le dice a quien va a confirmar la reversión.
     *
     * Una frase que explique qué va a pasar de verdad. «Se volverán a
     * poner en el estado que tenían y se les restablecerá el servicio»
     * dice lo que hace; «se revertirá la acción» no dice nada.
     */
    public function advertencia(): string;
}
