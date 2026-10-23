<?php

namespace App\Services;

use App\Models\Olt;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Por qué no se conecta con una OLT.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * El panel decía «Desconectado» y nada más. El controlador atrapaba
 * la excepción con un `catch (\Exception $e)` que no miraba `$e`, así
 * que el motivo —el único dato útil— se tiraba a la basura. Con los
 * datos aparentemente correctos en pantalla no había forma de saber
 * si el problema era el firewall, el puerto, la contraseña o el
 * cifrado del equipo.
 *
 * POR CAPAS, Y EN ESE ORDEN
 * -------------------------
 * Conectar con una OLT son cuatro cosas distintas que fallan por
 * motivos distintos y se arreglan en sitios distintos:
 *
 *   1. Que el paquete LLEGUE al puerto      → firewall, NAT, IP
 *   2. Que al otro lado haya un SSH que
 *      negocie el cifrado                   → equipo viejo, puerto equivocado
 *   3. Que el usuario y la clave valgan     → credenciales
 *   4. Que acepte comandos                  → perfil del usuario
 *
 * Y el SNMP va por su cuenta: otro puerto, otro protocolo y otra
 * credencial, así que una OLT puede tener el SSH perfecto y el SNMP
 * muerto, o al revés. Por eso se comprueba aparte y su fallo no
 * detiene el resto.
 *
 * Se para en el primer fallo de la cadena SSH a propósito: si el
 * puerto no responde, probar la contraseña no dice nada y encima
 * hace esperar otro tiempo de espera completo.
 */
class DiagnosticoDeOlt
{
    public const OK = 'ok';
    public const FALLO = 'fallo';
    public const AVISO = 'aviso';
    public const OMITIDO = 'omitido';

    /** Segundos de espera de cada prueba. Corto: esto es interactivo. */
    private const ESPERA = 6;

    /** sysDescr: el OID que contesta cualquier equipo con SNMP vivo. */
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';

    /**
     * Revisa la OLT capa por capa.
     *
     * @return array{pasos: array<int, array<string, mixed>>, conecta: bool, resumen: string}
     */
    public function ejecutar(Olt $olt): array
    {
        $pasos = [];

        $pasos[] = $puerto = $this->puertoSsh($olt);

        if ($puerto['estado'] === self::OK) {
            $pasos[] = $sesion = $this->sesionSsh($olt);

            if ($sesion['estado'] === self::OK) {
                $pasos[] = $this->aceptaComandos($olt, $sesion['ssh']);
            } else {
                $pasos[] = $this->omitido(
                    'Responde a comandos',
                    'No se llegó a probar: la sesión no se pudo abrir.',
                );
            }
        } else {
            $pasos[] = $this->omitido(
                'Sesión SSH',
                'No se llegó a probar: el puerto no respondió.',
            );
            $pasos[] = $this->omitido(
                'Responde a comandos',
                'No se llegó a probar: el puerto no respondió.',
            );
        }

        // El SNMP se mira SIEMPRE, falle lo que falle arriba: va por
        // otro puerto y es lo que alimenta las potencias y el estado
        // de las ONTs. Una OLT con el SSH caído y el SNMP vivo sigue
        // dando la mitad de la información.
        $pasos[] = $this->snmp($olt);

        // La clave `ssh` es el objeto de conexión, que no sale de aquí.
        $pasos = array_map(fn ($p) => array_diff_key($p, ['ssh' => null]), $pasos);

        $conecta = collect($pasos)->every(fn ($p) => $p['estado'] !== self::FALLO);

        return [
            'pasos' => $pasos,
            'conecta' => $conecta,
            'resumen' => $this->resumen($pasos, $conecta),
        ];
    }

