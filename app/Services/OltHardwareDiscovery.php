<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltBoard;
use App\Models\OltUplink;
use App\Models\PonPort;
use App\Services\Audit\AuditLogger;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpClientFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Descubre el hardware real de la OLT: tarjetas, puertos PON y uplinks.
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * Antes los puertos PON se deducían de dónde había ONTs conectadas.
 * Eso deja fuera precisamente los puertos que interesan al planear:
 * los VACÍOS. Al crear una zona o colgar una caja hay que poder elegir
 * entre todos los puertos que el equipo tiene, no solo entre los que ya
 * están en uso.
 *
 * DE DÓNDE SALEN LOS DATOS
 * ------------------------
 * De la IF-MIB estándar (RFC 2863), no de MIBs propietarias: se recorre
 * ifDescr y se reconocen los puertos por su nombre ("GPON_UNI 0/1/2",
 * "XGE 0/9/1"). Es lo que garantiza que esto funcione en cualquier
 * equipo con SNMP y no solo en el modelo con el que se probó.
 *
 * El nombre de la tarjeta y la potencia óptica del puerto salen de OIDs
 * propietarios de Huawei que NO están verificados contra el equipo: son
 * un extra. Si no responden, el descubrimiento termina igual y la
 * pantalla muestra un guion. Nada depende de ellos.
 *
 * QUÉ NO TOCA
 * -----------
 * La documentación. Un puerto ya documentado —con su zona, su splitter
 * y sus cajas— conserva todo eso: el emparejamiento se hace por la
 * posición física (frame/slot/port) y no por el ifIndex, que la OLT
 * puede reasignar cuando se reinicia una tarjeta. Y un puerto que deja
 * de aparecer NO se borra, porque podría tener cajas colgando: se deja
 * de refrescar su `discovered_at` y la pantalla lo señala.
 */
