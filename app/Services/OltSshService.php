<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\Ont;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;

class OltSshService
{
    private const SSH_TIMEOUT = 6;
    private const SSH_LONG_TIMEOUT = 10;

    /**
     * Diálogo adaptativo con la consola de la OLT.
     *
     * En vez de suponer cómo va a responder cada modelo (algunos
     * piden un Enter extra tras ciertos comandos, otros no), este
     * lector REACCIONA a lo que la OLT realmente envía y acumula
     * toda la salida hasta llegar al prompt. Reconoce cuatro señales:
     *
     *   - Prompt final  (…#  o  …>)  → el comando terminó.
     *   - "{ <cr>||<K> }:"           → la OLT espera un Enter para
     *                                   continuar (típico de los MA5800);
     *                                   se responde con "\n".
     *   - "(y/n)"                    → confirmación destructiva; se
     *                                   responde "y" o "n" según se pida.
     *   - "---- More"                → paginación; se responde con
     *                                   un espacio para avanzar.
     *
     * Así funciona igual en la MA5608T, la MA5800 (5800X15/X17) y
     * cualquier otra, sin condicionar el comportamiento al modelo.
     *
     * @param  bool  $confirmYes  Responder "y" ante un prompt (y/n)
     *                            (para operaciones que borran).
     */
    private function converse(SSH2 $ssh, string $command, bool $confirmYes = false, ?int $timeout = null): string
    {
        $timeout ??= self::SSH_TIMEOUT;

        // Se descarta cualquier resto de la respuesta anterior antes
        // de enviar: algunos modelos (MA5800) hacen eco con retardo,
        // y sin limpiar el búfer la lectura siguiente devolvería la
        // salida del comando previo, corriendo todo una posición.
        $this->drain($ssh);

        $ssh->setTimeout($timeout);
        $ssh->write($command . "\n");

        return $this->readUntilPrompt($ssh, $confirmYes, $timeout);
    }

    /**
     * Vacía lo que quede pendiente de leer, para sincronizar.
     *
     * Se lee con un patrón imposible y un tiempo corto: read() vuelve
     * al agotarse ese tiempo devolviendo lo acumulado; cuando ya no
     * llega nada, el búfer está limpio.
     */
    private function drain(SSH2 $ssh, float $timeout = 0.3): void
    {
        $ssh->setTimeout($timeout);

        // Tope por seguridad: nunca más de ~2 s drenando.
        for ($i = 0; $i < 6; $i++) {
            if ((string) $ssh->read('/\bIMPOSSIBLE_MATCH_TOKEN\b/', SSH2::READ_REGEX) === '') {
                break;
            }
        }
    }

    /**
     * Lee la salida de la OLT resolviendo prompts intermedios hasta
     * llegar al prompt final. Ver converse() para el detalle de las
     * señales que maneja.
     */
    private function readUntilPrompt(SSH2 $ssh, bool $confirmYes = false, ?int $timeout = null): string
    {
        $timeout ??= self::SSH_TIMEOUT;

        // Terminadores posibles, en un solo patrón: paginación,
        // prompt de continuación "}:", confirmación "(y/n)" o el
        // prompt final del equipo (…# / …>).
        // El prompt final se ancla a un salto de línea previo para no
        // confundirlo con un ">" o "#" que aparezca dentro del texto
        // (por ejemplo, en el banner de bienvenida).
        $terminadores = '/(?:-{2,}\s*More)'
            . '|(?:\}\s*:\s*)'
            . '|(?:\(y\/n\)[^\r\n]*)'
            . '|(?:[\r\n][\w.\-]+(?:\([^)]*\))?[>#]\s*)$/i';

        $salida = '';

        // Tope de vueltas: evita un bucle infinito si la OLT quedara
        // en un estado inesperado. 200 cubre de sobra la paginación
        // más larga (autofind con cientos de ONTs).
        for ($i = 0; $i < 200; $i++) {
            $ssh->setTimeout($timeout);
            $chunk = (string) $ssh->read($terminadores, SSH2::READ_REGEX);
            $salida .= $chunk;

            // Se decide según el terminador que está JUSTO al final
            // de lo acumulado (anclado con $). Comprobar "en cualquier
            // parte" enviaría respuestas de más cuando una señal vieja
            // (por ejemplo un "}:" ya atendido) sigue presente en una
            // salida corta.
            $tail = substr($salida, -160);

            if (preg_match('/-{2,}\s*More[^\r\n]*$/i', $tail)) {
                $ssh->write(' ');
                continue;
            }

            if (preg_match('/\(y\/n\)[^\r\n]*$/i', $tail)) {
                $ssh->write(($confirmYes ? 'y' : 'n') . "\n");
                continue;
            }

            // "}:" al final pide un Enter para continuar/ver la salida.
            if (preg_match('/\}\s*:\s*$/', $tail)) {
                $ssh->write("\n");
                continue;
            }

            // Prompt final → el comando terminó.
            if (preg_match('/[>#]\s*$/', $tail)) {
                break;
            }

            // read() volvió por timeout sin terminador reconocible:
            // no hay más que leer, se corta con lo acumulado.
            if ($chunk === '') {
                break;
            }
        }

        return $salida;
    }

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

