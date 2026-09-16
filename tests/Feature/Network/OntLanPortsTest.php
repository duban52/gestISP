<?php

namespace Tests\Feature\Network;

use App\Services\OltSshService;
use Tests\TestCase;

/** Parseo de `display ont port state <port> <ont> eth-port all`. */
class OntLanPortsTest extends TestCase
{
    /** Salida real de una OLT Huawei: ONT de 4 puertos, todos caídos. */
    private const CUATRO_PUERTOS = <<<'TXT'
  --------------------------------------------------------------------------
  ONT-ID   ONT      ONT       Speed(Mbps)   Duplex   LinkState  RingStatus
           port-ID  Port-type
  --------------------------------------------------------------------------
       1         1         GE -             -        down       -
       1         2         FE -             -        down       -
       1         3         FE -             -        down       -
       1         4         FE -             -        down       -
  --------------------------------------------------------------------------
TXT;

    public function test_lee_cada_puerto_con_su_tipo(): void
    {
        $puertos = OltSshService::parseLanPorts(self::CUATRO_PUERTOS);

        $this->assertCount(4, $puertos);
        $this->assertSame(
            ['GE', 'FE', 'FE', 'FE'],
            array_column($puertos, 'tipo'),
        );
        $this->assertSame([1, 2, 3, 4], array_column($puertos, 'puerto'));
    }

    public function test_el_guion_de_la_olt_no_es_un_dato(): void
    {
        // Con el puerto caído la OLT pone "-" en velocidad y duplex.
        // Pintarlo tal cual haría creer que el dato existe.
        $puerto = OltSshService::parseLanPorts(self::CUATRO_PUERTOS)[0];

        $this->assertNull($puerto['velocidad']);
        $this->assertNull($puerto['duplex']);
        $this->assertSame('down', $puerto['estado']);
    }

    public function test_un_puerto_conectado_trae_velocidad_y_duplex(): void
    {
        $puertos = OltSshService::parseLanPorts(<<<'TXT'
  ONT-ID   ONT      ONT       Speed(Mbps)   Duplex   LinkState  RingStatus
           port-ID  Port-type
       4         1         GE 1000          full     up         -
TXT);

        $this->assertSame('1000', $puertos[0]['velocidad']);
        $this->assertSame('full', $puertos[0]['duplex']);
        $this->assertSame('up', $puertos[0]['estado']);
    }

    public function test_una_salida_sin_filas_no_inventa_puertos(): void
    {
        $this->assertSame([], OltSshService::parseLanPorts('  ONT-ID  ONT  ONT'));
        $this->assertSame([], OltSshService::parseLanPorts(''));
    }
}
