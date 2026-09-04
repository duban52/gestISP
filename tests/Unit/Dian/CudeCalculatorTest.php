<?php

namespace Tests\Unit\Dian;

use App\Billing\Dian\CudeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * El CUDE, contra el ejemplo resuelto de la propia DIAN.
 *
 * Igual que con el CUFE: es un hash, así que o sale exactamente el que
 * espera la DIAN o el documento se rechaza, y mirando el resultado no
 * hay forma de saber cuál de los catorce campos está mal.
 *
 * El anexo técnico 1.9 publica en §11.4.3 un ejemplo de nota crédito
 * con sus datos y su CUDE. Esta prueba lo reproduce.
 *
 * LO QUE MÁS SE DEFIENDE
 * ----------------------
 * Que el CUDE lleve el **PIN del software** donde el CUFE lleva la
 * **clave técnica**. Es la única diferencia entre los dos, y
 * confundirlas produce un hash perfectamente válido que la DIAN
 * rechaza.
 */
class CudeCalculatorTest extends TestCase
{
    /** Los datos del ejemplo del anexo, §11.4.3. */
    private const EJEMPLO = [
        'numero' => '8110007871',
        'fecha' => '2019-01-12',
        'hora' => '07:00:00-05:00',
        'valorSinImpuestos' => 5000.00,
        'iva' => 950.00,
        'inc' => 0.00,
        'ica' => 0.00,
        'total' => 5950.00,
        'nitEmisor' => '900373076',
        'adquiriente' => '8355990',
        'pin' => '12301',
        'ambiente' => '1',
    ];

    private const CUDE_ESPERADO =
        '907e4444decc9e59c160a2fb3b6659b33dc5b632a5008922b9a62f83f757b1c448e47f5867f2b50dbdb96f48c7681168';

    private function calcular(array $cambios = []): string
    {
        $d = array_merge(self::EJEMPLO, $cambios);

        return (new CudeCalculator())->calcular(
            $d['numero'], $d['fecha'], $d['hora'],
            $d['valorSinImpuestos'], $d['iva'], $d['inc'], $d['ica'], $d['total'],
            $d['nitEmisor'], $d['adquiriente'], $d['pin'], $d['ambiente'],
        );
    }

    public function test_reproduce_el_ejemplo_publicado_por_la_dian(): void
    {
        $this->assertSame(self::CUDE_ESPERADO, $this->calcular());
    }

    public function test_la_cadena_es_la_del_anexo(): void
    {
        // La del ejemplo, literal.
        $cadena = (new CudeCalculator())->cadena(
            self::EJEMPLO['numero'], self::EJEMPLO['fecha'], self::EJEMPLO['hora'],
            self::EJEMPLO['valorSinImpuestos'], self::EJEMPLO['iva'],
            self::EJEMPLO['inc'], self::EJEMPLO['ica'], self::EJEMPLO['total'],
            self::EJEMPLO['nitEmisor'], self::EJEMPLO['adquiriente'],
            self::EJEMPLO['pin'], self::EJEMPLO['ambiente'],
        );

        $this->assertSame(
            '81100078712019-01-1207:00:00-05:005000.0001950.00040.00030.005950.009003730768355990123011',
            $cadena,
        );
    }

    public function test_el_pin_va_donde_el_cufe_lleva_la_clave_tecnica(): void
    {
        // ES LA DIFERENCIA ENTRE LOS DOS CODIGOS. Usar la clave tecnica
        // aqui da un hash perfectamente valido que la DIAN rechaza, y
        // no hay forma de verlo mirando el resultado.
        $this->assertNotSame(
            self::CUDE_ESPERADO,
            $this->calcular(['pin' => '693ff6f2a553c3646a063436fd4dd9ded0311471']),
        );

        $this->assertStringContainsString(
            '12301',
            (new CudeCalculator())->cadena(
                self::EJEMPLO['numero'], self::EJEMPLO['fecha'], self::EJEMPLO['hora'],
                self::EJEMPLO['valorSinImpuestos'], self::EJEMPLO['iva'],
                self::EJEMPLO['inc'], self::EJEMPLO['ica'], self::EJEMPLO['total'],
                self::EJEMPLO['nitEmisor'], self::EJEMPLO['adquiriente'],
                self::EJEMPLO['pin'], self::EJEMPLO['ambiente'],
            ),
        );
    }

    public function test_cualquier_dato_distinto_da_otro_cude(): void
    {
        foreach ([
            'numero' => '8110007872',
            'fecha' => '2019-01-13',
            'total' => 5950.01,
            'nitEmisor' => '900373077',
            'adquiriente' => '8355991',
            'ambiente' => '2',
        ] as $campo => $valor) {
            $this->assertNotSame(
                self::CUDE_ESPERADO,
                $this->calcular([$campo => $valor]),
                "Cambiar {$campo} no cambió el CUDE.",
            );
        }
    }

    public function test_es_un_sha384(): void
    {
        $this->assertSame(96, strlen($this->calcular()));
    }
}
