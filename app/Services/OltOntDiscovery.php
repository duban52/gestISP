<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Olt;
use App\Models\Ont;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Descubrimiento del inventario de ONTs de una OLT.
 *
 * Sirve para incorporar a GestISP las ONTs que ya estaban
 * funcionando en la OLT, ya sea porque el operador las configuró a
 * mano o porque venían de otro sistema (Smart OLT, AdminOLT...).
 *
 * Todo el inventario se obtiene con unos pocos recorridos SNMP
 * masivos: una OLT con 2.198 ONTs se lee completa en unos 20
 * segundos y con apenas cuatro consultas, sin abrir una sola
 * sesión SSH.
 */
class OltOntDiscovery
{
    public function __construct(
        private readonly OltSnmpService $snmp,
    ) {
    }

    /**
     * Lee de la OLT todas las ONTs registradas.
     *
     * @return Collection<int, array> ONTs encontradas con sus datos
     */
    public function discover(Olt $olt): Collection
    {
        $client = SnmpClient::forOlt($olt);

        if (!$client) {
            throw new \RuntimeException(
                'La OLT no tiene community SNMP de lectura configurada, o falta la extensión SNMP de PHP.'
            );
        }

        if (!$client->isReachable()) {
            $client->close();

            throw new \RuntimeException("La OLT {$olt->name} ({$olt->ip_address}) no responde por SNMP.");
        }

        $config = $this->inventoryConfig($olt);

        // Recorridos masivos: uno por dato, no uno por ONT
        $serials = $client->walk($config['serial']);
        $descriptions = $client->walk($config['description']);
        $states = $client->walk($this->runStatusOid($olt));
        $distances = $client->walk($this->distanceOid($olt));

        $client->close();

        if (empty($serials)) {
            throw new \RuntimeException(
                'La OLT respondió pero no devolvió ninguna ONT. Verifique los OIDs de inventario en config/olt_snmp.php con: php artisan olt:snmp-probe ' . $olt->id
            );
        }

        // Mapa ifIndex del puerto PON → slot/port, para saber dónde
        // está conectada cada ONT
        $ponPorts = $this->ponPortMap($olt);

        $emptyDescription = $config['empty_description'] ?? 'ONT_NO_DESCRIPTION';

        return collect($serials)
            ->map(function ($rawSerial, $index) use ($descriptions, $states, $distances, $ponPorts, $emptyDescription) {
                $index = ltrim($index, '.');
                [$ifIndex, $onuId] = array_pad(explode('.', $index), 2, null);

                if ($onuId === null) {
                    return null;
                }

                $port = $this->resolvePonPort((int) $ifIndex, $ponPorts);
                $description = trim((string) ($descriptions[$index] ?? $descriptions['.' . $index] ?? ''));

                if ($description === $emptyDescription) {
                    $description = '';
                }

                return [
                    'sn' => $this->decodeSerial($rawSerial),
                    'if_index' => (int) $ifIndex,
                    'onu_id' => (int) $onuId,
                    'slot' => $port['slot'] ?? null,
                    'port' => $port['port'] ?? null,
                    'description' => $description,
                    'online' => trim((string) ($states[$index] ?? $states['.' . $index] ?? '')) === '1',
                    'distance' => (int) ($distances[$index] ?? $distances['.' . $index] ?? 0),
                ];
            })
            ->filter()
            // Sin serial no se puede identificar el equipo
            ->filter(fn ($ont) => $ont['sn'] !== null)
            ->values();
    }

    /**
     * Convierte el serial binario de Huawei al formato legible.
     *
     * El equipo entrega 8 bytes: los 4 primeros son el fabricante
     * en ASCII y los 4 siguientes el número en hexadecimal.
     * 48575443DD5C64C6 → HWTC-DD5C64C6
     */
    public function decodeSerial(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Algunos agentes ya lo entregan como texto
        if (preg_match('/^[A-Z0-9]{4}-[0-9A-F]{8}$/i', trim($raw))) {
            return strtoupper(trim($raw));
        }

        $hex = bin2hex($raw);

        // Otros lo entregan como cadena hexadecimal
        if (!preg_match('/^[0-9a-f]{16}$/i', $hex) && preg_match('/^[0-9a-fA-F]{16}$/', trim($raw))) {
            $hex = strtolower(trim($raw));
        }

        if (strlen($hex) !== 16) {
            return null;
        }

        $vendor = @hex2bin(substr($hex, 0, 8));

        // El fabricante debe ser ASCII imprimible (HWTC, OEMT...)
        if (!$vendor || !preg_match('/^[A-Za-z0-9]{4}$/', $vendor)) {
            return strtoupper($hex);
        }

        return strtoupper($vendor . '-' . substr($hex, 8));
    }

    /**
     * Intenta identificar el contrato al que pertenece una ONT a
     * partir de su descripción en la OLT.
     *
     * Las descripciones suelen incluir el documento del cliente
     * ("BT000353 - 94280438 - JOSE ARGEMIRO MARIN"). Se extraen
     * los números largos y se buscan entre los clientes de la
     * sucursal; solo se acepta cuando hay UNA coincidencia, para
     * no asignar una ONT al cliente equivocado.
     */
    public function matchContract(string $description, int $branchId): ?int
    {
        if (trim($description) === '') {
            return null;
        }

        // Números de 5 a 15 dígitos: documentos de identidad
        preg_match_all('/\b\d{5,15}\b/', $description, $matches);

        foreach ($matches[0] as $candidate) {
            $clients = Client::where('branch_id', $branchId)
                ->where('identity_number', $candidate)
                ->with('contracts')
                ->get();

            if ($clients->count() !== 1) {
                continue;
            }

            $contracts = $clients->first()->contracts;

            // Si el cliente tiene un solo contrato, la asignación es
            // inequívoca; con varios se deja sin asignar para que lo
            // decida una persona
            if ($contracts->count() === 1) {
                return $contracts->first()->id;
            }
        }

        return null;
    }

