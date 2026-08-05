<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltPortMetric;
use App\Models\OltUplink;
use App\Models\Ont;
use App\Models\PonPort;
use App\Services\Snmp\SnmpClientFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Muestrea el tráfico de los puertos PON y de los uplinks.
 *
 * Es el gemelo de OntPoller, pero un nivel más arriba: en vez de una
 * muestra por ONT, una por puerto. De aquí salen la gráfica del modal
 * de puerto y el aviso de uplink saturado.
 *
 * POR QUÉ ES BARATO
 * -----------------
 * Todo se resuelve con DOS recorridos de tabla por OLT
 * (ifHCInOctets e ifHCOutOctets), no con una consulta por puerto: una
 * OLT de doscientos puertos cuesta lo mismo que una de ocho. Por eso
 * puede correr cada cinco minutos sin molestar al equipo.
 *
 * EL DATO QUE SE GUARDA DOS VECES
 * -------------------------------
 * Los contadores crudos van al historial porque hacen falta para
 * calcular la diferencia con la muestra siguiente; los bits por segundo
 * ya calculados van tanto al historial como a la fila del puerto, para
 * que la ficha de la OLT pinte la rejilla sin recorrer nada.
 */
class OltPortPoller
{
    public function __construct(
        private readonly OltSnmpService $snmp,
        private readonly SnmpClientFactory $clientes,
    ) {
    }

    /**
     * @return array{pon: int, uplinks: int, con_trafico: int, elapsed_ms: float, reachable: bool}
     */
    public function poll(Olt $olt): array
    {
        $inicio = microtime(true);
        $vacio = ['pon' => 0, 'uplinks' => 0, 'con_trafico' => 0, 'elapsed_ms' => 0.0, 'reachable' => true];

        $puertos = PonPort::where('olt_id', $olt->id)->whereNotNull('if_index')->get();
        $uplinks = OltUplink::where('olt_id', $olt->id)->get();

        if ($puertos->isEmpty() && $uplinks->isEmpty()) {
            return $vacio;
        }

        $contadores = $this->snmp->bulkTrafficCounters($olt);

        if (empty($contadores)) {
            return array_merge($vacio, [
                'pon' => $puertos->count(),
                'uplinks' => $uplinks->count(),
                'elapsed_ms' => round((microtime(true) - $inicio) * 1000, 1),
                'reachable' => false,
            ]);
        }

        $medidoEn = now();
        $conTrafico = 0;

        // Cuántas ONTs hay y cuántas en línea por puerto, de una sola
        // consulta a la base: es lo que da sentido al tráfico de un
        // puerto ("300 Mbps entre 48 clientes" no es lo mismo que
        // "300 Mbps entre 4").
        $ontsPorPuerto = $this->ontsPorPuerto($olt);
        $potencias = $this->potenciasDePuerto($olt, $puertos);

        foreach ($puertos as $puerto) {
            $contador = $contadores[$puerto->if_index] ?? null;
            $conteo = $ontsPorPuerto[$puerto->slot . '/' . $puerto->port] ?? ['total' => 0, 'online' => 0];

            $tasas = $this->guardarMuestra(
                $puerto,
                $contador,
                $medidoEn,
                [
                    'tx_power' => $potencias[$puerto->if_index] ?? null,
                    'onts_total' => $conteo['total'],
                    'onts_online' => $conteo['online'],
                ],
            );

            $puerto->update([
                'in_bps' => $tasas['in_bps'],
                'out_bps' => $tasas['out_bps'],
                'tx_power' => $potencias[$puerto->if_index] ?? $puerto->tx_power,
                'measured_at' => $medidoEn,
            ]);

            if ($tasas['in_bps'] !== null) {
                $conTrafico++;
            }
        }

        foreach ($uplinks as $uplink) {
            $tasas = $this->guardarMuestra($uplink, $contadores[$uplink->if_index] ?? null, $medidoEn);

            $uplink->update([
                'in_bps' => $tasas['in_bps'],
                'out_bps' => $tasas['out_bps'],
                'measured_at' => $medidoEn,
            ]);

            if ($tasas['in_bps'] !== null) {
                $conTrafico++;
            }
        }

        return [
            'pon' => $puertos->count(),
            'uplinks' => $uplinks->count(),
            'con_trafico' => $conTrafico,
            'elapsed_ms' => round((microtime(true) - $inicio) * 1000, 1),
            'reachable' => true,
        ];
    }

