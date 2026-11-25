<?php

namespace Tests\Unit;

use App\Models\NapBox;
use PHPUnit\Framework\TestCase;

/** Una caja se muestra por el nombre que le puso el usuario; el código es secundario. */
class NapBoxEtiquetaTest extends TestCase
{
    public function test_con_nombre_se_muestra_el_nombre_y_el_codigo_al_lado(): void
    {
        $caja = new NapBox(['code' => 'CN-02076', 'name' => 'CN002']);

        $this->assertSame('CN002', $caja->etiqueta);
        $this->assertSame('CN-02076', $caja->etiqueta_secundaria);
    }

    public function test_sin_nombre_se_muestra_el_codigo_y_nada_al_lado(): void
    {
        $caja = new NapBox(['code' => 'CN-02076', 'name' => null]);

        $this->assertSame('CN-02076', $caja->etiqueta);
        $this->assertNull($caja->etiqueta_secundaria);
    }
}
