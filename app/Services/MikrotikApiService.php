<?php

namespace App\Services;

use App\Models\Router;
use App\Models\PppoeAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;

class MikrotikApiService
{
    /**
     * Cliente HTTP con autenticación básica hacia la REST API
     */
    private function http(Router $router): PendingRequest
    {
        return Http::withBasicAuth($router->username, $router->getPlainPassword())
            ->timeout(60)
            ->acceptJson();
    }

    /**
     * Construye la URL base de la REST API
     */
    private function baseUrl(Router $router): string
    {
        $port = $router->api_port ?: 80;
        return "http://{$router->ip_address}:{$port}/rest";
    }

    /**
     * Lanza excepción legible si la respuesta falló
     */
    private function checkResponse($response, string $action): void
    {
        if ($response->failed()) {
            $detail = $response->json('detail') ?? substr($response->body(), 0, 200);
            throw new \Exception("Mikrotik REST ({$action}): HTTP {$response->status()} - {$detail}");
        }
    }

    /**
     * Decodifica el JSON de la respuesta tolerando caracteres UTF-8 inválidos
     * (comentarios con ñ/tildes guardados con codificación antigua desde Winbox)
     */
    private function safeJson($response): array
    {
        $decoded = json_decode(
            $response->body(),
            true,
            512,
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Respuesta del router no es JSON válido: ' . json_last_error_msg());
        }

        return $decoded ?? [];
    }

    /**
     * Obtiene información del sistema (identity, version, uptime)
     */
    public function getSystemInfo(Router $router): array
    {
        $base = $this->baseUrl($router);

        $resource = $this->http($router)->get("{$base}/system/resource");
        $this->checkResponse($resource, 'system/resource');

        $identity = $this->http($router)->get("{$base}/system/identity");
        $this->checkResponse($identity, 'system/identity');

        $res = $this->safeJson($resource);
        $ide = $this->safeJson($identity);

        return [
            'status'      => true,
            'identity'    => $ide['name'] ?? 'N/A',
            'version'     => $res['version'] ?? 'N/A',
            'board_name'  => $res['board-name'] ?? 'N/A',
            'uptime'      => $res['uptime'] ?? 'N/A',
            'cpu_load'    => $res['cpu-load'] ?? 'N/A',
            'free_memory' => $res['free-memory'] ?? 'N/A',
        ];
    }

    /**
     * Lista los perfiles PPP disponibles en el router
     */
    public function getPppProfiles(Router $router): array
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->get("{$base}/ppp/profile");
        $this->checkResponse($response, 'ppp/profile');