    /**
     * ¿Llega el paquete al puerto SSH?
     *
     * Se distingue «no llega» de «llega y nadie contesta» por el
     * TIEMPO, no por el código de error: el `errno` de fsockopen no
     * vale lo mismo en Linux que en Windows, y esto corre en los dos.
     * Agotar la espera es un paquete que se pierde —firewall, NAT mal
     * publicado, IP que ya no es esa—; fallar enseguida es que algo
     * contestó «aquí no hay nadie escuchando».
     */
    private function puertoSsh(Olt $olt): array
    {
        $arranque = microtime(true);
        $socket = @fsockopen($olt->ip_address, (int) $olt->ssh_port, $errno, $errstr, self::ESPERA);
        $tardo = microtime(true) - $arranque;

        if ($socket) {
            fclose($socket);

            return [
                'titulo' => 'Puerto SSH',
                'estado' => self::OK,
                'detalle' => sprintf('%s:%s responde.', $olt->ip_address, $olt->ssh_port),
                'consejo' => null,
            ];
        }

        $agotoLaEspera = $tardo >= self::ESPERA * 0.9;

        return [
            'titulo' => 'Puerto SSH',
            'estado' => self::FALLO,
            'detalle' => sprintf(
                '%s:%s no responde (%s).',
                $olt->ip_address,
                $olt->ssh_port,
                $errstr ?: 'sin respuesta',
            ),
            'consejo' => $agotoLaEspera
                ? 'El paquete no llega: no hay ruta hasta ese equipo o algo lo está filtrando. '
                    . 'Revise el firewall, la redirección de puertos del router y que la IP siga '
                    . 'siendo esa. Compruébelo desde el servidor con '
                    . "«telnet {$olt->ip_address} {$olt->ssh_port}»."
                : 'El equipo contesta pero en ese puerto no hay nadie escuchando. '
                    . 'Compruebe el número de puerto SSH y que el servicio esté levantado en la OLT.',
        ];
    }

    /**
     * ¿Hay un SSH que negocie y acepte las credenciales?
     *
     * Son dos fallos muy distintos en el mismo paso porque phpseclib
     * los hace en la misma llamada. Se separan por el tipo de
     * excepción, y la distinción importa: un cifrado que no negocia
     * NO se arregla cambiando la contraseña, y es el fallo típico de
     * una OLT antigua contra un PHP moderno.
     */
    private function sesionSsh(Olt $olt): array
    {
        try {
            $ssh = new SSH2($olt->ip_address, (int) $olt->ssh_port, self::ESPERA);

            if (!$ssh->login($olt->username, $olt->getPlainPassword())) {
                return [
                    'titulo' => 'Sesión SSH',
                    'estado' => self::FALLO,
                    'detalle' => sprintf('El equipo rechazó el usuario «%s».', $olt->username),
                    'consejo' => 'El puerto responde y el SSH negocia, así que lo que falla es la '
                        . 'credencial: revise el usuario y vuelva a escribir la contraseña en la '
                        . 'configuración de la OLT. Compruebe también que ese usuario no esté '
                        . 'bloqueado por intentos fallidos en el propio equipo.',
                ];
            }

            return [
                'titulo' => 'Sesión SSH',
                'estado' => self::OK,
                'detalle' => sprintf('Autenticado como «%s».', $olt->username),
                'consejo' => null,
                'ssh' => $ssh,
            ];
        } catch (\phpseclib3\Exception\NoSupportedAlgorithmsException $e) {
            return [
                'titulo' => 'Sesión SSH',
                'estado' => self::FALLO,
                'detalle' => 'No hay un cifrado en común con el equipo: ' . $e->getMessage(),
                'consejo' => 'Es el fallo típico de una OLT antigua: solo ofrece algoritmos que '
                    . 'las versiones modernas ya no aceptan. Se arregla en el EQUIPO, habilitando '
                    . 'un intercambio de claves y un cifrado actuales; no en esta pantalla, y no '
                    . 'cambiando la contraseña.',
            ];
        } catch (Throwable $e) {
            return [
                'titulo' => 'Sesión SSH',
                'estado' => self::FALLO,
                'detalle' => $this->enClaro($e),
                'consejo' => 'El puerto responde pero no se completa el saludo SSH. Suele ser que '
                    . 'en ese puerto hay otro servicio (telnet, web) o que el equipo corta la '
                    . 'conexión por tener ya demasiadas sesiones abiertas: cierre las que haya en '
                    . 'el equipo y reintente.',
            ];
        }
    }

