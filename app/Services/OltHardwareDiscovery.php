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
     * @return array{tarjetas: int, pon: int, pon_nuevos: int, uplinks: int, interfaces: int}
     */
    public function descubrir(Olt $olt): array
    {
        if (!$olt->optical_network_id) {
            throw new RuntimeException(
                "La OLT {$olt->name} no pertenece a ninguna red óptica. "
                . 'Asígnela a una red antes de descubrir sus puertos: los puertos PON '
                . 'se documentan dentro de una red.'
            );
        }

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

        $patronPon = $marca['pon_discovery_pattern'] ?? null;
        $patronUplink = $marca['uplink_discovery_pattern'] ?? null;

        foreach ($descripciones as $ifIndex => $descripcion) {
            if ($patronPon && preg_match($patronPon, $descripcion, $m)) {
                $pon[] = [
                    'if_index' => $ifIndex,
                    'frame' => (int) $m[1],
                    'slot' => (int) $m[2],
                    'port' => (int) $m[3],
                    'alias' => $alias[$ifIndex] ?? null,
                    'admin' => $this->estadoLegible($adminEstado[$ifIndex] ?? null),
                    'oper' => $this->estadoLegible($operEstado[$ifIndex] ?? null),
                ];

                continue;
            }

            if ($patronUplink && preg_match($patronUplink, $descripcion, $m)) {
                $uplinks[] = [
                    'if_index' => $ifIndex,
                    'name' => $descripcion,
                    'frame' => (int) $m[1],
                    'slot' => (int) $m[2],
                    'port' => (int) $m[3],
                    'alias' => $alias[$ifIndex] ?? null,
                    'admin' => $this->estadoLegible($adminEstado[$ifIndex] ?? null),
                    'oper' => $this->estadoLegible($operEstado[$ifIndex] ?? null),
                    'speed' => isset($velocidad[$ifIndex]) ? (int) $velocidad[$ifIndex] : null,
                ];
            }
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
