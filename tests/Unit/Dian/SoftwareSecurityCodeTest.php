<?php

namespace Tests\Unit\Dian;

use App\Billing\Dian\SoftwareSecurityCode;
use PHPUnit\Framework\TestCase;

/**
 * El código de seguridad del software.
 *
 * QUÉ SE PUEDE DEFENDER Y QUÉ NO
 * ------------------------------
 * A diferencia del CUFE, el anexo NO publica ningún ejemplo resuelto de
 * este código — no podría, porque tendría que revelar un PIN. Así que
 * aquí no hay un valor contra el que comparar.
 *
 * Lo que sí se puede defender es la FORMA, que es donde están los
 * errores que de verdad ocurren: que la concatenación sea la del anexo
 * (§11.8: Id Software + Pin + NroDocumentos, en ese orden), que el
 * número lleve el prefijo, y que cambiar cualquiera de los tres datos
 * cambie el resultado.
 *
 * Es Unit y no Feature: no toca base de datos.
 */
class SoftwareSecurityCodeTest extends TestCase
{
    private const SOFTWARE = 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607';
    private const PIN = '12345';
    private const NUMERO = 'SETP990000001';

    private function calcular(?string $software = null, ?string $pin = null, ?string $numero = null): string
    {
        return (new SoftwareSecurityCode())->calcular(
            $software ?? self::SOFTWARE,
            $pin ?? self::PIN,
            $numero ?? self::NUMERO,
        );
    }

    public function test_es_un_sha384(): void
    {
        // 96 caracteres hexadecimales. Si sale de 64, alguien cambió el
        // algoritmo por SHA-256 y la DIAN lo rechaza.
        $codigo = $this->calcular();

        $this->assertSame(96, strlen($codigo));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', $codigo);
    }

    public function test_la_cadena_es_la_del_anexo(): void
    {
        // Id Software + Pin + NroDocumentos, en ese orden y sin
        // separadores. El orden importa: concatenar al revés da un hash
        // perfectamente válido que la DIAN rechaza.
        $cadena = (new SoftwareSecurityCode())->cadena(self::SOFTWARE, self::PIN, self::NUMERO);

        $this->assertSame(self::SOFTWARE . self::PIN . self::NUMERO, $cadena);
        $this->assertSame(hash('sha384', $cadena), $this->calcular());
    }

    public function test_el_numero_va_con_su_prefijo(): void
    {
        // NroDocumentos es el cbc:ID, que es el numero COMPLETO. Mandar
        // solo el consecutivo da otro codigo.
        $this->assertNotSame(
            $this->calcular(numero: 'SETP990000001'),
            $this->calcular(numero: '990000001'),
        );
    }

    public function test_cualquier_dato_distinto_da_otro_codigo(): void
    {
        $base = $this->calcular();

        $this->assertNotSame($base, $this->calcular(software: 'otro-identificador'));
        $this->assertNotSame($base, $this->calcular(pin: '54321'));
        $this->assertNotSame($base, $this->calcular(numero: 'SETP990000002'));
    }

    public function test_cada_factura_tiene_el_suyo(): void
    {
        // No es una huella del software a secas: entra el numero del
        // documento, asi que cambia en cada factura.
        $primera = $this->calcular(numero: 'SETP990000001');
        $segunda = $this->calcular(numero: 'SETP990000002');

        $this->assertNotSame($primera, $segunda);
    }
}
