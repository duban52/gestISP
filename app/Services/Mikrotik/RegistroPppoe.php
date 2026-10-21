<?php

namespace App\Services\Mikrotik;

use Illuminate\Support\Collection;

/**
 * El registro de PPPoE del router, en castellano.
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * Cuando un cliente llama diciendo «no tengo internet», la respuesta
 * está en el log del Mikrotik: si entró y se cayó, si nunca llegó a
 * autenticar, o si está intentando conectarse con la contraseña
 * equivocada desde hace tres días. Hasta ahora eso exigía entrar por
 * Winbox, saber dónde mirar y entender líneas como:
 *
 *     ppp,pppoe,info,account juan_perez authentication failed
 *
 * Esto las traduce, las clasifica y deja buscar por usuario.
 *
 * EL MENSAJE ORIGINAL NO SE PIERDE NUNCA
 * --------------------------------------
 * Se guarda junto al traducido. RouterOS cambia textos entre
 * versiones y cada fabricante de CPE provoca los suyos: el día que
 * aparezca uno que no se reconoce, el operador tiene que poder leerlo
 * igual. Un «evento desconocido» sin su texto es peor que no traducir
 * nada.
 */
class RegistroPppoe
{
    // Qué pasó, de más grave a menos.
    public const FALLO = 'fallo';
    public const DESCONEXION = 'desconexion';
    public const CONEXION = 'conexion';
    public const OTRO = 'otro';

    /**
     * Traduce las líneas crudas del router.
     *
     * @param  array<int, array<string, mixed>>  $lineas  Lo que devuelve /log
     * @return Collection<int, array<string, mixed>>
     */
    public function interpretar(array $lineas): Collection
    {
        return collect($lineas)
            ->filter(fn ($linea) => $this->esDePppoe($linea['topics'] ?? ''))
            ->map(fn ($linea) => $this->traducir($linea))
            ->values();
    }