            // Se intenta quitar la paginación; si el modelo la ignora,
            // el propio lector la maneja con "---- More".
            $this->converse($ssh, 'undo terminal more');

            // Autofind con tiempo amplio: el lector recorre toda la
            // paginación y devuelve la salida completa.
            $output = $this->converse($ssh, 'display ont autofind all', false, self::SSH_LONG_TIMEOUT);

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

        // Se consume el banner de bienvenida completo (puede ser
        // largo y traer avisos), para arrancar el diálogo desde un
        // búfer limpio.
        $this->drain($ssh, 1.5);

        return $ssh;
    }

    /**
     * Habilita el modo privilegiado en la OLT
     */
    private function enablePrivilegedMode(SSH2 $ssh): void
    {
        $this->converse($ssh, 'enable');
    }

    /**
     * Ejecuta un comando en la OLT y devuelve su salida completa.
     *
     * Delega en el diálogo adaptativo, que funciona con cualquier
     * modelo (ya no depende de suposiciones por modelo ni de un
     * "doble enter" cableado).
     */
    private function executeCommand(SSH2 $ssh, string $command, Olt $olt, int $timeout = self::SSH_TIMEOUT): string
    {
        return $this->converse($ssh, $command, false, $timeout);
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
            $this->converse($ssh, 'enable', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, 'config', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, "interface gpon {$interface}", false, self::SSH_LONG_TIMEOUT);

            // 1. Agregar la ONT. El lector maneja solo el prompt
            // "{ <cr> }:" que algunos modelos muestran (y que otros
            // no), así que el mismo código sirve para todos.
            $ontAddOutput = $this->converse($ssh, implode(' ', [
                "ont add {$port} sn-auth {$data['ont_sn']} omci",
                "ont-lineprofile-id {$data['ont_lineprofile']}",
                "ont-srvprofile-id {$data['ont_srvprofile']}",
                "desc \"{$data['client_name']}\"",
            ]), false, self::SSH_LONG_TIMEOUT);

            Log::debug('ONT ADD OUTPUT', ['olt' => $olt->name, 'output' => $ontAddOutput]);

            $ontId = $this->parseOntId($ontAddOutput);
            if ($ontId === null) {
                throw new \Exception("No se pudo obtener el ONT-ID. Respuesta: {$ontAddOutput}");
            }

            // 2. Salir de la interfaz GPON
            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

            // 3. Crear el service-port
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

            $this->converse($ssh, $servicePortCmd, false, self::SSH_LONG_TIMEOUT);

            // 4. Consultar el INDEX del service-port recién creado
            $servicePortOutput = $this->converse($ssh, implode(' ', [
                'display service-port port',
                "{$interface}/{$port}",
                'ont', $ontId,
            ]), false, self::SSH_LONG_TIMEOUT);

            Log::debug('SERVICE PORT DISPLAY OUTPUT', ['olt' => $olt->name, 'output' => $servicePortOutput]);

            $servicePortId = $this->parseServicePortId($servicePortOutput);

            // 5. Salir
            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

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
     * Consulta a la OLT el service-port de una ONT ya existente.
     *
     * Las ONTs importadas desde la OLT llegan sin este dato: no se
     * expone por SNMP y solo se obtiene por consola. Se resuelve
     * bajo demanda, justo antes de las operaciones que lo
     * necesitan (eliminar y mover), y queda guardado para no
     * repetir la consulta.
     */
    public function resolveServicePort(Olt $olt, Ont $ont): ?int
    {
        $interface = "0/{$ont->slot}";

        $ssh = $this->connectToOlt($olt);

        try {
            $this->converse($ssh, 'enable', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, 'config', false, self::SSH_LONG_TIMEOUT);

            $output = $this->converse($ssh, implode(' ', [
                'display service-port port',
                "{$interface}/{$ont->port}",
                'ont', $ont->onu_id,
            ]), false, self::SSH_LONG_TIMEOUT);

            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

            $servicePort = $this->parseServicePortId($output);

            Log::debug('RESOLVE SERVICE PORT', [
                'sn' => $ont->sn,
                'service_port' => $servicePort,
            ]);

            return $servicePort;

        } finally {
            $ssh->disconnect();
        }
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
            $this->converse($ssh, 'enable', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, 'config', false, self::SSH_LONG_TIMEOUT);

            // 1. Eliminar el service-port (pide confirmación → "y").
            // El lector detecta el "(y/n)" en cualquier modelo y
            // responde solo.
            $out = $this->converse($ssh, "undo service-port {$ont->service_port}", true, self::SSH_LONG_TIMEOUT);
            Log::debug('DELETE ONT - undo service-port', ['output' => $out]);

            // 2. Entrar a la interfaz GPON
            $this->converse($ssh, "interface gpon {$interface}", false, self::SSH_LONG_TIMEOUT);

            // 3. Eliminar la ONT (también confirma con "y")
            $out = $this->converse($ssh, "ont delete {$port} {$ont->onu_id}", true, self::SSH_LONG_TIMEOUT);
            Log::debug('DELETE ONT - ont delete', ['output' => $out]);

            // 4. Salir
            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

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
            $this->converse($ssh, 'enable', false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, 'config', false, self::SSH_LONG_TIMEOUT);

            // 1. Eliminar service-port viejo (confirma con "y")
            $this->converse($ssh, "undo service-port {$ont->service_port}", true, self::SSH_LONG_TIMEOUT);

            Log::debug('MOVE ONT - service-port eliminado', [
                'service_port' => $ont->service_port,
            ]);

            // 2. Entrar al puerto viejo y eliminar la ONT
            $this->converse($ssh, "interface gpon {$oldInterface}", false, self::SSH_LONG_TIMEOUT);
            $this->converse($ssh, "ont delete {$oldPort} {$ont->onu_id}", true, self::SSH_LONG_TIMEOUT);

            Log::debug('MOVE ONT - ONT eliminada del puerto viejo', [
                'old_interface' => $oldInterface,
                'old_port'      => $oldPort,
                'onu_id'        => $ont->onu_id,
            ]);

            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

            // 3. Entrar al puerto nuevo y activar la ONT
            $this->converse($ssh, "interface gpon {$newInterface}", false, self::SSH_LONG_TIMEOUT);

            $ontAddOutput = $this->converse($ssh, implode(' ', [
                "ont add {$newPort} sn-auth {$ont->sn} omci",
                "ont-lineprofile-id {$newData['ont_lineprofile']}",
                "ont-srvprofile-id {$newData['ont_srvprofile']}",
                "desc \"{$ont->description}\"",
            ]), false, self::SSH_LONG_TIMEOUT);

            Log::debug('MOVE ONT - ont add output', ['output' => $ontAddOutput]);

            $newOntId = $this->parseOntId($ontAddOutput);

            if ($newOntId === null) {
                throw new \Exception("No se pudo obtener el nuevo ONT-ID. Respuesta: {$ontAddOutput}");
            }

            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

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

            $this->converse($ssh, $servicePortCmd, false, self::SSH_LONG_TIMEOUT);

            // 5. Consultar el nuevo INDEX del service-port
            $servicePortOutput = $this->converse($ssh, implode(' ', [
                'display service-port port',
                "{$newInterface}/{$newPort}",
                'ont', $newOntId,
            ]), false, self::SSH_LONG_TIMEOUT);

            Log::debug('MOVE ONT - service-port output', ['output' => $servicePortOutput]);

            $newServicePort = $this->parseServicePortId($servicePortOutput);

            $this->converse($ssh, 'quit', false, self::SSH_LONG_TIMEOUT);

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

            $this->converse($ssh, 'enable');
            $this->converse($ssh, 'config');
            $this->converse($ssh, "interface gpon {$interface}");

            $output = $this->executeDisplayCommand(
                $ssh,
                "display ont port state {$ont->port} {$ont->onu_id} catv-port 1"
            );

            Log::debug('CATV PORT STATE OUTPUT', ['output' => $output]);

            $this->converse($ssh, 'quit');
            $this->converse($ssh, 'quit');

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
     * Ejecuta un comando "display" y devuelve toda su salida.
     *
     * Delega en el diálogo adaptativo, que ya resuelve el prompt de
     * continuación "{ <cr> }:" y la paginación "---- More" de forma
     * transparente para cualquier modelo. Se conserva la firma por
     * compatibilidad con los llamadores.
     */
    private function executeDisplayCommand(SSH2 $ssh, string $command, bool $confirmPrompt = true): string
    {
        return $this->converse($ssh, $command, false, self::SSH_LONG_TIMEOUT);
    }
    public function getOntOpticalInfo(Olt $olt, Ont $ont): array
    {
        $interface = "0/{$ont->slot}";

        $ssh = $this->connectToOlt($olt);

        try {
            $ssh->setTimeout(self::SSH_LONG_TIMEOUT);

            $this->converse($ssh, 'enable');
            $this->converse($ssh, 'config');
            $this->converse($ssh, "interface gpon {$interface}");

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

            $this->converse($ssh, 'quit');
            $this->converse($ssh, 'quit');

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

            $this->converse($ssh, 'enable');
            $this->converse($ssh, 'config');
            $this->converse($ssh, "interface gpon {$interface}");

            // Cambiar estado del puerto CATV 1
            $output = $this->converse(
                $ssh,
                "ont port attribute {$ont->port} {$ont->onu_id} catv 1 operational-state {$state}",
                true
            );

            Log::debug('CATV PORT STATE', [
                'sn'     => $ont->sn,
                'state'  => $state,
                'output' => $output,
            ]);

            if (str_contains($output, 'Failure') || str_contains($output, 'error')) {
                throw new \Exception("La OLT rechazó el cambio de estado CATV: {$output}");
            }

            $this->converse($ssh, 'quit');
            $this->converse($ssh, 'quit');

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
            $this->converse($ssh, 'enable');
            $this->converse($ssh, 'config');
            $this->converse($ssh, "interface gpon {$interface}");

            // El lector maneja solo la confirmación "(y/n)" que
            // algunas versiones piden al desactivar.
            $output = $this->converse($ssh, "ont {$command} {$ont->port} {$ont->onu_id}", true);

            Log::debug('ONT ADMIN STATE', [
                'sn' => $ont->sn,
                'command' => $command,
                'output' => $output,
            ]);

            if (str_contains($output, 'Failure') || stripos($output, 'error') !== false) {
                throw new \Exception("La OLT rechazó el cambio de estado de la ONT: {$output}");
            }

            $this->converse($ssh, 'quit');
            $this->converse($ssh, 'quit');

        } finally {
            $ssh->disconnect();
        }
    }
}
