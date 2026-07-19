<?php

/**
 * Mapa de OIDs SNMP de las OLTs.
 *
 * Consultar las ONTs por SNMP es entre 20 y 100 veces más rápido
 * que por SSH: una petición SNMP responde en milisegundos, mientras
 * que una sesión SSH exige autenticarse, entrar en modo enable,
 * posicionarse en la interfaz gpon y ejecutar comandos display con
 * paginación (varios segundos por ONT).
 *
 * Los OIDs se definen aquí y no en el código para poder ajustarlos
 * al modelo real de OLT sin tocar la aplicación, y para soportar
 * otras marcas más adelante.
 *
 * IMPORTANTE — validación contra el equipo real:
 * los OIDs de Huawei que siguen son los estándar de la MIB
 * HUAWEI-XPON-MIB y funcionan en la mayoría de MA5600/MA5800,
 * pero pueden variar según la versión de firmware. Para verificar
 * cuáles responde SU OLT y con qué escala, ejecute:
 *
 *     php artisan olt:snmp-probe {id_de_la_olt}
 *
 * El comando consulta cada OID de este archivo contra una ONT real
 * y muestra el valor crudo y el normalizado, para que pueda
 * corregir "oid" o "scale" si algo no coincide.
 *
 * Estructura de cada métrica:
 *   oid     Base del OID (se le agrega .{if_index}.{onu_id})
 *   scale   Factor por el que se multiplica el valor crudo
 *   unit    Unidad para mostrar
 *   invalid Valores que significan "sin dato" (se descartan)
 *   min/max Rango plausible; fuera de él el valor se descarta
 */

return [

    /*
    |----------------------------------------------------------
    | Parámetros de transporte
    |----------------------------------------------------------
    | timeout en microsegundos y reintentos de cada consulta.
    | max_repetitions controla el GETBULK: cuántas filas pide la
    | OLT por paquete al recorrer una tabla (más alto = menos
    | viajes de red = más rápido, hasta donde el equipo aguante).
    */
    'timeout' => env('OLT_SNMP_TIMEOUT', 1000000),   // 1 segundo
    'retries' => env('OLT_SNMP_RETRIES', 2),
    'max_repetitions' => env('OLT_SNMP_MAX_REPETITIONS', 40),

    /*
    | Segundos que se cachea la respuesta de una ONT concreta.
    | Evita machacar la OLT si el usuario refresca repetidamente.
    */
    'cache_ttl' => env('OLT_SNMP_CACHE_TTL', 10),

    /*
    |----------------------------------------------------------
    | OIDs por marca
    |----------------------------------------------------------
    */
    'brands' => [

        'huawei' => [

            /*
            | Métricas por ONT. El sufijo del OID es
            | .{if_index_del_puerto_pon}.{onu_id}
            |
            | Tabla hwGponOntOpticalDdmInfo:
            |   .1.3.6.1.4.1.2011.6.128.1.1.2.51.1.{x}
            */
            'ont_metrics' => [
                'rx_power' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4',
                    'scale' => 0.01,
                    'unit' => 'dBm',
                    'label' => 'Potencia Rx (ONT)',
                    'invalid' => [2147483647, 0],
                    'min' => -40,
                    'max' => 5,
                ],
                'tx_power' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3',
                    'scale' => 0.01,
                    'unit' => 'dBm',
                    'label' => 'Potencia Tx (ONT)',
                    'invalid' => [2147483647],
                    'min' => -40,
                    'max' => 10,
                ],
                /*
                | La potencia que la OLT recibe de la ONT viene
                | DESPLAZADA: el equipo entrega (dBm × 100) + 10000
                | para evitar negativos. Verificado contra la
                | salida SSH del equipo real: SNMP 8209 ↔ CLI
                | -17.83 dBm.
                */
                'olt_rx_power' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.6',
                    'scale' => 0.01,
                    'offset' => -100,
                    'unit' => 'dBm',
                    'label' => 'Potencia Rx en OLT',
                    'invalid' => [2147483647],
                    'min' => -40,
                    'max' => 5,
                ],
                'temperature' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.1',
                    'scale' => 1,
                    'unit' => '°C',
                    'label' => 'Temperatura',
                    'invalid' => [2147483647, -1],
                    'min' => -50,
                    'max' => 120,
                ],
                /*
                | OJO con estas dos: en el equipo real la columna
                | .5 es el VOLTAJE (en milivoltios) y la .2 es la
                | CORRIENTE (en mA), al contrario de lo que sugiere
                | el orden habitual de la MIB. Comprobado contra la
                | salida SSH: SNMP .5=3252 ↔ CLI 3.26 V, y
                | SNMP .2=11 ↔ CLI 11.0 mA.
                */
                'voltage' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.5',
                    'scale' => 0.001,
                    'unit' => 'V',
                    'label' => 'Voltaje',
                    'invalid' => [2147483647, -1],
                    'min' => 0,
                    'max' => 10,
                ],
                'bias_current' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.2',
                    'scale' => 1,
                    'unit' => 'mA',
                    'label' => 'Corriente de bias',
                    'invalid' => [2147483647, -1],
                    'min' => 0,
                    'max' => 200,
                ],

                /*
                | Tabla hwGponDeviceOntInfo:
                |   .1.3.6.1.4.1.2011.6.128.1.1.2.46.1.{x}
                */
                'distance' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20',
                    'scale' => 1,
                    'unit' => 'm',
                    'label' => 'Distancia',
                    'invalid' => [2147483647, -1],
                    'min' => 0,
                    'max' => 60000,
                ],
                'run_status' => [
                    'oid' => '.1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15',
                    'scale' => 1,
                    'unit' => '',
                    'label' => 'Estado operativo',
                    'invalid' => [],
                    'map' => [1 => 'online', 2 => 'offline'],
                ],
            ],

            /*
            | Contadores de tráfico por ONT (para la gráfica de
            | ancho de banda). Se consultan sobre el ifIndex propio
            | de la ONT — distinto del ifIndex del puerto PON — que
            | se resuelve recorriendo ifDescr con el patrón de abajo.
            |
            | Son contadores de 64 bits (ifHCInOctets/ifHCOutOctets):
            | el ancho de banda se calcula por diferencia entre dos
            | lecturas consecutivas.
            */
            'traffic' => [
                'in_octets' => '.1.3.6.1.2.1.31.1.1.1.6',
                'out_octets' => '.1.3.6.1.2.1.31.1.1.1.10',
            ],

            /*
            | Tabla de descripciones de interfaz (ifDescr), usada
            | para resolver el ifIndex de cada ONT.
            */
            'if_descr' => '.1.3.6.1.2.1.2.2.1.2',

            /*
            | Patrón para reconocer la interfaz del PUERTO PON.
            | %slot% y %port% se sustituyen. Ejemplo real:
            | "GPON_UNI 0/1/2"
            */
            'pon_port_pattern' => '/GPON_UNI\s+\d+\/%slot%\/%port%$/',

            /*
            | Patrón para reconocer la interfaz de una ONT concreta.
            | %slot%, %port% y %onu% se sustituyen. Las OLT Huawei
            | suelen exponerla como "GPON ONT 0/1/2:5" o similar.
            | Si su equipo usa otro formato, ajústelo aquí (el
            | comando olt:snmp-probe lista las ifDescr reales).
            */
            'ont_if_pattern' => '/ONT\s+\d+\/%slot%\/%port%:%onu%$/',
        ],

    ],
];
