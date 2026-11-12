<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Ninguna vista puede tener un formulario dentro de otro.
 *
 * LO QUE PASÓ
 * -----------
 * En la edición de clientes, el formulario de ELIMINAR estaba escrito
 * dentro del de actualizar. HTML no admite formularios anidados: el
 * navegador descarta la etiqueta del interior y sus campos pasan a
 * ser del exterior. El formulario de actualizar acababa enviando dos
 * campos `_method` —PUT y DELETE—, PHP se queda con el último, y
 * Laravel enrutaba la petición al borrado.
 *
 * **El botón «Actualizar cliente» eliminaba al cliente.** Se comprobó
 * en un navegador: un solo formulario en la página y
 * `_method = ["PUT", "DELETE"]` saliendo de los dos botones.
 *
 * En el historial de caja el mismo defecto tenía otro síntoma: el
 * `</form>` interior cerraba el formulario de fuera, y el botón
 * «Filtrar» quedaba suelto y no hacía nada.
 *
 * POR QUÉ UNA PRUEBA ESTÁTICA
 * ---------------------------
 * Ninguna prueba de las que hacen peticiones lo habría visto nunca:
 * mandan los datos directamente al servidor, sin pasar por el
 * analizador de HTML de un navegador, que es donde ocurre. Lo único
 * que lo detecta es leer las plantillas. No necesita base de datos ni
 * arrancar la aplicación, así que cuesta milisegundos.
 *
 * LO QUE NO VE
 * ------------
 * Un formulario que llegue anidado a través de un `@include`: el
 * parcial y quien lo incluye son archivos distintos. Cubre el caso
 * real —los dos que había estaban escritos en la misma plantilla— y
 * no pretende más.
 */
class FormulariosAnidadosTest extends TestCase
{
    public function test_ninguna_vista_anida_un_formulario_dentro_de_otro(): void
    {
        $raiz = dirname(__DIR__, 3) . '/resources/views';
        $anidados = [];

        // Con los iteradores de PHP y no con la fachada File: esta
        // prueba no arranca la aplicación, y sin contenedor no hay
        // fachadas. Es lo que le permite correr en milisegundos.
        $archivos = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS),
        );

        $revisadas = 0;

        foreach ($archivos as $archivo) {
            if (!str_ends_with($archivo->getFilename(), '.blade.php')) {
                continue;
            }

            $revisadas++;
            $linea = $this->lineaDelFormularioAnidado(file_get_contents($archivo->getPathname()));

            if ($linea !== null) {
                $relativa = substr($archivo->getPathname(), strlen($raiz) + 1);

                $anidados[] = str_replace(DIRECTORY_SEPARATOR, '/', $relativa) . ':' . $linea;
            }
        }

        // Si no encontró plantillas, no revisó nada: que no dé verde
        // por haber mirado en la carpeta equivocada.
        $this->assertGreaterThan(100, $revisadas, 'No se encontraron las plantillas a revisar.');

        $this->assertSame(
            [],
            $anidados,
            "Hay formularios dentro de otros formularios. El navegador descarta el interior y "
            . "sus campos —incluido un `_method` DELETE— se envían con el exterior:\n  "
            . implode("\n  ", $anidados),
        );
    }

    /**
     * La línea donde se abre un formulario con otro todavía abierto,
     * o null si la plantilla está bien.
     */
    private function lineaDelFormularioAnidado(string $plantilla): ?int
    {
        // Un <form> dentro de un comentario no es un formulario. Se
        // sustituyen por saltos de línea equivalentes para que el
        // número de línea que se informa siga siendo el del archivo.
        $sinComentarios = preg_replace_callback(
            '/\{\{--.*?--\}\}|<!--.*?-->/s',
            fn ($m) => str_repeat("\n", substr_count($m[0], "\n")),
            $plantilla,
        );

        $abiertos = 0;

        preg_match_all('/<form\b|<\/form>/i', $sinComentarios, $etiquetas, PREG_OFFSET_CAPTURE);

        foreach ($etiquetas[0] as [$etiqueta, $posicion]) {
            if (strtolower($etiqueta) === '</form>') {
                $abiertos = max(0, $abiertos - 1);

                continue;
            }

            if (++$abiertos > 1) {
                return substr_count($sinComentarios, "\n", 0, $posicion) + 1;
            }
        }

        return null;
    }

    /**
     * La propia comprobación tiene que saber fallar.
     *
     * Una prueba estática que nunca detecta nada da la misma luz verde
     * que una que funciona. Aquí se le enseña la estructura exacta que
     * tenía la edición de clientes y se exige que la señale.
     */
    public function test_la_comprobacion_reconoce_el_caso_que_borraba_clientes(): void
    {
        $comoEstaba = <<<'BLADE'
<form method="POST" action="/clients/1">
    @method('PUT')
    <input type="submit" value="Actualizar cliente">
    <form action="/clients/1" method="POST">
        @method('DELETE')
        <input type="submit" value="Eliminar">
    </form>
</form>
BLADE;

        $this->assertSame(4, $this->lineaDelFormularioAnidado($comoEstaba));

        $bien = <<<'BLADE'
<form method="POST" action="/clients/1">
    @method('PUT')
</form>
{{-- <form> dentro de un comentario no cuenta --}}
<form action="/clients/1" method="POST">
    @method('DELETE')
</form>
BLADE;

        $this->assertNull($this->lineaDelFormularioAnidado($bien));
    }
}
