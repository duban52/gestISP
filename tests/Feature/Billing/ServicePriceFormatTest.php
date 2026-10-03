<?php

namespace Tests\Feature\Billing;

use App\Models\Service;

/**
 * El precio de un servicio, escrito como se escribe aquí.
 *
 * «27.731,09» es un precio normal en Colombia: el punto separa los
 * miles y la coma los decimales. El formulario lo rechazaba con un
 * «base price debe ser un número», porque PHP lee ese texto como 27
 * y `numeric` no lo deja pasar.
 *
 * Lo que fija esta prueba no es solo que entre, sino que entre con
 * el valor CORRECTO: aceptarlo y guardar 27 sería mucho peor que
 * rechazarlo — se factura mal y nadie se entera hasta que el cliente
 * reclama.
 */
class ServicePriceFormatTest extends BillingTestCase
{
    private function crear(string $precio, string $iva = '19')
    {
        return $this->post(route('services.store'), [
            'branch_id' => $this->branch->id,
            'name' => 'TV por suscripción',
            'base_price' => $precio,
            'tax_percentage' => $iva,
        ]);
    }

    public function test_acepta_el_precio_con_separador_de_miles_y_decimales(): void
    {
        $this->crear('27.731,09')->assertSessionHasNoErrors();

        $servicio = Service::where('name', 'TV por suscripción')->firstOrFail();

        $this->assertEqualsWithDelta(27731.09, (float) $servicio->base_price, 0.001);
    }

    /** @dataProvider formatos */
    public function test_entiende_las_formas_en_que_se_escribe_el_mismo_precio(string $escrito): void
    {
        $this->crear($escrito)->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(
            27731.09,
            (float) Service::where('name', 'TV por suscripción')->firstOrFail()->base_price,
            0.001,
        );
    }

    public static function formatos(): array
    {
        return [
            'colombiano' => ['27.731,09'],
            'solo coma decimal' => ['27731,09'],
            'punto decimal' => ['27731.09'],
            'ingles' => ['27,731.09'],
            'con el signo de peso' => ['$ 27.731,09'],
        ];
    }

    public function test_un_precio_redondo_con_miles_no_se_lee_como_decimales(): void
    {
        // La trampa: «45.000» son cuarenta y cinco mil, no cuarenta y
        // cinco. Es el error que factura por una milésima.
        $this->crear('45.000')->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(
            45000,
            (float) Service::where('name', 'TV por suscripción')->firstOrFail()->base_price,
            0.001,
        );
    }

    public function test_lo_que_no_es_un_precio_se_sigue_rechazando(): void
    {
        $this->crear('gratis')->assertSessionHasErrors('base_price');

        $this->assertSame(0, Service::where('name', 'TV por suscripción')->count());
    }

    public function test_el_formulario_ya_no_pide_el_iva_como_decimal(): void
    {
        // La etiqueta decía «si es el 19%, ingrese 0.19» y el sistema
        // divide entre 100: quien le hiciera caso facturaba un 0,19%
        // de IVA, y la validación lo dejaba pasar sin decir nada.
        $this->get(route('services.create'))
            ->assertOk()
            ->assertDontSee('ingrese 0.19')
            ->assertSee('para el 19%', false);
    }

    public function test_el_iva_se_guarda_como_porcentaje(): void
    {
        $this->crear('50000', '19')->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(
            19,
            (float) Service::where('name', 'TV por suscripción')->firstOrFail()->tax_percentage,
            0.001,
        );
    }
}
