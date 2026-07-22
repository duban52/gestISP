<?php

namespace Tests\Feature\Network;

use App\Services\OltOntDiscovery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Decodificación de la posición (frame/slot/port) de un puerto GPON a
 * partir de su ifIndex de Huawei SmartAX.
 *
 * Es el respaldo que permite ubicar las ONTs cuando la OLT no publica
 * el ifDescr "GPON_UNI f/s/p" por SNMP (caso de la MA5608T). Los
 * valores esperados se tomaron de equipos reales, donde la fórmula
 * reprodujo el 100% de los ifDescr (5800X17: 96/96, 5800X15: 112/112).
 */
class OltOntDiscoveryTest extends TestCase
{
    private function decodificar(int $ifIndex): ?array
    {
        $metodo = new ReflectionMethod(OltOntDiscovery::class, 'decodeGponIfIndex');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(OltOntDiscovery::class), $ifIndex);
    }

    public function test_decodifica_el_primer_puerto_del_slot(): void
    {
        // 0xFA002000 → 0/1/0
        $this->assertSame(['frame' => 0, 'slot' => 1, 'port' => 0], $this->decodificar(0xFA002000));
    }

    public function test_decodifica_el_ultimo_puerto_del_slot(): void
    {
        // 0xFA002F00 → 0/1/15
        $this->assertSame(['frame' => 0, 'slot' => 1, 'port' => 15], $this->decodificar(0xFA002F00));
    }

    public function test_decodifica_un_puerto_de_otro_slot(): void
    {
        // 0xFA004000 → 0/2/0
        $this->assertSame(['frame' => 0, 'slot' => 2, 'port' => 0], $this->decodificar(0xFA004000));
    }

    public function test_ignora_ifindex_que_no_es_gpon(): void
    {
        // Un ifIndex de otro tipo de puerto (byte alto distinto de 0xFA)
        // no debe producir una ubicación inventada.
        $this->assertNull($this->decodificar(0x08002000));
        $this->assertNull($this->decodificar(1));
    }
}
