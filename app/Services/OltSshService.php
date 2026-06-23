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

            $output = $this->executeCommand($ssh, "display ont autofind all", $olt, self::SSH_LONG_TIMEOUT);
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
}
