<?php

namespace Tests\Feature\Network;

use App\Services\OltSshService;
use phpseclib3\Net\SSH2;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Doble de SSH2 para probar el diálogo sin una OLT real.
 *
 * No abre ninguna conexión (constructor vacío) y responde a las
 * lecturas con un guión preparado, registrando lo que se escribe.
 * Así se puede reproducir el comportamiento de cada modelo:
 *
 *   - MA5608T: tras un comando muestra el prompt directo.
 *   - MA5800 (5800X15/X17): tras algunos comandos muestra
 *     "{ <cr>||<K> }:" y espera un Enter.
 */
class GuionSsh extends SSH2
{
    /** @var array<int, string> Respuestas que devuelve read(), en orden */
    public array $guion = [];

    /** @var array<int, string> Todo lo que se escribió, para verificar */
    public array $escrito = [];

    public function __construct()
    {
        // A propósito no se llama al padre: no se abre conexión.
    }

    // El doble no tiene conexión real: se anula la limpieza que
    // haría el objeto al destruirse (tocaría estado no inicializado).
    public function disconnect(): void
    {
    }

    public function __destruct()
    {
    }

    public function setTimeout($timeout): void
    {
        // Irrelevante en el doble.
    }

    public function write($cmd, $channel = null): int
    {
        $this->escrito[] = $cmd;

        return strlen((string) $cmd);
    }

    public function read($expect = '', $mode = SSH2::READ_SIMPLE, $channel = null)
    {
        // El drenado usa un patrón imposible: aquí siempre ve el
        // búfer vacío (no hay residuos que limpiar en la prueba).
        if (str_contains((string) $expect, 'IMPOSSIBLE_MATCH_TOKEN')) {
            return '';
        }

        return array_shift($this->guion) ?? '';
    }
}

class OltSshDialogTest extends TestCase
{
    private function conversar(GuionSsh $ssh, string $comando, bool $confirmar = false): string
    {
        $metodo = new ReflectionMethod(OltSshService::class, 'converse');
        $metodo->setAccessible(true);

        return $metodo->invoke(new OltSshService(), $ssh, $comando, $confirmar, 3);
    }

    public function test_modelo_con_prompt_directo_no_envia_enter_extra(): void
    {
        // Estilo MA5608T: la respuesta llega completa con el prompt.
        $ssh = new GuionSsh();
        $ssh->guion = [
            "display sysuptime\r\n  System up time: 10 day 2 hour\r\n\r\nMA5608T(config)#",
        ];

        $salida = $this->conversar($ssh, 'display sysuptime');

        $this->assertStringContainsString('System up time', $salida);
        // Solo se envió el comando: ningún Enter de más
        $this->assertSame(["display sysuptime\n"], $ssh->escrito);
    }

    public function test_modelo_con_prompt_de_continuacion_recibe_el_enter(): void
    {
        // Estilo MA5800: primero "{ <cr> }:" y, tras el Enter, la
        // salida y el prompt.
        $ssh = new GuionSsh();
        $ssh->guion = [
            "display sysuptime \r\n{ <cr>||<K> }: ",
            "\r\n  System up time: 90 day\r\n\r\nOLT_MA5800_X7(config)#",
        ];

        $salida = $this->conversar($ssh, 'display sysuptime');

        $this->assertStringContainsString('System up time', $salida);
        // El mismo código, al ver "}:", envió el Enter que este
        // modelo necesita.
        $this->assertSame(["display sysuptime\n", "\n"], $ssh->escrito);
    }

    public function test_confirma_las_operaciones_destructivas(): void
    {
        $ssh = new GuionSsh();
        $ssh->guion = [
            "undo service-port 2312\r\n  Are you sure to delete service port? (y/n)[n]:",
            "\r\n  It will take several minutes, please wait...done\r\n\r\nMA5608T(config)#",
        ];

        $this->conversar($ssh, 'undo service-port 2312', confirmar: true);

        $this->assertSame(["undo service-port 2312\n", "y\n"], $ssh->escrito);
    }

    public function test_no_confirma_cuando_no_se_pide(): void
    {
        $ssh = new GuionSsh();
        $ssh->guion = [
            "ont delete 1 5\r\n  Are you sure to delete the ONT? (y/n)[n]:",
            "\r\n  Failure: operation cancelled\r\n\r\nMA5608T(config)#",
        ];

        $this->conversar($ssh, 'ont delete 1 5', confirmar: false);

        // Sin pedir confirmación, responde "n" y no borra
        $this->assertSame(["ont delete 1 5\n", "n\n"], $ssh->escrito);
    }

    public function test_avanza_la_paginacion_con_espacio(): void
    {
        $ssh = new GuionSsh();
        $ssh->guion = [
            "  linea 1\r\n  linea 2\r\n  ---- More ( Press 'Q' to break ) ----",
            "\r\n  linea 3\r\n  linea 4\r\n\r\nMA5608T(config)#",
        ];

        $salida = $this->conversar($ssh, 'display ont info');

        $this->assertStringContainsString('linea 4', $salida);
        // Ante "---- More" envía un espacio para avanzar
        $this->assertSame(["display ont info\n", ' '], $ssh->escrito);
    }

    public function test_el_service_port_se_extrae_igual_en_ambos_modelos(): void
    {
        // La tabla de la MA5800, que llega tras el prompt "{ <cr> }:"
        $salida5800 = "display service-port port 0/3/13 ont 22 \r\n"
            . "{ <cr>|e2e<K>|gemport<K>|sort-by<K>||<K> }: \r\n"
            . "  INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI ...\r\n"
            . "   1980  150 common   gpon 0/3 /13 22   1     vlan  150 ...\r\n"
            . "  Total : 1\r\n\r\nOLT_MA5800_X7(config)#";

        $parse = new ReflectionMethod(OltSshService::class, 'parseServicePortId');
        $parse->setAccessible(true);

        $this->assertSame(1980, $parse->invoke(new OltSshService(), $salida5800));
    }
}
