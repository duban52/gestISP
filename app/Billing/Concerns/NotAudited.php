<?php

namespace App\Billing\Concerns;

/**
 * Este modelo NO entra en la trazabilidad.
 *
 * PARA QUÉ
 * --------
 * Para la telemetría: las lecturas que los sondeos escriben cada cinco
 * minutos —potencias, tráfico, estado de sesión—. Son decenas de miles
 * de filas al día que no las hace ninguna persona, y auditarlas entierra
 * lo que de verdad importa bajo ruido.
 *
 * POR QUÉ UN TRAIT Y NO UNA LISTA EN LA CONFIGURACIÓN
 * ---------------------------------------------------
 * Porque ya había una lista, en `config/audit.php`, y **fallaba
 * abierto**: se audita todo salvo lo que esté apuntado. Con ese diseño,
 * olvidarse de una tabla no da ningún error — solo hace crecer `audits`
 * en silencio hasta que alguien mira el tamaño de la base.
 *
 * Y se olvidó una: `OltPortMetric` no estaba en la lista, y sus
 * 1.965.252 lecturas produjeron otras tantas filas de auditoría.
 * `PppoeSessionMetric` y `OntMetric` sí estaban, así que el olvido era
 * evidentemente eso: un olvido, no una decisión.
 *
 * Puesto en el propio modelo, la decisión viaja con él. Quien cree la
 * siguiente tabla de métricas lo tiene delante en el archivo que está
 * escribiendo, no en un config que no va a abrir.
 *
 * LA LISTA DE `config/audit.php` SIGUE FUNCIONANDO
 * ------------------------------------------------
 * No se retira: una instalación ya desplegada la tiene puesta, y las
 * dos vías se comprueban. Lo que cambia es cuál es la principal.
 */
trait NotAudited
{
    /**
     * Lo consulta AuditServiceProvider antes de escribir nada.
     *
     * Es un método y no una propiedad para que no se pueda cambiar por
     * accidente desde fuera del modelo.
     */
    public function seAudita(): bool
    {
        return false;
    }
}