    /**
     * Resumen de lo que traería una importación, sin escribir nada.
     *
     * @return array{total: int, nuevas: int, existentes: int, con_contrato: int, muestra: array}
     */
    public function preview(Olt $olt, int $limit = 15): array
    {
        $found = $this->discover($olt);

        $existing = Ont::where('olt_id', $olt->id)->pluck('sn')->map(fn ($s) => strtoupper($s))->flip();

        $nuevas = $found->reject(fn ($o) => $existing->has(strtoupper($o['sn'])));

        $muestra = $nuevas->take($limit)->map(function ($ont) use ($olt) {
            $ont['contract_id'] = $this->matchContract($ont['description'], $olt->branch_id);

            return $ont;
        })->values()->all();

        return [
            'total' => $found->count(),
            'nuevas' => $nuevas->count(),
            'existentes' => $found->count() - $nuevas->count(),
            'sin_ubicacion' => $found->whereNull('slot')->count(),
            'muestra' => $muestra,
        ];
    }

    /**
     * Resuelve el frame/slot/port de una ONT a partir del ifIndex de
     * su puerto PON.
     *
     * Primero usa el mapa de descripciones de interfaz (ifDescr), que
     * es la fuente más explícita. Cuando la OLT no expone ese ifDescr
     * —la MA5608T no lo publica por SNMP— se recurre a decodificar el
     * propio ifIndex, que en la plataforma Huawei SmartAX codifica la
     * posición del puerto de forma determinista.
     *
     * @param  array<int, array>  $map  Mapa ifIndex → posición (de ifDescr)
     * @return array{frame:int, slot:int, port:int}|null
     */
    private function resolvePonPort(int $ifIndex, array $map): ?array
    {
        return $map[$ifIndex] ?? $this->decodeGponIfIndex($ifIndex);
    }

    /**
     * Decodifica la posición (frame/slot/port) de un puerto GPON a
     * partir de su ifIndex de Huawei SmartAX.
     *
     * El ifIndex de un puerto GPON tiene la forma 0xFA_SSPP_00:
     *   - byte alto 0xFA  → tipo de puerto = GPON
     *   - bits 21-23      → frame
     *   - bits 13-20      → slot
     *   - bits  8-12      → port
     *
     * Verificado contra equipos reales (5800X17: 96/96, 5800X15:
     * 112/112 puertos coinciden con su ifDescr). Si el ifIndex no
     * corresponde a un puerto GPON se devuelve null en vez de inventar
     * una ubicación.
     *
     * @return array{frame:int, slot:int, port:int}|null
     */
    private function decodeGponIfIndex(int $ifIndex): ?array
    {
        // Solo se decodifican puertos GPON (byte de tipo 0xFA).
        if (($ifIndex >> 24) !== 0xFA) {
            return null;
        }

        return [
            'frame' => ($ifIndex >> 21) & 0x7,
            'slot' => ($ifIndex >> 13) & 0xFF,
            'port' => ($ifIndex >> 8) & 0x1F,
        ];
    }

    /**
     * Mapa ifIndex → slot/port de los puertos PON, leído del ifDescr.
     */
    private function ponPortMap(Olt $olt): array
    {
        $map = [];

        foreach ($this->snmp->interfaceDescriptions($olt) as $ifIndex => $descr) {
            if (preg_match('/GPON_UNI\s+(\d+)\/(\d+)\/(\d+)/', $descr, $m)) {
                $map[$ifIndex] = [
                    'frame' => (int) $m[1],
                    'slot' => (int) $m[2],
                    'port' => (int) $m[3],
                ];
            }
        }

        return $map;
    }

    private function inventoryConfig(Olt $olt): array
    {
        $brand = strtolower($olt->brand ?: 'huawei');
        $config = config("olt_snmp.brands.{$brand}") ?? config('olt_snmp.brands.huawei');

        $inventory = $config['inventory'] ?? [];

        // Sin el mapa de OIDs de inventario no se puede leer la OLT.
        // Pasa cuando el proceso quedó con una configuración vieja
        // en memoria: el worker de colas es de larga duración y
        // conserva la config con la que arrancó.
        if (empty($inventory['serial'])) {
            throw new \RuntimeException(
                'Falta el mapa de OIDs de inventario en config/olt_snmp.php para la marca "' . $brand . '". ' .
                'Si acaba de actualizar el sistema, reinicie el worker de colas: php artisan queue:restart'
            );
        }

        $inventory['empty_description'] = $config['empty_description'] ?? 'ONT_NO_DESCRIPTION';

        return $inventory;
    }

    private function runStatusOid(Olt $olt): string
    {
        return $this->snmp->metricDefinitions($olt)['run_status']['oid']
            ?? '.1.3.6.1.4.1.2011.6.128.1.1.2.46.1.15';
    }

    private function distanceOid(Olt $olt): string
    {
        return $this->snmp->metricDefinitions($olt)['distance']['oid']
            ?? '.1.3.6.1.4.1.2011.6.128.1.1.2.46.1.20';
    }
}
