<?php

namespace App\Services\Snmp;

use App\Models\Olt;

/**
 * Crea clientes SNMP para una OLT.
 *
 * Es una envoltura de una línea sobre SnmpClient::forOlt(), y existe
 * por una razón concreta: ese método es estático, y un estático no se
 * puede sustituir en una prueba. Todo lo que dependa de él solo se
 * puede probar contra una OLT de verdad, que es como decir que no se
 * puede probar.
 *
 * Pasando por aquí, una prueba registra su propia fábrica en el
 * contenedor y devuelve un cliente simulado que responde lo que
 * respondería el equipo. Lo que se prueba entonces es lo que importa:
 * que los datos crudos del SNMP se interpreten bien.
 *
 * El método estático se conserva para el código que ya lo usaba: esto
 * se añade, no reemplaza nada.
 */
class SnmpClientFactory
{
    public function forOlt(Olt $olt): ?SnmpClient
    {
        return SnmpClient::forOlt($olt);
    }
}
