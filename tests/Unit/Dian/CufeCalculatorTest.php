<?php

namespace Tests\Unit\Dian;

use App\Billing\Dian\CufeCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * El CUFE, contra el ejemplo resuelto de la propia DIAN.
 *
 * POR QUÉ ESTA PRUEBA VALE MÁS QUE LAS DEMÁS
 * ------------------------------------------
 * Casi todo lo demás del sistema se puede comprobar razonando: si el
 * código hace lo que dice, la prueba pasa. El CUFE no. Es un hash: o
 * sale exactamente el mismo que espera la DIAN, o el documento se
 * rechaza, y no hay forma de saber cuál de los catorce campos está mal
 * mirando el resultado.
 *
 * El anexo técnico 1.9 publica en §11.2.1 un ejemplo con sus datos de
 * entrada y su CUFE. Esta prueba lo reproduce. Es la única
 * verificación de extremo a extremo posible sin una resolución real.
 *
 * UNA TRAMPA DEL PROPIO ANEXO
 * ---------------------------
 * El PDF imprime la concatenación de su ejemplo con el guion del huso
 * horario perdido —`10:53:1005:00` en vez de `10:53:10-05:00`—,
 * seguramente al maquetar. Quien copie esa cadena literalmente obtiene
 * un hash distinto del que el mismo documento da por bueno. Se
 * comprueban las dos aquí para dejarlo dicho.
 *
 * Es Unit y no Feature a propósito: no toca base de datos, y así corre
 * en milisegundos y se puede ejecutar en cada cambio.
 */
class CufeCalculatorTest extends TestCase
{
    /** Los datos del ejemplo del anexo, §11.2.1. */
    private const EJEMPLO = [
        'numero' => '323200000129',
        'fecha' => '2019-01-16',
        'hora' => '10:53:10-05:00',
        'valorSinImpuestos' => 1500000.00,
        'iva' => 285000.00,
        'inc' => 0.00,
        'ica' => 0.00,
        'total' => 1785000.00,
        'nitEmisor' => '700085371',
        'adquiriente' => '800199436',
        'claveTecnica' => '693ff6f2a553c3646a063436fd4dd9ded0311471',
        'ambiente' => '1',
    ];

    private const CUFE_ESPERADO =
        '8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4';

    private function calcular(array $cambios = []): string
    {
        $d = array_merge(self::EJEMPLO, $cambios);

        return (new CufeCalculator())->calcular(
            $d['numero'], $d['fecha'], $d['hora'],
            $d['valorSinImpuestos'], $d['iva'], $d['inc'], $d['ica'], $d['total'],
            $d['nitEmisor'], $d['adquiriente'], $d['claveTecnica'], $d['ambiente'],
        );
    }

    public function test_reproduce_el_ejemplo_publicado_por_la_dian(): void
    {
        $this->assertSame(self::CUFE_ESPERADO, $this->calcular());
    }

    public function test_la_cadena_lleva_el_guion_del_huso_horario(): void
    {
        // La trampa del PDF. Si el guion se pierde, el hash cambia — y
        // no hay forma de saberlo mirando el resultado.
        $cadena = (new CufeCalculator())->cadena(
            self::EJEMPLO['numero'], self::EJEMPLO['fecha'], self::EJEMPLO['hora'],
            self::EJEMPLO['valorSinImpuestos'], self::EJEMPLO['iva'],
            self::EJEMPLO['inc'], self::EJEMPLO['ica'], self::EJEMPLO['total'],
            self::EJEMPLO['nitEmisor'], self::EJEMPLO['adquiriente'],
            self::EJEMPLO['claveTecnica'], self::EJEMPLO['ambiente'],
        );

        $this->assertStringContainsString('10:53:10-05:00', $cadena);
        $this->assertNotSame(self::CUFE_ESPERADO, hash('sha384', str_replace('-05:00', '05:00', $cadena)));
    }

    // ==================== Lo que cambia el CUFE ====================

    public function test_cualquier_dato_distinto_da_otro_cufe(): void
    {
        // Es la propiedad que lo hace útil como control: no se puede
        // alterar un dato de la factura sin que el CUFE deje de cuadrar.
        foreach ([
            'numero' => '323200000130',
            'fecha' => '2019-01-17',
            'total' => 1785000.01,
            'nitEmisor' => '700085372',
            'adquiriente' => '800199437',
            'ambiente' => '2',
        ] as $campo => $valor) {
            $this->assertNotSame(
                self::CUFE_ESPERADO,
                $this->calcular([$campo => $valor]),
                "Cambiar {$campo} no cambió el CUFE.",
            );
        }
    }

    public function test_sin_la_clave_tecnica_no_sale_el_cufe(): void
    {
        // Es lo que impide falsificarlo conociendo solo la factura: la
        // clave no viaja en ningún XML.
        $this->assertNotSame(self::CUFE_ESPERADO, $this->calcular(['claveTecnica' => '']));
    }

    // ==================== El formato de los importes ====================

    public function test_los_importes_se_truncan_y_no_se_redondean(): void
    {
        // El anexo dice «truncados». Redondear 1500000.005 a
        // 1500000.01 en vez de 1500000.00 produce un CUFE que la DIAN
        // rechaza, y `number_format` a secas redondea.
        $truncado = $this->calcular(['valorSinImpuestos' => 1500000.004]);
        $exacto = $this->calcular(['valorSinImpuestos' => 1500000.00]);

        $this->assertSame($exacto, $truncado);

        // Y por arriba también: .009 trunca a .00, no sube a .01
        $this->assertSame($exacto, $this->calcular(['valorSinImpuestos' => 1500000.009]));
    }

    public function test_el_documento_va_sin_puntuacion(): void
    {
        // La mayoría de los CUFE que no cuadran vienen de aquí: un NIT
        // escrito con puntos en la ficha del cliente.
        $this->assertSame(self::CUFE_ESPERADO, $this->calcular(['nitEmisor' => '700.085.371']));
        $this->assertSame(self::CUFE_ESPERADO, $this->calcular(['adquiriente' => '800-199-436']));
    }

    public function test_los_impuestos_ausentes_van_en_cero(): void
    {
        // El anexo: «Si no está referenciado el impuesto, este valor se
        // representa con 0.00». No se omite el campo: se pone a cero.
        $cadena = (new CufeCalculator())->cadena(
            self::EJEMPLO['numero'], self::EJEMPLO['fecha'], self::EJEMPLO['hora'],
            self::EJEMPLO['valorSinImpuestos'], self::EJEMPLO['iva'], 0.0, 0.0,
            self::EJEMPLO['total'], self::EJEMPLO['nitEmisor'], self::EJEMPLO['adquiriente'],
            self::EJEMPLO['claveTecnica'], self::EJEMPLO['ambiente'],
        );

        $this->assertStringContainsString('040.00030.00', $cadena);
    }

    // ==================== La hora ====================

    public function test_la_hora_sale_con_su_huso(): void
    {
        $momento = Carbon::parse('2019-01-16 10:53:10', 'America/Bogota');

        $this->assertSame('10:53:10-05:00', (new CufeCalculator())->horaDe($momento));
    }
}