class OltHardwareDiscovery
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SnmpClientFactory $clientes,
    ) {
    }

    /**
     * Recorre la OLT y actualiza su inventario físico.
     *
     * @return array{tarjetas: int, pon: int, pon_nuevos: int, uplinks: int, interfaces: int, sin_clasificar: int, ejemplos: array<int, string>}
     */
    public function descubrir(Olt $olt): array
    {
        // La OLT NO necesita pertenecer a una red para que se le miren
        // los puertos: son un hecho físico del equipo y la red es
        // documentación posterior. Un puerto descubierto sin red queda
        // sin ella y la adopta cuando la OLT se asigne a una.
        $cliente = $this->clientes->forOlt($olt);

        if (!$cliente) {
            throw new RuntimeException(
                "La OLT {$olt->name} no tiene community SNMP de lectura configurada "
                . '(o falta la extensión SNMP de PHP en el servidor).'
            );
        }

        if (!$cliente->isReachable()) {
            $cliente->close();

            throw new RuntimeException("La OLT {$olt->name} no responde por SNMP.");
        }

        $marca = $this->config($olt);
        $ifs = $marca['interfaces'] ?? [];

        // Un recorrido por tabla, no uno por puerto: una OLT con cientos
        // de interfaces se resuelve en cuatro consultas.
        $descripciones = $this->walkIndexado($cliente, $marca['if_descr'] ?? '.1.3.6.1.2.1.2.2.1.2');
        $nombres = $this->walkIndexado($cliente, $ifs['if_name'] ?? null);
        $alias = $this->walkIndexado($cliente, $ifs['if_alias'] ?? null);
        $adminEstado = $this->walkIndexado($cliente, $ifs['admin_status'] ?? null);
        $operEstado = $this->walkIndexado($cliente, $ifs['oper_status'] ?? null);
        $velocidad = $this->walkIndexado($cliente, $ifs['high_speed'] ?? null);

        $cliente->close();

        if (empty($descripciones)) {
            throw new RuntimeException(
                "La OLT {$olt->name} respondió, pero no devolvió ninguna interfaz. "
                . 'Revise que la community tenga acceso a la IF-MIB.'
            );
        }

        $pon = [];
        $uplinks = [];
        // Interfaces que no encajaron con ningún patrón. Se guardan unas
        // pocas para poder DECIR qué se vio cuando no se reconoce nada:
        // sin eso, un patrón que no encaja se ve igual que una OLT sin
        // puertos, y no hay forma de saber qué corregir.
        $sinClasificar = [];

        $patronPon = $marca['pon_discovery_pattern'] ?? null;
        $patronUplink = $marca['uplink_discovery_pattern'] ?? null;

        foreach ($descripciones as $ifIndex => $descripcion) {
            // Se prueba con los TRES nombres que publica la interfaz, no
            // solo con ifDescr. Hay firmwares —las MA5600 V800R015, por
            // ejemplo— donde ifDescr es genérico por tipo
            // ("Huawei-MA5600-V800R015-GPON_UNI", igual para los
            // dieciséis puertos) y la posición solo aparece en ifName.
            // Buscar únicamente en ifDescr deja esos equipos sin ningún
            // puerto reconocido.
            $candidatos = array_filter([
                $descripcion,
                $nombres[$ifIndex] ?? null,
                $alias[$ifIndex] ?? null,
            ]);

            if ($posicion = $this->posicionEn($candidatos, $patronPon)) {
                $pon[] = array_merge($posicion, [
                    'if_index' => $ifIndex,
                    'alias' => $alias[$ifIndex] ?? null,
                    'admin' => $this->estadoLegible($adminEstado[$ifIndex] ?? null),
                    'oper' => $this->estadoLegible($operEstado[$ifIndex] ?? null),
                ]);

                continue;
            }

            if ($posicion = $this->posicionEn($candidatos, $patronUplink)) {
                $uplinks[] = array_merge($posicion, [
                    'if_index' => $ifIndex,
                    // Se prefiere el nombre corto de ifName si lo hay:
                    // "GE 0/8/0" se lee mejor en una tabla que
                    // "Huawei-MA5800-V100R018-ETHERNET 0/8/0".
                    'name' => ($nombres[$ifIndex] ?? null) ?: $descripcion,
                    'alias' => $alias[$ifIndex] ?? null,
                    'admin' => $this->estadoLegible($adminEstado[$ifIndex] ?? null),
                    'oper' => $this->estadoLegible($operEstado[$ifIndex] ?? null),
                    'speed' => isset($velocidad[$ifIndex]) ? (int) $velocidad[$ifIndex] : null,
                ]);

                continue;
            }

            // Se anota CON su ifIndex: cuando ningún nombre trae la
            // posición, el ifIndex es el único dato que queda para
            // deducirla, y sin verlo no hay forma de saberlo.
            $sinClasificar[] = '[' . $ifIndex . '] ' . $descripcion
                . (($nombres[$ifIndex] ?? '') !== '' && ($nombres[$ifIndex] ?? null) !== $descripcion
                    ? ' (ifName: ' . $nombres[$ifIndex] . ')'
                    : '');
        }

        $resumen = DB::transaction(function () use ($olt, $pon, $uplinks) {
            $tarjetas = $this->guardarTarjetas($olt, $pon, $uplinks);
            $resultadoPon = $this->guardarPuertosPon($olt, $pon);
            $totalUplinks = $this->guardarUplinks($olt, $uplinks);

            return [
                'tarjetas' => $tarjetas,
                'pon' => $resultadoPon['total'],
                'pon_nuevos' => $resultadoPon['nuevos'],
                'uplinks' => $totalUplinks,
            ];
        });

        $resumen['interfaces'] = count($descripciones);
        $resumen['sin_clasificar'] = count($sinClasificar);
        $resumen['ejemplos'] = $this->ejemplosUtiles($sinClasificar);

        $this->auditLogger->action(
            'olts.ports_discovered',
            sprintf(
                'Descubrió el hardware de la OLT %s: %d tarjeta(s), %d puerto(s) PON (%d nuevo(s)) y %d uplink(s)',
                $olt->name,
                $resumen['tarjetas'],
                $resumen['pon'],
                $resumen['pon_nuevos'],
                $resumen['uplinks'],
            ),
            $resumen,
            $olt,
            'red',
        );

        return $resumen;
    }

    /**
     * Busca la posición física en cualquiera de los nombres de la interfaz.
     *
     * Devuelve frame/slot/port del primero que encaje, o null si ninguno
     * la lleva.
     *
     * @param  array<int, string>  $candidatos
     * @return array{frame: int, slot: int, port: int}|null
     */
    private function posicionEn(array $candidatos, ?string $patron): ?array
    {
        if (!$patron) {
            return null;
        }

        foreach ($candidatos as $texto) {
            if (preg_match($patron, $texto, $m)) {
                return [
                    'frame' => (int) $m[1],
                    'slot' => (int) $m[2],
                    'port' => (int) $m[3],
                ];
            }
        }

        return null;
    }

    /**
     * Ejemplos de interfaces sin reconocer, para poder corregir el patrón.
     *
     * Se priorizan las que TIENEN forma de puerto físico (llevan un
     * f/s/p), porque son las candidatas a ser el puerto que no se
     * reconoció. Sin este orden, la muestra se llena de interfaces
     * internas —InLoopBack0, NULL0, MEth0, Vlanif150— que siempre
     * aparecen primero y no dicen nada: pasó exactamente eso en la
     * primera OLT donde falló, y hubo que adivinar el nombre real.
     *
     * @param  array<int, string>  $sinClasificar
     * @return array<int, string>
     */
    private function ejemplosUtiles(array $sinClasificar): array
    {
        $unicas = array_values(array_unique($sinClasificar));

        $conPosicion = array_values(array_filter(
            $unicas,
            fn (string $d) => preg_match('/\d+\/\d+\/\d+/', $d) === 1,
        ));

        $resto = array_values(array_diff($unicas, $conPosicion));

        return array_slice(array_merge($conPosicion, $resto), 0, 12);
    }

    // ==================== Guardado ====================

    /**
     * Las tarjetas se deducen de los puertos encontrados.
     *
     * Se hace así, y no leyendo la tabla de tarjetas del equipo, porque
     * esa tabla es propietaria y cambia entre firmwares: agrupar por
     * frame/slot los puertos que ya se conocen funciona siempre. El
     * nombre del modelo se añade aparte, si el equipo lo publica.
     *
     * @param  array<int, array<string, mixed>>  $pon
     * @param  array<int, array<string, mixed>>  $uplinks
     */
    private function guardarTarjetas(Olt $olt, array $pon, array $uplinks): int
    {
        $porPosicion = [];

        foreach ($pon as $p) {
            $clave = $p['frame'] . '/' . $p['slot'];
            $porPosicion[$clave]['frame'] = $p['frame'];
            $porPosicion[$clave]['slot'] = $p['slot'];
            $porPosicion[$clave]['pon'] = ($porPosicion[$clave]['pon'] ?? 0) + 1;
        }

        foreach ($uplinks as $u) {
            $clave = $u['frame'] . '/' . $u['slot'];
            $porPosicion[$clave]['frame'] = $u['frame'];
            $porPosicion[$clave]['slot'] = $u['slot'];
            $porPosicion[$clave]['uplink'] = ($porPosicion[$clave]['uplink'] ?? 0) + 1;
        }

        $ahora = now();

        foreach ($porPosicion as $datos) {
            $puertosPon = $datos['pon'] ?? 0;
            $puertosUplink = $datos['uplink'] ?? 0;

            OltBoard::updateOrCreate(
                [
                    'olt_id' => $olt->id,
                    'frame' => $datos['frame'],
                    'slot' => $datos['slot'],
                ],
                [
                    // Una tarjeta con puertos PON es de acceso aunque
                    // además tenga alguna interfaz de subida.
                    'type' => $puertosPon > 0 ? 'pon' : ($puertosUplink > 0 ? 'uplink' : 'desconocida'),
                    'port_count' => $puertosPon + $puertosUplink,
                    'discovered_at' => $ahora,
                ],
            );
        }

        return count($porPosicion);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pon
     * @return array{total: int, nuevos: int}
     */
    private function guardarPuertosPon(Olt $olt, array $pon): array
    {
        $ahora = now();
        $nuevos = 0;

        foreach ($pon as $p) {
            $existente = PonPort::where('olt_id', $olt->id)
                ->where('frame', $p['frame'])
                ->where('slot', $p['slot'])
                ->where('port', $p['port'])
                ->first();

            // Lo que se refresca del equipo en cada descubrimiento. La
            // descripción NO va aquí: es de la documentación, y el
            // alias del equipo la pisaría en cada pasada.
            $delEquipo = [
                'if_index' => $p['if_index'],
                'admin_status' => $p['admin'],
                'oper_status' => $p['oper'],
                'discovered_at' => $ahora,
            ];

            if ($existente) {
                $existente->update($delEquipo);

                continue;
            }

            PonPort::create(array_merge($delEquipo, [
                'optical_network_id' => $olt->optical_network_id,
                'olt_id' => $olt->id,
                'frame' => $p['frame'],
                'slot' => $p['slot'],
                'port' => $p['port'],
                // El alias del equipo solo se usa como descripción
                // inicial: a partir de ahí manda lo que se documente.
                'description' => $p['alias'] ?: null,
                'active' => true,
            ]));

            $nuevos++;
        }

        return ['total' => count($pon), 'nuevos' => $nuevos];
    }

    /**
     * @param  array<int, array<string, mixed>>  $uplinks
     */
    private function guardarUplinks(Olt $olt, array $uplinks): int
    {
        $ahora = now();

        foreach ($uplinks as $u) {
            // Los uplinks sí se identifican por ifIndex: no son objeto
            // de documentación, son un espejo del equipo.
            OltUplink::updateOrCreate(
                ['olt_id' => $olt->id, 'if_index' => $u['if_index']],
                [
                    'name' => $u['name'],
                    'description' => $u['alias'] ?: null,
                    'frame' => $u['frame'],
                    'slot' => $u['slot'],
                    'port' => $u['port'],
                    'speed_mbps' => $u['speed'] ?: null,
                    'admin_status' => $u['admin'],
                    'oper_status' => $u['oper'],
                    'discovered_at' => $ahora,
                ],
            );
        }

        // Un uplink que desaparece SÍ se borra: no cuelga nada de él en
        // la documentación, así que dejarlo solo confundiría.
        //
        // Se compara contra los ifIndex vistos en ESTA pasada y no
        // contra la marca de tiempo: dos descubrimientos dentro del
        // mismo segundo —que pasa al redescubrir a mano— dejarían el
        // "discovered_at < ahora" en falso y no borrarían nada.
        $vistos = array_column($uplinks, 'if_index');

        OltUplink::where('olt_id', $olt->id)
            ->when($vistos !== [], fn ($q) => $q->whereNotIn('if_index', $vistos))
            ->delete();

        return count($uplinks);
    }

    // ==================== Utilidades ====================

    /**
     * Recorre una tabla y devuelve [ifIndex => valor].
     *
     * @return array<int, string>
     */
    private function walkIndexado(SnmpClient $cliente, ?string $oid): array
    {
        if (!$oid) {
            return [];
        }

        $resultado = [];

        foreach ($cliente->walk($oid) as $indice => $valor) {
            $resultado[(int) ltrim($indice, '.')] = is_string($valor) ? trim($valor) : $valor;
        }

        return $resultado;
    }

    /**
     * Traduce los códigos de ifAdminStatus / ifOperStatus (RFC 2863).
     *
     * Se guardan como texto y no como número para que la base se pueda
     * leer sin tener la RFC al lado.
     */
    private function estadoLegible(?string $codigo): ?string
    {
        if ($codigo === null || $codigo === '') {
            return null;
        }

        return match ((int) $codigo) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'notPresent',
            7 => 'lowerLayerDown',
            default => $codigo,
        };
    }

    /** @return array<string, mixed> */
    private function config(Olt $olt): array
    {
        $marca = strtolower($olt->brand ?: 'huawei');

        return config("olt_snmp.brands.{$marca}", config('olt_snmp.brands.huawei', []));
    }
}