        return array_map(fn($p) => [
            'id'             => $p['.id'],
            'name'           => $p['name'],
            'local_address'  => $p['local-address'] ?? null,
            'remote_address' => $p['remote-address'] ?? null,
            'rate_limit'     => $p['rate-limit'] ?? null,
        ], $this->safeJson($response));
    }

    /**
     * Lista los secrets (cuentas pppoe) del router
     */
    public function getPppSecrets(Router $router): array
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->get("{$base}/ppp/secret");
        $this->checkResponse($response, 'ppp/secret');

        return array_map(fn($s) => [
            'mikrotik_id'    => $s['.id'],
            'username'       => $s['name'],
            'password'       => $s['password'] ?? null,
            'profile'        => $s['profile'] ?? 'default',
            'service'        => $s['service'] ?? 'any',
            'remote_address' => $s['remote-address'] ?? null,
            'disabled'       => ($s['disabled'] ?? 'false') === 'true',
            'comment'        => $s['comment'] ?? null,
        ], $this->safeJson($response));
    }

    /**
     * Crea una cuenta pppoe (secret) en el router
     * Retorna el .id de mikrotik
     */
    public function createPppSecret(Router $router, array $data): string
    {
        $base = $this->baseUrl($router);

        $payload = [
            'name'     => $data['username'],
            'password' => $data['password'],
            'service'  => $data['service'] ?? 'pppoe',
            'profile'  => $data['profile'],
        ];

        if (!empty($data['remote_address'])) {
            $payload['remote-address'] = $data['remote_address'];
        }

        if (!empty($data['comment'])) {
            $payload['comment'] = $data['comment'];
        }

        $response = $this->http($router)->put("{$base}/ppp/secret", $payload);
        $this->checkResponse($response, 'crear secret');

        $created = $this->safeJson($response);

        Log::debug('MIKROTIK REST CREATE SECRET', [
            'router'   => $router->name,
            'username' => $data['username'],
            'response' => $created,
        ]);

        if (isset($created['.id'])) {
            return $created['.id'];
        }

        throw new \Exception('El router no retornó el ID del secret creado: ' . json_encode($created));
    }

    /**
     * Actualiza una cuenta pppoe existente
     */
    public function updatePppSecret(Router $router, PppoeAccount $account, array $data): void
    {
        $base = $this->baseUrl($router);

        $payload = [
            'name'    => $data['username'],
            'profile' => $data['profile'],
        ];

        if (!empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        if (isset($data['remote_address'])) {
            $payload['remote-address'] = $data['remote_address'] ?? '';
        }

        if (isset($data['comment'])) {
            $payload['comment'] = $data['comment'] ?? '';
        }

        $response = $this->http($router)->patch(
            "{$base}/ppp/secret/{$account->mikrotik_id}",
            $payload
        );
        $this->checkResponse($response, 'actualizar secret');

        Log::debug('MIKROTIK REST UPDATE SECRET', [
            'router'      => $router->name,
            'mikrotik_id' => $account->mikrotik_id,
            'username'    => $data['username'],
        ]);
    }

    /**
     * Habilita o deshabilita una cuenta pppoe
     */
    public function setPppSecretState(Router $router, PppoeAccount $account, bool $disabled): void
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->patch(
            "{$base}/ppp/secret/{$account->mikrotik_id}",
            ['disabled' => $disabled ? 'true' : 'false']
        );
        $this->checkResponse($response, 'cambiar estado secret');

        // Si se deshabilita, tumbar la sesión activa para corte inmediato
        if ($disabled) {
            $this->dropActiveSession($router, $account->username);
        }

        Log::debug('MIKROTIK REST SECRET STATE', [
            'router'   => $router->name,
            'username' => $account->username,
            'disabled' => $disabled,
        ]);
    }

    /**
     * Elimina una cuenta pppoe del router
     */
    public function deletePppSecret(Router $router, PppoeAccount $account): void
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->delete("{$base}/ppp/secret/{$account->mikrotik_id}");
        $this->checkResponse($response, 'eliminar secret');

        // Tumbar sesión activa si existe
        $this->dropActiveSession($router, $account->username);

        Log::debug('MIKROTIK REST DELETE SECRET', [
            'router'   => $router->name,
            'username' => $account->username,
        ]);
    }

    /**
     * Lista las sesiones pppoe activas
     */
    public function getActiveSessions(Router $router): array
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->get("{$base}/ppp/active");
        $this->checkResponse($response, 'ppp/active');

        return array_map(fn($a) => [
            'mikrotik_id' => $a['.id'],
            'username'    => $a['name'],
            'address'     => $a['address'] ?? null,
            'caller_id'   => $a['caller-id'] ?? null,
            'uptime'      => $a['uptime'] ?? null,
            'service'     => $a['service'] ?? null,
        ], $this->safeJson($response));
    }

    /**
     * Obtiene la sesión activa de un usuario específico
     * Retorna null si no está conectado
     */
    public function getActiveSession(Router $router, string $username): ?array
    {
        $base = $this->baseUrl($router);

        // REST permite filtrar con query params
        $response = $this->http($router)->get("{$base}/ppp/active", [
            'name' => $username,
        ]);
        $this->checkResponse($response, 'ppp/active filtrado');

        $active = $this->safeJson($response);

        if (empty($active)) {
            return null;
        }

        $session = $active[0];

        return [
            'mikrotik_id' => $session['.id'],
            'username'    => $session['name'],
            'address'     => $session['address'] ?? null,
            'caller_id'   => $session['caller-id'] ?? null,
            'uptime'      => $session['uptime'] ?? null,
            'service'     => $session['service'] ?? null,
            'encoding'    => $session['encoding'] ?? null,
            'session_id'  => $session['session-id'] ?? null,
        ];
    }

    /**
     * Contadores de tráfico de TODAS las interfaces del router.
     *
     * Una sola petición devuelve los octetos acumulados de todas
     * las sesiones PPPoE activas: RouterOS crea una interfaz
     * dinámica por sesión, llamada "<pppoe-USUARIO>".
     *
     * De aquí sale el ancho de banda, calculando la diferencia
     * entre dos lecturas consecutivas (los routers no reportan
     * velocidad, sino bytes acumulados).
     *
     * @return array<string, array{in: int, out: int, running: bool}> [usuario => contadores]
     */
    public function getPppoeInterfaceCounters(Router $router): array
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->get("{$base}/interface");
        $this->checkResponse($response, 'interface');

        $counters = [];

        foreach ($this->safeJson($response) as $interface) {
            $name = $interface['name'] ?? '';

            // Solo las interfaces dinámicas de sesiones PPPoE
            if (!preg_match('/^<pppoe-(.+)>$/', $name, $m)) {
                continue;
            }

            $counters[$m[1]] = [
                // rx/tx son desde el punto de vista del ROUTER:
                // rx = lo que sube el cliente, tx = lo que baja
                'in' => (int) ($interface['rx-byte'] ?? 0),
                'out' => (int) ($interface['tx-byte'] ?? 0),
                'running' => ($interface['running'] ?? 'false') === 'true',
            ];
        }

        return $counters;
    }

    /**
     * Velocidad instantánea de una sesión PPPoE.
     *
     * Usa monitor-traffic del router, que entrega bits por segundo
     * en el momento sin necesidad de dos lecturas. Es lo que se
     * muestra en el panel de estado en vivo.
     *
     * @return array{in_bps: int, out_bps: int}|null
     */
    public function getSessionTraffic(Router $router, string $username): ?array
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)
            ->timeout(15)
            ->post("{$base}/interface/monitor-traffic", [
                'interface' => "<pppoe-{$username}>",
                'once' => '',
            ]);

        if ($response->failed()) {
            return null;
        }

        $data = $this->safeJson($response);
        $sample = $data[0] ?? null;

        if (!$sample) {
            return null;
        }

        return [
            // Desde la perspectiva del cliente: lo que el router
            // recibe es la subida del cliente y viceversa
            'in_bps' => (int) ($sample['rx-bits-per-second'] ?? 0),
            'out_bps' => (int) ($sample['tx-bits-per-second'] ?? 0),
        ];
    }

    /**
     * Tumba la sesión activa de un usuario
     */
    public function dropActiveSession(Router $router, string $username): bool
    {
        $base = $this->baseUrl($router);

        $response = $this->http($router)->get("{$base}/ppp/active", [
            'name' => $username,
        ]);
        $this->checkResponse($response, 'buscar sesión activa');

        $active = $this->safeJson($response);

        if (empty($active)) {
            return false;
        }

        $delete = $this->http($router)->delete("{$base}/ppp/active/{$active[0]['.id']}");
        $this->checkResponse($delete, 'eliminar sesión activa');

        Log::debug('MIKROTIK REST DROP SESSION', [
            'router'   => $router->name,
            'username' => $username,
        ]);

        return true;
    }
}
