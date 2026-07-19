<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Ont;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class OltSshService
{
    private const SSH_TIMEOUT = 2;
    private const SSH_LONG_TIMEOUT = 3;

    /**
     * Obtiene el estado de la OLT (temperatura, uptime, etc.)
     */
    public function getOltStatus(Olt $olt): array
    {
        $ssh = $this->connectToOlt($olt);

        $result = [
            'status' => 'Conectado',
            'temperature' => 'N/A',
            'uptime' => 'N/A',
        ];

        try {
            $this->enablePrivilegedMode($ssh);

            // Obtener uptime
            $uptimeRaw = $this->executeCommand($ssh, "display sysuptime", $olt);
            $result['uptime'] = $this->processUptime($uptimeRaw);

            // Obtener temperatura
            $temperatureRaw = $this->executeCommand($ssh, "display temperature 0/1", $olt);
            $result['temperature'] = $this->processTemperature($temperatureRaw);

        } finally {
            $ssh->disconnect();
        }

        return $result;
    }

    /**
     * Obtiene las ONTs en modo autofind
     */
    public function getAutoFindOnts(Olt $olt): array
    {
        $ssh = $this->connectToOlt($olt);

        try {
            $this->enablePrivilegedMode($ssh);

            $ssh->setTimeout(self::SSH_TIMEOUT);

            // Deshabilitar paginación en Huawei MA5800
            $ssh->write("undo terminal more\n");
            $ssh->read('#');

            // Ejecutar autofind con timeout largo (método que funciona)
            $output = $this->executeCommand($ssh, "display ont autofind all", $olt, 10);

            // Si la salida quedó paginada, seguir presionando espacio hasta el final
            $maxPages = 50;
            for ($i = 0; $i < $maxPages; $i++) {

                // ¿La salida termina con el resumen final o el prompt? → ya está completa
                if (str_contains($output, 'The number of GPON')) {
                    break;
                }

                // ¿Hay paginación pendiente? → enviar espacio y leer el siguiente bloque
                if (str_contains($output, '---- More')) {
                    $ssh->write(' ');
                    $ssh->setTimeout(10);
                    $output .= $ssh->read('#');
                    continue;
                }

                // No hay More ni resumen → no hay más que leer
                break;
            }

            Log::debug('AUTOFIND RAW', [
                'length' => strlen($output),
                'output' => $output,
            ]);

            return $this->processOntsAutofind($output);

        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Establece conexión SSH con la OLT
     */
    private function connectToOlt(Olt $olt): SSH2
    {
        $ssh = new SSH2($olt->ip_address, (int) $olt->ssh_port);

        if (!$ssh->login($olt->username, $olt->getPlainPassword())) {
            throw new \Exception("No se pudo autenticar con la OLT {$olt->name}");
        }

        return $ssh;
    }

    /**
     * Habilita el modo privilegiado en la OLT
     */
    private function enablePrivilegedMode(SSH2 $ssh): void
    {
        $ssh->setTimeout(self::SSH_TIMEOUT);
        $ssh->write("enable\n");
        $ssh->read('#');
    }

    /**
     * Ejecuta un comando en la OLT
     */
    private function executeCommand(SSH2 $ssh, string $command, Olt $olt, int $timeout = self::SSH_TIMEOUT): string
    {
        $ssh->setTimeout($timeout);

        // Verificar si el modelo requiere doble enter
        if ($this->requiresDoubleEnter($olt)) {
            $ssh->write($command . "\n\n");
        } else {
            $ssh->write($command . "\n");
        }

        return $ssh->read('#');
    }

    /**
     * Verifica si el modelo de OLT requiere doble enter después de los comandos
     */
    private function requiresDoubleEnter(Olt $olt): bool
    {
        return strtoupper($olt->model) === '5800X17';
    }

    /**
     * Procesa la salida del comando de uptime
     */
    private function processUptime(string $uptimeRaw): string
    {
        if (preg_match('/System up time:\s*(.+)/i', $uptimeRaw, $matches)) {
            return trim($matches[1]);
        }
        return 'N/A';
    }

    /**
     * Procesa la salida del comando de temperatura
     */
    private function processTemperature(string $temperatureRaw): string
    {
        if (preg_match('/temperature of the board:\s*(\d+)[C°]/i', $temperatureRaw, $matches)) {
            return $matches[1] . '°C';
        }
        return 'N/A';
    }

    /**
     * Procesa la salida del comando autofind para extraer ONTs
     */
    private function processOntsAutofind(string $output): array
    {
        $onts = [];
        $bloques = preg_split('/Number\s+:/', $output, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($bloques as $bloque) {
            $ont = $this->parseOntBlock($bloque);

            if (!empty($ont)) {
                $onts[] = $ont;
            }
        }

        return $onts;
    }

    /**
     * Parsea un bloque de información de ONT
     */
    private function parseOntBlock(string $bloque): array
    {
        $ont = [];

        $patterns = [
            'fspon' => '/F\/S\/P\s+:\s+([0-9\/]+)/',
            'ont_sn_data' => '/Ont SN\s+:\s+([0-9A-F]+)\s+\((.*?)\)/i',
            'equipment_id' => '/Ont EquipmentID\s+:\s+(.*?)\s*$/m',
            'vendor' => '/VendorID\s+:\s+(.*?)\s*$/m',
            'autofind_time' => '/Ont autofind time\s+:\s+(.*?)\s*$/m',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $bloque, $matches)) {
                switch ($key) {
                    case 'ont_sn_data':
                        $ont['ont_sn_hex'] = $matches[1];
                        $ont['ont_sn'] = $matches[2];
                        break;
                    case 'fspon':
                        $ont['fspon'] = $matches[1];
                        break;
                    case 'equipment_id':
                        $ont['equipment_id'] = trim($matches[1]);
                        break;
                    case 'vendor':
                        $ont['vendor'] = trim($matches[1]);
                        break;
                    case 'autofind_time':
                        $ont['autofind_time'] = trim($matches[1]);
                        break;
                }
            }
        }

        return $ont;
    }

    /**
     * Activa una ONT en la OLT via SSH y retorna el ont_id asignado
     */
    public function activateOnt(Olt $olt, array $data): array
    {
        $parts     = explode('/', $data['fspon']);
        $interface = $parts[0] . '/' . $parts[1];
        $port      = $parts[2];

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            $ssh->write("interface gpon {$interface}\n");
            $ssh->read('/[>#]/');

            // 1. Agregar ONT
            $ssh->write(
                "ont add {$port} sn-auth {$data['ont_sn']} omci " .
                "ont-lineprofile-id {$data['ont_lineprofile']} " .
                "ont-srvprofile-id {$data['ont_srvprofile']} " .
                "desc \"{$data['client_name']}\"\n"
            );
            $ssh->read('/\}:/');
            $ssh->write("\n");
            $ontAddOutput = $ssh->read('/[>#]/');

            Log::debug('ONT ADD OUTPUT', ['olt' => $olt->name, 'output' => $ontAddOutput]);

            $ontId = $this->parseOntId($ontAddOutput);
            if ($ontId === null) {
                throw new \Exception("No se pudo obtener el ONT-ID. Respuesta: {$ontAddOutput}");
            }

            // 2. Salir de la interfaz GPON
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            // 3. Crear service-port — definir el comando en variable
            $servicePortCmd = implode(' ', [
                'service-port',
                'vlan', $data['vlan'],
                'gpon', "{$interface}/{$port}",
                'ont', $ontId,
                'gemport', '1',
                'multi-service',
                'user-vlan', $data['vlan'],
                'tag-transform', 'translate',
            ]);

            $ssh->write($servicePortCmd . "\n\n");
            $ssh->read('/[>#]/');

            // 4. Consultar el INDEX del service-port recién creado
            $displayCmd = implode(' ', [
                'display service-port port',
                "{$interface}/{$port}",
                'ont', $ontId,
            ]);

            $ssh->write($displayCmd . "\n");
            $ssh->read('/\}:/');
            $ssh->write("\n");
            $servicePortOutput = $ssh->read('/[>#]/');

            Log::debug('SERVICE PORT DISPLAY OUTPUT', ['olt' => $olt->name, 'output' => $servicePortOutput]);

            $servicePortId = $this->parseServicePortId($servicePortOutput);

            // 5. Salir
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            return [
                'ont_id'       => $ontId,
                'service_port' => $servicePortId,
            ];

        } finally {
            $ssh->disconnect();
        }
    }
    private function parseOntId(string $output): ?int
    {
        $patterns = [
            '/ONTID\s*:\s*(\d+)/i',
            '/ont-id\s*:\s*(\d+)/i',
            '/ontid\s*=\s*(\d+)/i',
            '/Add\s+ONT\s+successfully.*?(\d+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Extrae el service-port ID de la respuesta
     * Huawei responde algo como: "Add service-port successfully, index: 5"
     */
    private function parseServicePortId(string $output): ?int
    {
        // Busca la primera línea de datos de la tabla
        // Formato:  "  2299  150 common   gpon ..."
        // El INDEX es el primer número en esa línea
        if (preg_match('/^\s+(\d+)\s+\d+\s+\w+\s+gpon/m', $output, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Elimina una ONT de la OLT via SSH
     */
    public function deleteOnt(Olt $olt, Ont $ont): void
    {
        $interface = "0/{$ont->slot}";
        $port      = $ont->port;

        Log::debug('DELETE ONT - Iniciando', [
            'olt'          => $olt->name,
            'interface'    => $interface,
            'port'         => $port,
            'onu_id'       => $ont->onu_id,
            'service_port' => $ont->service_port,
        ]);

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - enable', ['output' => $output]);

            $ssh->write("config\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - config', ['output' => $output]);

            // 1. Eliminar el service-port
            $ssh->write("undo service-port {$ont->service_port}\n");
            $output = $ssh->read('/\}:/');
            Log::debug('DELETE ONT - undo service-port (prompt)', ['output' => $output]);

            $ssh->write("y\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - undo service-port (confirmación)', ['output' => $output]);

            // 2. Entrar a la interfaz GPON
            $ssh->write("interface gpon {$interface}\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - interface gpon', ['output' => $output]);

            // 3. Eliminar la ONT
            $ssh->write("ont delete {$port} {$ont->onu_id}\n");
            $output = $ssh->read('/\}:/');
            Log::debug('DELETE ONT - ont delete (prompt)', ['output' => $output]);

            $ssh->write("y\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - ont delete (confirmación)', ['output' => $output]);

            // 4. Salir
            $ssh->write("quit\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - quit interfaz', ['output' => $output]);

            $ssh->write("quit\n");
            $output = $ssh->read('/[>#]/');
            Log::debug('DELETE ONT - quit config', ['output' => $output]);

            Log::debug('DELETE ONT - Finalizado correctamente', [
                'olt'    => $olt->name,
                'onu_id' => $ont->onu_id,
            ]);

        } finally {
            $ssh->disconnect();
        }
    }
    /**
     * Mueve una ONT de un puerto a otro en la misma OLT
     * 1. Elimina service-port y ONT del puerto viejo
     * 2. Activa la ONT en el puerto nuevo
     * Retorna array con ont_id y service_port nuevos
     */
    public function moveOnt(Olt $olt, Ont $ont, array $newData): array
    {
        $oldInterface = "0/{$ont->slot}";
        $oldPort      = $ont->port;

        $newParts     = explode('/', $newData['fspon']);
        $newInterface = $newParts[0] . '/' . $newParts[1];
        $newPort      = $newParts[2];

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            // 1. Eliminar service-port viejo
            $ssh->write("undo service-port {$ont->service_port}\n");
            $ssh->read('/\}:/');
            $ssh->write("y\n");
            $ssh->read('/[>#]/');

            Log::debug('MOVE ONT - service-port eliminado', [
                'service_port' => $ont->service_port,
            ]);

            // 2. Entrar al puerto viejo y eliminar la ONT
            $ssh->write("interface gpon {$oldInterface}\n");
            $ssh->read('/[>#]/');

            $ssh->write("ont delete {$oldPort} {$ont->onu_id}\n");
            $ssh->read('/\}:/');
            $ssh->write("y\n");
            $ssh->read('/[>#]/');

            Log::debug('MOVE ONT - ONT eliminada del puerto viejo', [
                'old_interface' => $oldInterface,
                'old_port'      => $oldPort,
                'onu_id'        => $ont->onu_id,
            ]);

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            // 3. Entrar al puerto nuevo y activar la ONT
            $ssh->write("interface gpon {$newInterface}\n");
            $ssh->read('/[>#]/');

            $ssh->write(
                "ont add {$newPort} sn-auth {$ont->sn} omci " .
                "ont-lineprofile-id {$newData['ont_lineprofile']} " .
                "ont-srvprofile-id {$newData['ont_srvprofile']} " .
                "desc \"{$ont->description}\"\n"
            );
            $ssh->read('/\}:/');
            $ssh->write("\n");
            $ontAddOutput = $ssh->read('/[>#]/');

            Log::debug('MOVE ONT - ont add output', ['output' => $ontAddOutput]);

            $newOntId = $this->parseOntId($ontAddOutput);

            if ($newOntId === null) {
                throw new \Exception("No se pudo obtener el nuevo ONT-ID. Respuesta: {$ontAddOutput}");
            }

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            // 4. Crear nuevo service-port
            $servicePortCmd = implode(' ', [
                'service-port',
                'vlan', $newData['vlan'],
                'gpon', "{$newInterface}/{$newPort}",
                'ont', $newOntId,
                'gemport', '1',
                'multi-service',
                'user-vlan', $newData['vlan'],
                'tag-transform', 'translate',
            ]);

            $ssh->write($servicePortCmd . "\n\n");
            $ssh->read('/[>#]/');

            // 5. Consultar el nuevo INDEX del service-port
            $displayCmd = implode(' ', [
                'display service-port port',
                "{$newInterface}/{$newPort}",
                'ont', $newOntId,
            ]);

            $ssh->write($displayCmd . "\n");
            $ssh->read('/\}:/');
            $ssh->write("\n");
            $servicePortOutput = $ssh->read('/[>#]/');

            Log::debug('MOVE ONT - service-port output', ['output' => $servicePortOutput]);

            $newServicePort = $this->parseServicePortId($servicePortOutput);

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            return [
                'ont_id'       => $newOntId,
                'service_port' => $newServicePort,
            ];

        } finally {
            $ssh->disconnect();
        }
    }
/**
 * Consulta el estado del puerto CATV de una ONT
 * Retorna 'on', 'off' o null si no se pudo determinar
 */
    public function getCatvPortState(Olt $olt, Ont $ont): ?string
    {
        $interface = "0/{$ont->slot}";

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            $ssh->write("interface gpon {$interface}\n");
            $ssh->read('/[>#]/');

            $output = $this->executeDisplayCommand(
                $ssh,
                "display ont port state {$ont->port} {$ont->onu_id} catv-port 1"
            );

            Log::debug('CATV PORT STATE OUTPUT', ['output' => $output]);

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            return $this->parseCatvState($output);

        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Parsea el estado del puerto CATV
     * Formato real de la tabla:
     *   ONT-ID   ONT      ONT       LinkState  TxPower
     *            port-ID  Port-type            (dBmV)
     *   --------------------------------------------------
     *       22         1       CATV up         -
     */
    private function parseCatvState(string $output): ?string
    {
        // La fila de datos: número(s), número, CATV, up/down
        if (preg_match('/\d+\s+\d+\s+CATV\s+(up|down)/i', $output, $m)) {
            return strtolower($m[1]) === 'up' ? 'on' : 'off';
        }

        // Fallback para firmwares que usan "Operational state : on/off"
        if (preg_match('/Operational state\s*:\s*(on|off)/i', $output, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }
    /**
     * Ejecuta un comando display y lee toda la salida manejando la paginación "---- More ----"
     */
    private function executeDisplayCommand(SSH2 $ssh, string $command, bool $confirmPrompt = true): string
    {
        $ssh->setTimeout(self::SSH_LONG_TIMEOUT);
        $ssh->write($command . "\n");

        if ($confirmPrompt) {
            // Comandos display de Huawei muestran el prompt { <cr>||<K> }:
            $ssh->read('/\}:/');
            $ssh->write("\n");
        }

        $output   = '';
        $maxPages = 100;

        for ($i = 0; $i < $maxPages; $i++) {
            $ssh->setTimeout(10);
            $chunk  = $ssh->read('/[>#]|---- More/');
            $output .= $chunk;

            // Verificar solo el final de lo acumulado
            $tail = substr($output, -100);

            if (str_contains($tail, '---- More')) {
                $ssh->write(' ');
                continue;
            }

            // Llegó el prompt → salida completa
            break;
        }

        return $output;
    }
    public function getOntOpticalInfo(Olt $olt, Ont $ont): array
    {
        $interface = "0/{$ont->slot}";

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            $ssh->write("interface gpon {$interface}\n");
            $ssh->read('/[>#]/');

            // Info óptica — salida corta, sin paginación normalmente
            $opticalOutput = $this->executeDisplayCommand($ssh, "display ont optical-info {$ont->port} {$ont->onu_id}");

            Log::debug('ONT OPTICAL INFO', ['length' => strlen($opticalOutput)]);

            // Info general — salida larga, con paginación
            $infoOutput = $this->executeDisplayCommand($ssh, "display ont info {$ont->port} {$ont->onu_id}");

            Log::debug('ONT INFO', ['length' => strlen($infoOutput)]);

            $optical = $this->parseOpticalInfo($opticalOutput);
            $info    = $this->parseOntInfo($infoOutput);

            // Estado del puerto CATV — solo si la ONT tiene módulo CATV
            $catvState = null;
            if ($optical['has_catv']) {
                $catvOutput = $this->executeDisplayCommand(
                    $ssh,
                    "display ont port state {$ont->port} {$ont->onu_id} catv-port 1"
                );

                Log::debug('CATV PORT STATE OUTPUT', ['output' => $catvOutput]);

                $catvState = $this->parseCatvState($catvOutput);
            }

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

            return array_merge($optical, $info, ['catv_state' => $catvState]);

        } finally {
            $ssh->disconnect();
        }
    }

    private function parseOpticalInfo(string $output): array
    {
        $data = [
            'rx_power'      => null,
            'tx_power'      => null,
            'olt_rx_power'  => null,
            'temperature'   => null,
            'voltage'       => null,
            'current'       => null,
            'catv_rx_power' => null,
            'has_catv'      => false,
        ];

        $patterns = [
            'rx_power'      => '/Rx optical power\(dBm\)\s+:\s+(-?[\d.]+)/i',
            'tx_power'      => '/Tx optical power\(dBm\)\s+:\s+(-?[\d.]+)/i',
            'olt_rx_power'  => '/OLT Rx ONT optical power\(dBm\)\s+:\s+(-?[\d.]+)/i',
            'temperature'   => '/Temperature\(C\)\s+:\s+(-?[\d.]+)/i',
            'voltage'       => '/Voltage\(V\)\s+:\s+([\d.]+)/i',
            'current'       => '/Laser bias current\(mA\)\s+:\s+([\d.]+)/i',
            'catv_rx_power' => '/CATV Rx optical power\(dBm\)\s+:\s+(-?[\d.]+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $output, $m)) {
                $data[$key] = (float) $m[1];
            }
        }

        // -40.00 en CATV significa sin señal / módulo CATV presente pero apagado
        // Si el campo apareció en la salida, la ONT tiene CATV
        $data['has_catv'] = str_contains($output, 'CATV Rx optical power');

        return $data;
    }

    private function parseOntInfo(string $output): array
    {
        $data = [
            'run_state'       => null,
            'config_state'    => null,
            'match_state'     => null,
            'distance'        => null,
            'last_down_time'  => null,
            'last_up_time'    => null,
            'last_down_cause' => null,
            'online_duration' => null,
            'battery_state'   => null,
            'line_profile'    => null,
            'srv_profile'     => null,
        ];

        $patterns = [
            'run_state'       => '/Run state\s+:\s+(\S+)/i',
            'config_state'    => '/Config state\s+:\s+(\S+)/i',
            'match_state'     => '/Match state\s+:\s+(\S+)/i',
            'distance'        => '/ONT distance\(m\)\s+:\s+(\d+)/i',
            'last_down_time'  => '/Last down time\s+:\s+(.+?)\s*$/mi',
            'last_up_time'    => '/Last up time\s+:\s+(.+?)\s*$/mi',
            'last_down_cause' => '/Last down cause\s+:\s+(.+?)\s*$/mi',
            'online_duration' => '/ONT online duration\s+:\s+(.+?)\s*$/mi',
            'battery_state'   => '/ONT battery state\s+:\s+(.+?)\s*$/mi',
            'line_profile'    => '/Line profile name\s+:\s+(\S+)/i',
            'srv_profile'     => '/Service profile name\s+:\s+(\S+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $output, $m)) {
                $data[$key] = trim($m[1]);
            }
        }

        return $data;
    }

    /**
     * Habilita o deshabilita el puerto CATV de una ONT
     */
    public function setCatvPort(Olt $olt, Ont $ont, bool $enable): void
    {
        $interface = "0/{$ont->slot}";
        $state     = $enable ? 'on' : 'off';

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            $ssh->write("interface gpon {$interface}\n");
            $ssh->read('/[>#]/');

            // Cambiar estado del puerto CATV 1
            $ssh->write("ont port attribute {$ont->port} {$ont->onu_id} catv 1 operational-state {$state}\n");
            $output = $ssh->read('/[>#]/');

            Log::debug('CATV PORT STATE', [
                'sn'     => $ont->sn,
                'state'  => $state,
                'output' => $output,
            ]);

            if (str_contains($output, 'Failure') || str_contains($output, 'error')) {
                throw new \Exception("La OLT rechazó el cambio de estado CATV: {$output}");
            }

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Habilita o deshabilita la ONT completa en la OLT.
     *
     * Deshabilitarla corta el servicio del cliente sin borrar su
     * configuración: la ONT queda registrada y se puede volver a
     * habilitar en cualquier momento (a diferencia de eliminarla,
     * que obliga a reautorizarla desde cero).
     *
     * Es una operación de escritura, por eso va por CLI: SNMP en
     * modo lectura no puede cambiar el estado del equipo.
     */
    public function setOntAdminState(Olt $olt, Ont $ont, bool $enable): void
    {
        $interface = "0/{$ont->slot}";
        $command = $enable ? 'activate' : 'deactivate';

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $ssh->write("enable\n");
            $ssh->read('/[>#]/');

            $ssh->write("config\n");
            $ssh->read('/[>#]/');

            $ssh->write("interface gpon {$interface}\n");
            $ssh->read('/[>#]/');

            $ssh->write("ont {$command} {$ont->port} {$ont->onu_id}\n");
            $output = $ssh->read('/[>#]/');

            // Algunas versiones piden confirmación al desactivar
            if (str_contains($output, '(y/n)')) {
                $ssh->write("y\n");
                $output .= $ssh->read('/[>#]/');
            }

            Log::debug('ONT ADMIN STATE', [
                'sn' => $ont->sn,
                'command' => $command,
                'output' => $output,
            ]);

            if (str_contains($output, 'Failure') || stripos($output, 'error') !== false) {
                throw new \Exception("La OLT rechazó el cambio de estado de la ONT: {$output}");
            }

            $ssh->write("quit\n");
            $ssh->read('/[>#]/');
            $ssh->write("quit\n");
            $ssh->read('/[>#]/');

        } finally {
            $ssh->disconnect();
        }
    }
}
