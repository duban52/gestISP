<?php

namespace App\Services;

use App\Models\Ont;
use App\Models\Olt;
use Illuminate\Support\Facades\Log;

class OltSnmpService
{
    private const OID_RX_POWER = '.1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4';

    /**
     * Actualiza la potencia de todas las ONTs de una OLT via SNMP walk.
     * Usado por el comando: php artisan onts:sync-power
     */
    public function syncRxPower(Olt $olt): int
    {
        $host      = $olt->ip_address . ':' . ($olt->snmp_port ?? 161);
        $community = $olt->read_snmp_comunity;
        $updated   = 0;

        if (!$community) {
            Log::warning("SNMP: OLT {$olt->name} sin community string.");
            return 0;
        }

        try {
            $result = @snmprealwalk($host, $community, self::OID_RX_POWER, 2000000, 5);

            if (empty($result)) {
                Log::warning("SNMP: Sin respuesta de {$olt->name}");
                return 0;
            }

            foreach ($result as $oid => $rawValue) {
                if (!preg_match('/\.(\d+)\.(\d+)$/', $oid, $m)) {
                    continue;
                }

                $ifIndex = (int) $m[1];
                $onuId   = (int) $m[2];
                $raw     = (int) preg_replace('/[^-\d]/', '', $rawValue);

                $ont = Ont::where('olt_id',   $olt->id)
                    ->where('if_index', $ifIndex)
                    ->where('onu_id',   $onuId)
                    ->first();

                if (!$ont) {
                    continue;
                }

                if ($raw === 2147483647 || $raw === 0) {
                    $ont->update(['status' => 0, 'rx_power' => null]);
                    $updated++;
                    continue;
                }

                $rxPower = round($raw / 100, 2);

                if ($rxPower < -40 || $rxPower > 5) {
                    continue;
                }

                $ont->update(['status' => 1, 'rx_power' => $rxPower]);
                $updated++;

                Log::debug('SNMP rx_power actualizado', [
                    'sn'       => $ont->sn,
                    'if_index' => $ifIndex,
                    'onu_id'   => $onuId,
                    'rx_power' => $rxPower,
                ]);
            }

        } catch (\Exception $e) {
            Log::error("SNMP error {$olt->name}: " . $e->getMessage());
        }

        return $updated;
    }

    /**
     * Actualiza la potencia de una ONT específica via snmpget directo.
     * Usado por el botón de refresco en la vista.
     */
    public function syncSingleOntPower(Olt $olt, Ont $ont): bool
    {
        $host      = $olt->ip_address . ':' . ($olt->snmp_port ?? 161);
        $community = $olt->read_snmp_comunity;

        if (!$community || !$ont->if_index) {
            Log::warning("SNMP: ONT {$ont->sn} sin if_index o community.");
            return false;
        }

        try {
            // Consulta directa al OID exacto — no hace walk completo
            $oid    = self::OID_RX_POWER . '.' . $ont->if_index . '.' . $ont->onu_id;
            $result = snmpget($host, $community, $oid, 2000000, 3);

            if (!$result) {
                return false;
            }

            $raw = (int) preg_replace('/[^-\d]/', '', $result);

            if ($raw === 2147483647 || $raw === 0) {
                $ont->update(['status' => 0, 'rx_power' => null]);
                return true;
            }

            $rxPower = round($raw / 100, 2);

            if ($rxPower < -40 || $rxPower > 5) {
                return false;
            }

            $ont->update(['status' => 1, 'rx_power' => $rxPower]);

            Log::debug('SNMP single sync', [
                'sn'       => $ont->sn,
                'rx_power' => $rxPower,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("SNMP single error {$ont->sn}: " . $e->getMessage());
            return false;
        }
    }
}