    /** ¿El usuario puede ejecutar algo, o entra y no le dejan hacer nada? */
    private function aceptaComandos(Olt $olt, SSH2 $ssh): array
    {
        try {
            $ssh->setTimeout(self::ESPERA);
            $salida = trim((string) $ssh->read());
            $ssh->disconnect();

            if ($salida === '') {
                return [
                    'titulo' => 'Responde a comandos',
                    'estado' => self::AVISO,
                    'detalle' => 'La sesión se abrió pero el equipo no devolvió ningún texto.',
                    'consejo' => 'Puede ser un banner que tarda. Si el estado sigue en '
                        . '«Desconectado», revise el perfil del usuario en la OLT: tiene que poder '
                        . 'entrar en modo privilegiado («enable») y ejecutar «display».',
                ];
            }

            return [
                'titulo' => 'Responde a comandos',
                'estado' => self::OK,
                'detalle' => 'El equipo contesta: ' . $this->recorte($salida),
                'consejo' => null,
            ];
        } catch (Throwable $e) {
            return [
                'titulo' => 'Responde a comandos',
                'estado' => self::FALLO,
                'detalle' => $this->enClaro($e),
                'consejo' => 'Se entró pero el equipo no responde a los comandos. Revise que el '
                    . 'usuario tenga nivel suficiente en la OLT.',
            ];
        }
    }

    /**
     * El SNMP, que es de donde salen las potencias y el estado de las
     * ONTs. Va por UDP y por otro puerto: que el SSH funcione no dice
     * absolutamente nada de esto.
     */
    private function snmp(Olt $olt): array
    {
        if (!class_exists('SNMP')) {
            return [
                'titulo' => 'SNMP',
                'estado' => self::FALLO,
                'detalle' => 'La extensión SNMP de PHP no está instalada en el servidor.',
                'consejo' => 'Instálela y reinicie PHP: «apt install php8.3-snmp && systemctl '
                    . 'restart php8.3-fpm». Sin ella no hay potencias ni estado de ONTs, por '
                    . 'mucho que el SSH funcione.',
            ];
        }

        if (blank($olt->read_snmp_comunity)) {
            return [
                'titulo' => 'SNMP',
                'estado' => self::FALLO,
                'detalle' => 'Esta OLT no tiene community de lectura configurada.',
                'consejo' => 'Escríbala en la configuración de la OLT: es la contraseña con la '
                    . 'que se leen las potencias y el estado de las ONTs.',
            ];
        }

        $destino = $olt->ip_address . ':' . ($olt->snmp_port ?: 161);

        try {
            $sesion = new \SNMP(\SNMP::VERSION_2C, $destino, $olt->read_snmp_comunity, self::ESPERA * 1000000, 1);
            $sesion->valueretrieval = SNMP_VALUE_PLAIN;
            $sesion->exceptions_enabled = \SNMP::ERRNO_ANY;

            $descripcion = $sesion->get(self::SYS_DESCR);
            $sesion->close();

            return [
                'titulo' => 'SNMP',
                'estado' => self::OK,
                'detalle' => 'El equipo se identifica como: ' . $this->recorte((string) $descripcion),
                'consejo' => null,
            ];
        } catch (Throwable $e) {
            return [
                'titulo' => 'SNMP',
                'estado' => self::FALLO,
                'detalle' => sprintf('%s no contesta por SNMP: %s', $destino, $this->enClaro($e)),
                'consejo' => 'El SNMP va por UDP y por su propio puerto, así que esto falla aparte '
                    . 'del SSH. Revise: que el puerto SNMP sea el correcto, que la community de '
                    . 'lectura coincida con la del equipo, y que la OLT tenga autorizada la IP de '
                    . 'este servidor — muchas filtran por lista de permitidos.',
            ];
        }
    }

    /** Una frase corta con lo que hay que mirar primero. */
    private function resumen(array $pasos, bool $conecta): string
    {
        if ($conecta) {
            return 'La OLT responde por SSH y por SNMP.';
        }

        $primerFallo = collect($pasos)->firstWhere('estado', self::FALLO);

        return $primerFallo
            ? 'Lo primero que hay que resolver es «' . $primerFallo['titulo'] . '».'
            : 'La OLT responde, pero con avisos.';
    }

    /**
     * El mensaje de la excepción, sin la traza ni las rutas del
     * servidor. Lo lee un técnico, no un programador.
     */
    private function enClaro(Throwable $e): string
    {
        $mensaje = trim($e->getMessage());

        return $mensaje !== '' ? $this->recorte($mensaje) : class_basename($e);
    }

    private function recorte(string $texto, int $largo = 200): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? $texto;

        return mb_strlen($texto) > $largo ? mb_substr($texto, 0, $largo) . '…' : $texto;
    }

    private function omitido(string $titulo, string $detalle): array
    {
        return [
            'titulo' => $titulo,
            'estado' => self::OMITIDO,
            'detalle' => $detalle,
            'consejo' => null,
        ];
    }
}
