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
        // La pausa de reintentos frena el SONDEO AUTOMÁTICO. Aquí hay
        // una persona que acaba de pulsar un botón para averiguar qué
        // pasa: negarle la prueba sería inútil justo cuando más falta
        // hace. Se levanta antes de empezar; si el equipo vuelve a
        // rechazar la credencial, el propio intento la pone otra vez.
        if ($olt->auth_failed_at) {
            $olt->forceFill(['auth_failed_at' => null])->saveQuietly();
        }

        $pasos = [];

        $pasos[] = $puerto = $this->puertoSsh($olt);

        if ($puerto['estado'] === self::OK) {
            $pasos[] = $sesion = $this->sesionSsh($olt);

            if ($sesion['estado'] === self::OK) {
                $pasos[] = $this->aceptaComandos($olt);
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
                // QUÉ PIDE EL EQUIPO PARA DEJAR ENTRAR.
                //
                // Sin esto, «rechazó el usuario» tapa dos problemas muy
                // distintos: una contraseña equivocada y un equipo que
                // no quiere contraseña —quiere clave pública, o pide un
                // segundo factor—. El primero se arregla aquí; el
                // segundo no se arregla aquí en absoluto, y perseguir
                // la contraseña es perder la tarde.
                $metodos = $ssh->getAuthMethodsToContinue() ?: [];
                $soloPorClave = $metodos && !array_intersect($metodos, ['password', 'keyboard-interactive']);

                return [
                    'titulo' => 'Sesión SSH',
                    'estado' => self::FALLO,
                    'detalle' => sprintf(
                        'El equipo rechazó el usuario «%s».%s',
                        $olt->username,
                        $metodos ? ' Admite: ' . implode(', ', $metodos) . '.' : '',
                    ),
                    'consejo' => $soloPorClave
                        ? 'El equipo NO acepta contraseña para este usuario: pide ' . implode(' o ', $metodos)
                            . '. Cambiar la contraseña aquí no servirá de nada; hay que habilitar el '
                            . 'acceso por contraseña en la OLT para el usuario de gestISP.'
                        : 'El puerto responde y el SSH negocia, así que lo que falla es la '
                            . 'credencial: revise el usuario y vuelva a escribir la contraseña en la '
                            . 'configuración de la OLT. Compruebe también que ese usuario no esté '
                            . 'bloqueado por intentos fallidos y que el equipo no tenga ya abiertas '
                            . 'todas las sesiones que admite — muchas OLT solo permiten unas pocas a '
                            . 'la vez y rechazan el resto como si fuera un fallo de credencial.',
                ];
            }

            // La sesión se cierra aquí: el paso siguiente abre la suya
            // por el camino de producción. Dejarla abierta sería una
            // sesión de más en un equipo que a veces las raciona.
            $ssh->disconnect();

            return [
                'titulo' => 'Sesión SSH',
                'estado' => self::OK,
                'detalle' => sprintf('Autenticado como «%s».', $olt->username),
                'consejo' => null,
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
                'consejo' => $this->cortaAntesDeSaludar($e)
                    // El puerto acepta la conexión y acto seguido la cierra, sin
                    // mandar su identificación. Un servicio equivocado en ese
                    // puerto no se comporta así: esto es el equipo rechazando
                    // ACTIVAMENTE a quien llama.
                    ? 'El equipo acepta la conexión y la corta antes de saludar. Casi siempre es '
                        . 'una de dos: la OLT bloqueó la IP de este servidor por intentos de acceso '
                        . 'fallidos (defensa contra ataques), o tiene ya ocupadas todas las sesiones '
                        . 'que admite. Compruébelo en el equipo con «display ssh server session» y '
                        . '«display attack-defense», y revise si hay una lista de IP autorizadas que '
                        . 'no incluya este servidor. Si fue un bloqueo, suele levantarse solo en unos '
                        . 'minutos — pero volverá a caer si la contraseña guardada sigue siendo la '
                        . 'equivocada.'
                    : 'El puerto responde pero no se completa el saludo SSH. Suele ser que en ese '
                        . 'puerto hay otro servicio (telnet, web) en lugar del SSH: revise el número '
                        . 'de puerto y, si hay una redirección en el router, a dónde apunta.',
            ];
        }
    }

    /**
     * ¿El equipo aceptó la conexión y la cerró sin identificarse?
     *
     * Es la firma de un bloqueo o de un límite de sesiones, y hay que
     * distinguirla de «en ese puerto no hay un SSH»: se parecen en la
     * pantalla y no se arreglan en el mismo sitio ni por la misma
     * persona.
     */
    private function cortaAntesDeSaludar(Throwable $e): bool
    {
        $mensaje = mb_strtolower($e->getMessage());

        foreach (['identification string', 'connection reset', 'connection closed'] as $senal) {
            if (str_contains($mensaje, $senal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Responde a los comandos que el panel le manda?
     *
     * ESTE PASO EJECUTA EL CAMINO DE VERDAD, no una imitación.
     *
     * Antes hacía un `read()` suelto sobre la sesión, y eso daba un
     * «contesta» alegre con un equipo cuyo panel seguía en
     * «Desconectado»: autenticar y aceptar comandos son cosas
     * distintas. Lo que el panel necesita es `enable` seguido de
     * `display sysuptime` y `display temperature`, con el diálogo
     * adaptativo que lee hasta el prompt — que es justo la parte
     * frágil. Aproximarla aquí era garantizar un diagnóstico que dice
     * que todo va bien mientras la pantalla dice lo contrario.
     *
     * Cuesta una segunda sesión SSH. En un botón que pulsa una persona
     * para averiguar por qué no funciona, eso está bien pagado.
     */
    private function aceptaComandos(Olt $olt): array
    {
        try {
            $estado = app(OltSshService::class)->getOltStatus($olt);

            return [
                'titulo' => 'Responde a comandos',
                'estado' => self::OK,
                'detalle' => sprintf(
                    'El equipo contesta: uptime %s, temperatura %s.',
                    $estado['uptime'] ?: 'sin dato',
                    $estado['temperature'] ?: 'sin dato',
                ),
                'consejo' => null,
            ];
        } catch (Throwable $e) {
            return [
                'titulo' => 'Responde a comandos',
                'estado' => self::FALLO,
                'detalle' => $this->enClaro($e),
                'consejo' => 'Se entra al equipo pero falla al pedirle los datos. Como la sesión '
                    . 'SSH sí se abrió, lo que falla es el nivel del usuario o el diálogo con el '
                    . 'equipo: compruebe que «' . $olt->username . '» puede ejecutar «enable» y '
                    . '«display sysuptime» entrando a mano, y que la OLT no le pide cambiar la '
                    . 'contraseña al entrar — un aviso así deja la sesión esperando una respuesta '
                    . 'que nadie da.',
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
