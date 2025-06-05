<?php

namespace App\Services;

use App\Models\Olt;
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
}
