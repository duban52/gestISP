<?php

namespace Tests\Feature\Network;

use App\Services\OltSshService;
use Tests\TestCase;

/** Parseo de MAC GPON y WAN. Salidas reales de una OLT Huawei. */
class OntAccessInfoTest extends TestCase
{
    private const MAC = <<<'TXT'
olt_interya(config)#display mac-address port 0/9/0 ont 56
  -----------------------------------------------------------------------
   SRV-P BUNDLE TYPE MAC            MAC TYPE F /S /P   VPI  VCI   VLAN ID
   INDEX INDEX
  -----------------------------------------------------------------------
    4252     -  gpon dc54-ad8c-04bc dynamic  0 /9 /0   56   1         206
  -----------------------------------------------------------------------
  Total: 1
TXT;

    private const WAN = <<<'TXT'
  ---------------------------------------------------------------------
  F/S/P                      : 0/9/0
  ONT ID                     : 56
  ---------------------------------------------------------------------
  Index                      : 1
  Name                       : 1_INTERNET_R_VID_206
  Service type               : Internet
  Connection type            : IP routed
  IPv4 Connection status     : Connected
  IPv4 access type           : DHCP
  IPv4 address               : 10.82.0.171
  Subnet mask                : 255.255.252.0
  Default gateway            : 10.82.0.1
  Manage VLAN                : 206
  MAC address                : DC54-AD8C-04BC
  L2 encap-type              : IPoE
  IPv6 switch                : Disable
  Prefix                     : -
  IPv6 address               : -
  ---------------------------------------------------------------------
TXT;

    private const VERSION = <<<'TXT'
olt_interya(config)#display ont version 0 9 0 56
  --------------------------------------------------------------------------
  F/S/P                    : 0/9/0
  ONT-ID                   : 56
  Vendor-ID                : HWTC
  ONT Version              : V1.0
  Product-ID               : 0
  Equipment-ID             : ZK9004WT
  Main Software Version    : v1.0.15
  Standby Software Version : v1.0.13
  OntProductDescription    :
  Support XML Version      :
  --------------------------------------------------------------------------
TXT;

    public function test_saca_marca_y_modelo(): void
    {
        $v = OltSshService::parseVersion(self::VERSION);

        $this->assertSame('HWTC', $v['Vendor-ID']);
        $this->assertSame('ZK9004WT', $v['Equipment-ID']);
        $this->assertSame('v1.0.15', $v['Main Software Version']);
    }

    public function test_los_campos_vacios_no_se_guardan(): void
    {
        // "OntProductDescription :" sin valor. Guardarlo dejaria una
        // fila vacia en la ficha.
        $v = OltSshService::parseVersion(self::VERSION);

        $this->assertArrayNotHasKey('OntProductDescription', $v);
        $this->assertArrayNotHasKey('Support XML Version', $v);
    }

    public function test_saca_la_mac_gpon(): void
    {
        $mac = OltSshService::parseMac(self::MAC);

        $this->assertSame('DC:54:AD:8C:04:BC', $mac['mac']);
        $this->assertSame('gpon', $mac['tipo']);
        $this->assertSame('dynamic', $mac['aprendizaje']);
        $this->assertSame('206', $mac['vlan']);
        // SRV-P INDEX: el service-port, que se resolvia con otra consulta.
        $this->assertSame(4252, $mac['service_port']);
    }

    public function test_sin_mac_devuelve_null(): void
    {
        // La cabecera lleva la palabra MAC pero no una direccion.
        $this->assertNull(OltSshService::parseMac("  TYPE MAC   MAC TYPE\n  Total: 0"));
    }

    public function test_lee_el_servicio_wan(): void
    {
        $wan = OltSshService::parseWan(self::WAN);

        $this->assertCount(1, $wan);
        $this->assertSame('10.82.0.171', $wan[0]['IPv4 address']);
        $this->assertSame('Connected', $wan[0]['IPv4 Connection status']);
        $this->assertSame('DHCP', $wan[0]['IPv4 access type']);
        $this->assertSame('206', $wan[0]['Manage VLAN']);
        $this->assertSame('1_INTERNET_R_VID_206', $wan[0]['Name']);
    }

    public function test_lo_que_no_aplica_no_se_pinta(): void
    {
        // La OLT pone "-" en IPv6 cuando esta desactivado. Mostrarlo
        // como campo haria creer que hay algo configurado.
        $wan = OltSshService::parseWan(self::WAN);

        $this->assertArrayNotHasKey('Prefix', $wan[0]);
        $this->assertArrayNotHasKey('IPv6 address', $wan[0]);
    }

    public function test_la_cabecera_no_es_un_servicio(): void
    {
        // "F/S/P" y "ONT ID" van ANTES del primer Index: son del
        // equipo, no de un servicio WAN.
        $wan = OltSshService::parseWan(self::WAN);

        $this->assertArrayNotHasKey('F/S/P', $wan[0]);
        $this->assertArrayNotHasKey('ONT ID', $wan[0]);
    }

    public function test_varios_servicios_son_varios_bloques(): void
    {
        $wan = OltSshService::parseWan(self::WAN . <<<'TXT'

  Index                      : 2
  Name                       : 2_TR069_VID_300
  Service type               : TR069
  IPv4 address               : 192.168.1.5
TXT);

        $this->assertCount(2, $wan);
        $this->assertSame('192.168.1.5', $wan[1]['IPv4 address']);
    }

    public function test_sin_wan_no_inventa_servicios(): void
    {
        $this->assertSame([], OltSshService::parseWan('  Failure: ONT does not exist'));
    }
}