    /**
     * Guarda la muestra y devuelve las tasas calculadas.
     *
     * @param  array{in?: int|null, out?: int|null}|null  $contador
     * @param  array<string, mixed>  $extra
     * @return array{in_bps: int|null, out_bps: int|null}
     */
    private function guardarMuestra(Model $puerto, ?array $contador, Carbon $medidoEn, array $extra = []): array
    {
        $entrada = $contador['in'] ?? null;
        $salida = $contador['out'] ?? null;

        $tasas = $this->calcularTasas($puerto, $entrada, $salida, $medidoEn);

        OltPortMetric::create(array_merge($extra, [
            'port_type' => $puerto->getMorphClass(),
            'port_id' => $puerto->getKey(),
            'in_octets' => $entrada,
            'out_octets' => $salida,
            'in_bps' => $tasas['in_bps'],
            'out_bps' => $tasas['out_bps'],
            'measured_at' => $medidoEn,
        ]));

        return $tasas;
    }

    /**
     * Bits por segundo a partir de la diferencia con la muestra previa.
     *
     * Contempla el reinicio del contador: cuando la OLT se reinicia
     * vuelve a cero, y restar daría un negativo. En ese caso se
     * descarta la muestra en vez de dibujar un pico que no ocurrió.
     *
     * @return array{in_bps: int|null, out_bps: int|null}
     */
    private function calcularTasas(Model $puerto, ?int $entrada, ?int $salida, Carbon $medidoEn): array
    {
        $sinDato = ['in_bps' => null, 'out_bps' => null];

        if ($entrada === null || $salida === null) {
            return $sinDato;
        }

        $anterior = OltPortMetric::where('port_type', $puerto->getMorphClass())
            ->where('port_id', $puerto->getKey())
            ->whereNotNull('in_octets')
            ->latest('measured_at')
            ->first();

        if (!$anterior) {
            return $sinDato;
        }

        $segundos = $medidoEn->diffInSeconds($anterior->measured_at);

        // Muestras demasiado juntas o demasiado separadas no dan un
        // promedio que signifique algo.
        if ($segundos < 1 || $segundos > 3600) {
            return $sinDato;
        }

        $deltaEntrada = $entrada - $anterior->in_octets;
        $deltaSalida = $salida - $anterior->out_octets;

        if ($deltaEntrada < 0 || $deltaSalida < 0) {
            return $sinDato;
        }

        return [
            'in_bps' => (int) round($deltaEntrada * 8 / $segundos),
            'out_bps' => (int) round($deltaSalida * 8 / $segundos),
        ];
    }

    /**
     * Cuántas ONTs cuelgan de cada puerto y cuántas están en línea.
     *
     * @return array<string, array{total: int, online: int}>
     */
    private function ontsPorPuerto(Olt $olt): array
    {
        $filas = Ont::where('olt_id', $olt->id)
            ->selectRaw('slot, port, COUNT(*) AS total, SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS online')
            ->groupBy('slot', 'port')
            ->get();

        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[$fila->slot . '/' . $fila->port] = [
                'total' => (int) $fila->total,
                'online' => (int) $fila->online,
            ];
        }

        return $resultado;
    }

    /**
     * Potencia de transmisión de cada puerto PON.
     *
     * Sale de un OID propietario que NO está verificado contra el
     * equipo real. Si no responde se devuelve vacío y no pasa nada: el
     * resto del muestreo funciona igual y la pantalla muestra un guion
     * donde iría la potencia.
     *
     * @param  \Illuminate\Support\Collection<int, PonPort>  $puertos
     * @return array<int, float>  [ifIndex => dBm]
     */
    private function potenciasDePuerto(Olt $olt, $puertos): array
    {
        $marca = strtolower($olt->brand ?: 'huawei');
        $definicion = config("olt_snmp.brands.{$marca}.pon_optical.tx_power");

        if (!$definicion || empty($definicion['oid']) || $puertos->isEmpty()) {
            return [];
        }

        $cliente = $this->clientes->forOlt($olt);

        if (!$cliente) {
            return [];
        }

        $crudos = $cliente->walk($definicion['oid']);
        $cliente->close();

        $resultado = [];
        $invalidos = $definicion['invalid'] ?? [];

        foreach ($crudos as $indice => $valor) {
            if (!is_numeric($valor) || in_array((int) $valor, $invalidos, true)) {
                continue;
            }

            $convertido = ((float) $valor) * ($definicion['scale'] ?? 1);

            // Fuera del rango plausible se descarta: más vale no
            // mostrar potencia que mostrar una inventada.
            if (isset($definicion['min']) && $convertido < $definicion['min']) {
                continue;
            }

            if (isset($definicion['max']) && $convertido > $definicion['max']) {
                continue;
            }

            $resultado[(int) ltrim($indice, '.')] = round($convertido, 2);
        }

        return $resultado;
    }

    /**
     * Borra el historial viejo.
     *
     * Sin esto la tabla crece sin freno: una OLT de 200 puertos
     * muestreada cada 5 minutos son 57.600 filas al día.
     */
    public function podarMetricasViejas(int $dias = 30): int
    {
        return OltPortMetric::where('measured_at', '<', now()->subDays($dias))->delete();
    }
}
