<?php

namespace Tests\Unit;

use App\Support\Dinero;
use PHPUnit\Framework\TestCase;

/**
 * Importes escritos por una persona.
 *
 * El caso que lo destapó: un servicio de $27.731,09 — el precio con
 * decimales escrito como se escribe en Colombia— que el formulario
 * rechazaba con «debe ser un número».
 *
 * La trampa de este código es la ambigüedad de `1.500`: mil
 * quinientos para un colombiano, uno coma cinco para un inglés. La
 * regla es contar los dígitos de atrás, y eso es exactamente lo que
 * hay que dejar fijado aquí, porque el día que alguien la «mejore»
 * se convierte en facturas por una milésima de lo que valían.
 */
class DineroTest extends TestCase
{
    /** @dataProvider importes */
    public function test_lee_los_importes_como_los_escribe_la_gente(mixed $escrito, ?float $esperado): void
    {
        $this->assertSame($esperado, Dinero::deTexto($escrito));
    }

    public static function importes(): array
    {
        return [
            'colombiano con decimales' => ['27.731,09', 27731.09],
            'colombiano sin decimales' => ['27.731', 27731.0],
            'solo decimales con coma' => ['27731,09', 27731.09],
            'punto decimal' => ['27731.09', 27731.09],
            'ingles con miles' => ['27,731.09', 27731.09],
            'con signo de peso' => ['$ 27.731,09', 27731.09],
            'con espacios' => ['  45000  ', 45000.0],
            'millones' => ['1.234.567', 1234567.0],
            'millones con decimales' => ['1.234.567,89', 1234567.89],

            // Lo que no se puede resolver sin preguntar: tres dígitos
            // detrás de un punto son miles. `1.500` es mil quinientos.
            'mil quinientos' => ['1.500', 1500.0],
            'uno coma cinco' => ['1,5', 1.5],
            'uno punto cinco' => ['1.5', 1.5],

            'numero de verdad' => [27731.09, 27731.09],
            'entero de verdad' => [45000, 45000.0],
            'cero' => ['0', 0.0],
            'negativo' => ['-5.000', -5000.0],

            'vacio' => ['', null],
            'espacios solos' => ['   ', null],
            'nulo' => [null, null],
            'texto' => ['gratis', null],
        ];
    }

    public function test_positivo_nunca_baja_de_cero(): void
    {
        $this->assertSame(27731.09, Dinero::positivo('27.731,09'));
        $this->assertSame(0.0, Dinero::positivo('-5.000'));
        $this->assertSame(0.0, Dinero::positivo('gratis'));
        $this->assertSame(0.0, Dinero::positivo(null));
    }
}
