<?php

namespace Tests;

use App\Models\FiscalCatalog;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Los codigos minimos que la aplicacion da por sentados.
     *
     * POR QUE ESTAN AQUI Y NO EN CADA PRUEBA
     * --------------------------------------
     * Desde la fase 8, crear un cliente exige un tipo de documento
     * VALIDADO CONTRA EL CATALOGO. Sin catalogo sembrado, cualquier
     * prueba que cree un cliente por HTTP falla con «el tipo de
     * documento seleccionado no es valido» — un mensaje que no dice
     * nada de que el problema sea el andamiaje.
     *
     * Son diecisiete filas de tres listas cortas; sembrarlas cuesta
     * microsegundos. Los catalogos largos —1.122 municipios, 1.093
     * unidades de medida— NO se siembran aqui: la prueba que los
     * necesite los siembra ella.
     *
     * Se siembran los codigos reales de la DIAN, no inventados, para
     * que una prueba que compruebe «13 es cedula de ciudadania» diga
     * la verdad.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrarCatalogosMinimos();
    }

    protected function sembrarCatalogosMinimos(): void
    {
        // Las pruebas unitarias no montan base de datos.
        if (!Schema::hasTable('fiscal_catalogs')) {
            return;
        }

        if (FiscalCatalog::de(FiscalCatalog::TIPO_DOCUMENTO)->exists()) {
            return;
        }

        $listas = [
            FiscalCatalog::TIPO_DOCUMENTO => [
                '11' => 'Registro civil',
                '12' => 'Tarjeta de identidad',
                '13' => 'Cédula de ciudadanía',
                '21' => 'Tarjeta de extranjería',
                '22' => 'Cédula de extranjería',
                '31' => 'NIT',
                '41' => 'Pasaporte',
                '42' => 'Documento de identificación extranjero',
                '50' => 'NIT de otro país',
                '91' => 'NUIP *',
            ],
            FiscalCatalog::TIPO_ORGANIZACION => [
                '1' => 'Persona Jurídica y asimiladas',
                '2' => 'Persona Natural y asimiladas',
            ],
            FiscalCatalog::RESPONSABILIDAD => [
                'O-13' => 'Gran contribuyente',
                'O-15' => 'Autorretenedor',
                'O-23' => 'Agente de retención IVA',
                'O-47' => 'Régimen simple de tributación',
                'R-99-PN' => 'No aplica - Otros',
            ],
        ];

        $filas = [];
        $ahora = now();

        foreach ($listas as $catalogo => $codigos) {
            $orden = 0;

            foreach ($codigos as $codigo => $nombre) {
                $filas[] = [
                    'catalog' => $catalogo,
                    'code' => $codigo,
                    'name' => $nombre,
                    'active' => true,
                    'sort_order' => $orden++,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        FiscalCatalog::insert($filas);
    }
}