    /**
     * ¿Esta línea habla de PPPoE?
     *
     * El log del router lo lleva TODO —DHCP, firewall, wireless, el
     * reloj—, y aquí solo interesa lo de las cuentas. Se filtra por
     * los temas que RouterOS pone en cada línea, no por el texto: el
     * texto cambia entre versiones, los temas no.
     */
    private function esDePppoe(string $temas): bool
    {
        foreach (['ppp', 'pppoe'] as $tema) {
            if (in_array($tema, array_map('trim', explode(',', $temas)), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Una línea del log, interpretada.
     *
     * @param  array<string, mixed>  $linea
     * @return array<string, mixed>
     */
    private function traducir(array $linea): array
    {
        $mensaje = trim((string) ($linea['message'] ?? ''));

        [$tipo, $titulo, $explicacion, $queHacer] = $this->clasificar($mensaje);

        return [
            'hora' => (string) ($linea['time'] ?? ''),
            'temas' => (string) ($linea['topics'] ?? ''),
            'usuario' => $this->usuarioDe($mensaje),
            'tipo' => $tipo,
            'titulo' => $titulo,
            'explicacion' => $explicacion,
            'que_hacer' => $queHacer,
            // El original, siempre.
            'mensaje' => $mensaje,
        ];
    }

    /**
     * De la línea cruda al castellano.
     *
     * Los textos son los de RouterOS 6 y 7. Lo que no encaje cae en
     * «otro» conservando su mensaje: preferible a inventar una
     * explicación que mande a revisar lo que no es.
     *
     * @return array{0: string, 1: string, 2: ?string, 3: ?string}
     */
    private function clasificar(string $m): array
    {
        $texto = mb_strtolower($m);

        return match (true) {
            // ---------- Conexiones ----------
            str_contains($texto, 'logged in')
                => [self::CONEXION, 'Conectó', 'La sesión se estableció correctamente.', null],

            str_contains($texto, 'connection established')
                => [self::CONEXION, 'Enlace establecido',
                    'El equipo del cliente llegó al router y empezó la negociación.', null],

            // ---------- Desconexiones normales ----------
            str_contains($texto, 'logged out')
                => [self::DESCONEXION, 'Se desconectó',
                    'La sesión terminó. Es lo normal al apagar el equipo o reiniciar la ONT.', null],

            str_contains($texto, 'peer is not responding')
                => [self::DESCONEXION, 'El equipo del cliente dejó de responder',
                    'El router no recibió respuesta del CPE y cerró la sesión. Suele ser corte de energía '
                    . 'en casa del cliente o un problema en la fibra.',
                    'Mire la potencia óptica de su ONT: si también está caída, el problema es de planta externa.'],

            str_contains($texto, 'terminating')
                => [self::DESCONEXION, 'Sesión terminada',
                    'El router cerró la sesión. El motivo va en el propio mensaje.', null],

            // ---------- Fallos de autenticación ----------
            str_contains($texto, 'authentication failed') || str_contains($texto, 'invalid username or password')
                => [self::FALLO, 'Usuario o contraseña incorrectos',
                    'El equipo del cliente intentó conectarse con credenciales que el router no acepta.',
                    'Compruebe el usuario y la contraseña en la ficha de la cuenta y vuelva a cargarlos en el CPE. '
                    . 'Si se repite cada pocos segundos, el CPE está reintentando solo.'],

            str_contains($texto, 'no such user') || str_contains($texto, 'user not found')
                => [self::FALLO, 'Ese usuario no existe en el router',
                    'El CPE está pidiendo un usuario que no está dado de alta.',
                    'Revise si la cuenta se borró del router o si el CPE quedó con el usuario de otro cliente.'],

            str_contains($texto, 'disabled')
                => [self::FALLO, 'La cuenta está suspendida',
                    'El usuario existe pero está deshabilitado en el router: por eso no entra.',
                    'Si no debería estarlo, reactívela desde la ficha de la cuenta.'],

            // ---------- Problemas de red / capacidad ----------
            str_contains($texto, 'no more ip addresses') || str_contains($texto, 'pool')
                => [self::FALLO, 'Sin direcciones IP libres',
                    'El rango de direcciones del router se agotó: aunque el usuario autentique bien, no hay IP '
                    . 'que darle.',
                    'Amplíe el pool en el router. Esto afecta a TODOS los clientes nuevos, no solo a este.'],

            str_contains($texto, 'already active') || str_contains($texto, 'max session')
                => [self::FALLO, 'La cuenta ya tiene una sesión abierta',
                    'El router no permite más sesiones simultáneas para ese usuario.',
                    'Suele pasar cuando la sesión anterior quedó colgada. Ciérrela desde la ficha de la cuenta '
                    . 'y deje que el equipo reintente.'],

            str_contains($texto, 'timeout') || str_contains($texto, 'timed out')
                => [self::FALLO, 'Se agotó el tiempo de espera',
                    'La negociación no terminó a tiempo. Suele ser señal mala o un CPE que responde con retraso.', null],

            default => [self::OTRO, 'Otro evento', null, null],
        };
    }

    /**
     * El usuario PPPoE al que se refiere la línea.
     *
     * RouterOS lo pone al principio («juan_perez logged in...») o
     * dentro del nombre de la interfaz dinámica
     * («<pppoe-juan_perez>: ...»). Es lo que permite buscar por
     * cliente, que es como se mira este registro cuando llama alguien.
     */
    private function usuarioDe(string $mensaje): ?string
    {
        if (preg_match('/<pppoe-([^>]+)>/', $mensaje, $m)) {
            return $m[1];
        }

        // «user juan_perez authentication failed»
        if (preg_match('/\buser\s+([A-Za-z0-9._\-]+)/i', $mensaje, $m)) {
            return $m[1];
        }

        // «juan_perez logged in, 10.0.0.5»
        if (preg_match('/^([A-Za-z0-9._\-]+)\s+(logged|is|authentication)/i', $mensaje, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Las cifras de la cabecera. @param Collection<int, array> $eventos */
    public function resumen(Collection $eventos): array
    {
        return [
            'total' => $eventos->count(),
            'conexiones' => $eventos->where('tipo', self::CONEXION)->count(),
            'desconexiones' => $eventos->where('tipo', self::DESCONEXION)->count(),
            'fallos' => $eventos->where('tipo', self::FALLO)->count(),
            // Los usuarios que más fallan: es lo que convierte un log
            // en un parte de averías.
            'reincidentes' => $eventos->where('tipo', self::FALLO)
                ->whereNotNull('usuario')
                ->groupBy('usuario')
                ->map->count()
                ->sortDesc()
                ->take(5),
        ];
    }

    public static function colorDe(string $tipo): string
    {
        return match ($tipo) {
            self::CONEXION => 'success',
            self::DESCONEXION => 'secondary',
            self::FALLO => 'danger',
            default => 'light',
        };
    }

    public static function iconoDe(string $tipo): string
    {
        return match ($tipo) {
            self::CONEXION => 'fa-plug',
            self::DESCONEXION => 'fa-unlink',
            self::FALLO => 'fa-exclamation-triangle',
            default => 'fa-info-circle',
        };
    }
}
