<?php

namespace App\Services\Snmp;

use App\Models\Olt;
use Illuminate\Support\Facades\Log;
use SNMP;
use SNMPException;

/**
 * Cliente SNMP de bajo nivel para las OLTs.
 *
 * Usa la clase SNMP nativa de PHP con SNMPv2c, que es donde está
 * la ganancia de rendimiento frente al código anterior:
 *
 *  - Las funciones sueltas snmpget()/snmprealwalk() usan SNMPv1:
 *    recorren las tablas con un GETNEXT por cada fila, es decir un
 *    viaje de red por dato. Con cientos de ONTs eso son cientos de
 *    viajes.
 *
 *  - Con v2c, walk() usa GETBULK: la OLT devuelve decenas de filas
 *    por paquete (max_repetitions), reduciendo el recorrido de una
 *    tabla completa de cientos de viajes a unos pocos.
 *
 *  - get() acepta un ARRAY de OIDs y los resuelve en UNA sola
 *    petición. La ficha de una ONT (potencia, temperatura,
 *    voltaje, corriente, distancia, estado) se obtiene con una
 *    única consulta en vez de una por métrica.
 *
 * Todas las operaciones devuelven null/array vacío ante un fallo:
 * una OLT inaccesible nunca debe romper una pantalla.
 */
class SnmpClient
{
    /**
     * OIDs por petición. 60 cabe holgadamente en un paquete UDP y
     * es el tamaño que aceptan sin problema los equipos Huawei
     * probados.
     */
    private const BATCH_SIZE = 60;

    private ?SNMP $session = null;

    public function __construct(
        private readonly string $host,
        private readonly string $community,
        private readonly int $timeout,
        private readonly int $retries,
        private readonly int $maxRepetitions,
    ) {
    }

    /**
     * ¿Está disponible la extensión SNMP de PHP?
     *
     * Sin ella no se puede consultar ningún equipo. Se comprueba
     * explícitamente para dar un mensaje claro en lugar de un
     * error fatal "Class SNMP not found" en mitad de una pantalla.
     */
    public static function isAvailable(): bool
    {
        return class_exists('SNMP');
    }

    /**
     * Construye el cliente a partir de los datos de la OLT.
     * Devuelve null si falta la extensión SNMP o la OLT no tiene
     * community de lectura.
     */
    public static function forOlt(Olt $olt): ?self
    {
        if (!self::isAvailable()) {
            Log::error(
                'La extensión SNMP de PHP no está instalada: no se pueden consultar las OLTs. ' .
                'Instálela en el servidor (por ejemplo: apt install php8.3-snmp) y reinicie PHP-FPM.'
            );

            return null;
        }

        if (empty($olt->read_snmp_comunity)) {
            Log::warning("SNMP: la OLT {$olt->name} no tiene community de lectura configurada.");

            return null;
        }

        return new self(
            host: $olt->ip_address . ':' . ($olt->snmp_port ?: 161),
            community: $olt->read_snmp_comunity,
            timeout: (int) config('olt_snmp.timeout', 1000000),
            retries: (int) config('olt_snmp.retries', 2),
            maxRepetitions: (int) config('olt_snmp.max_repetitions', 40),
        );
    }

    /**
     * Abre (perezosamente) la sesión SNMP v2c.
     */
    private function session(): SNMP
    {
        if ($this->session === null) {
            $session = new SNMP(SNMP::VERSION_2C, $this->host, $this->community, $this->timeout, $this->retries);

            // Valores crudos, sin formatear ni traducir a nombres:
            // el parseo lo hace la capa de dominio
            $session->valueretrieval = SNMP_VALUE_PLAIN;
            $session->enum_print = false;
            $session->oid_output_format = SNMP_OID_OUTPUT_NUMERIC;
            $session->exceptions_enabled = SNMP::ERRNO_ANY;

            // Cuántos OIDs se agrupan por paquete en un get múltiple
            $session->max_oids = 60;

            $this->session = $session;
        }

        return $this->session;
    }

    /**
     * Consulta varios OIDs en UNA sola petición.
     *
     * @param array<string, string> $oids [clave => oid]
     * @return array<string, string|null> [clave => valor crudo]
     */
    public function getMany(array $oids): array
    {
        if (empty($oids)) {
            return [];
        }

        $out = [];

        // Se envían por lotes: un paquete SNMP no puede llevar un
        // número ilimitado de OIDs, y los equipos rechazan las
        // peticiones demasiado grandes.
        foreach (array_chunk($oids, self::BATCH_SIZE, true) as $chunk) {
            $out += $this->getChunk($chunk);
        }

        return $out;
    }

    /**
     * Resuelve un lote de OIDs en UNA petición.
     *
     * @param array<string, string> $oids
     * @return array<string, string|null>
     */
    private function getChunk(array $oids): array
    {
        $keys = array_keys($oids);
        $values = array_values($oids);

        try {
            // preserve_keys = true para poder emparejar por orden
            $result = $this->session()->get($values, true);
        } catch (SNMPException $e) {
            // Algunos equipos rechazan el lote completo si un solo
            // OID no existe: se reintenta uno por uno para quedarse
            // con los que sí responden
            return $this->getManyIndividually($oids);
        }

        if (!is_array($result)) {
            return [];
        }

        $out = [];
        $resultValues = array_values($result);

        foreach ($keys as $i => $key) {
            $out[$key] = $resultValues[$i] ?? null;
        }

        return $out;
    }

    /**
     * Reintento uno a uno cuando el lote falla (por ejemplo, si un
     * OID no existe en ese modelo de OLT).
     *
     * @param array<string, string> $oids
     * @return array<string, string|null>
     */
    private function getManyIndividually(array $oids): array
    {
        $out = [];

        foreach ($oids as $key => $oid) {
            $out[$key] = $this->get($oid);
        }

        return $out;
    }

    /**
     * Consulta un OID puntual. Devuelve null si no responde.
     */
    public function get(string $oid): ?string
    {
        try {
            $value = $this->session()->get($oid);

            return $value === false ? null : $value;
        } catch (SNMPException $e) {
            return null;
        }
    }

    /**
     * Recorre una tabla completa con GETBULK.
     *
     * @return array<string, string> [sufijo del OID => valor]
     */
    public function walk(string $oid): array
    {
        try {
            // suffix_as_key = true: la clave es lo que sigue al OID
            // base (ej. "4194304000.5"), que es justo el índice que
            // identifica a la ONT
            $result = $this->session()->walk($oid, true, $this->maxRepetitions);

            return is_array($result) ? $result : [];
        } catch (SNMPException $e) {
            Log::warning("SNMP walk falló en {$this->host} para {$oid}: " . $e->getMessage());

            return [];
        }
    }

    /**
     * Comprueba que la OLT responde por SNMP (sysUpTime).
     */
    public function isReachable(): bool
    {
        return $this->get('.1.3.6.1.2.1.1.3.0') !== null;
    }

    public function close(): void
    {
        if ($this->session !== null) {
            try {
                $this->session->close();
            } catch (SNMPException $e) {
                // Cerrar nunca debe propagar error
            }

            $this->session = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
