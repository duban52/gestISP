<?php

namespace App\Console\Commands;

use App\Models\FiscalCatalog;
use Illuminate\Console\Command;
use RuntimeException;
use SimpleXMLElement;

/**
 * Convierte los `.gc` de la DIAN en datos versionados del proyecto.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Los catálogos vienen en un paquete de la DIAN —treinta y seis
 * archivos *genericode*, dentro de una carpeta con PDFs y esquemas que
 * está en `.gitignore` y que en el servidor NO existe.
 *
 * Sembrar directamente desde esa carpeta ataría el despliegue a tener
 * el paquete a mano. Así que este comando hace la conversión **una
 * vez, en desarrollo**, y deja el resultado en `database/data/dian/`
 * como JSON, que sí se versiona: los catálogos son datos de
 * referencia y su sitio es el control de versiones.
 *
 *     php artisan dian:importar-catalogos --dry-run
 *     php artisan dian:importar-catalogos
 *     php artisan db:seed --class=FiscalCatalogSeeder
 *
 * Cuando la DIAN publique una versión nueva se repite el primer paso
 * con los archivos nuevos y se revisa el diff en git — que es
 * exactamente donde se quiere ver un cambio de catálogo fiscal.
 *
 * SOBRE LA CODIFICACIÓN
 * ---------------------
 * Los archivos son UTF-8 de verdad, aunque una consola de Windows los
 * enseñe mal. Se leen como UTF-8 y no se «arregla» nada: reinterpretar
 * bytes correctos como Latin-1 es lo que convierte «Medellín» en
 * «MedellÃ­n».
 */
class ImportFiscalCatalogs extends Command
{
    protected $signature = 'dian:importar-catalogos
                            {--desde= : Carpeta con los .gc (por defecto, la del paquete de ayuda)}
                            {--dry-run : Muestra qué se importaría, sin escribir}';

    protected $description = 'Convierte los .gc de la DIAN en los JSON versionados de database/data/dian';

    private const CARPETA_POR_DEFECTO = 'ayuda facturacion dian/Listas de valores';

    /**
     * Qué archivo alimenta qué catálogo.
     *
     * Es una lista explícita y no «todos los .gc de la carpeta» a
     * propósito: hay archivos que no se usan —transporte, salud,
     * reclamos— y sembrarlos sería meter mil filas que nadie consulta
     * y que habría que explicar.
     */
    private const CATALOGOS = [
        'TipoIdFiscal-2.1.gc' => FiscalCatalog::TIPO_DOCUMENTO,
        'TipoOrganizacion-2.1.gc' => FiscalCatalog::TIPO_ORGANIZACION,
        'TipoResponsabilidad-2.1.gc' => FiscalCatalog::RESPONSABILIDAD,
        'Departamentos-2.1.gc' => FiscalCatalog::DEPARTAMENTO,
        'Municipio-2.1.gc' => FiscalCatalog::MUNICIPIO,
        'Paises-2.1.gc' => FiscalCatalog::PAIS,
        'FormasPago-2.1.gc' => FiscalCatalog::FORMA_PAGO,
        'MediosPago-2.1.gc' => FiscalCatalog::MEDIO_PAGO,
        'TarifaImpuestoIVA-2.1.gc' => FiscalCatalog::TARIFA_IVA,
        'TipoImpuesto-2.1.gc' => FiscalCatalog::TIPO_IMPUESTO,
        'UnidadesMedida-2.1.gc' => FiscalCatalog::UNIDAD_MEDIDA,
        'TipoMoneda-2.1.gc' => FiscalCatalog::MONEDA,
        'TipoOperacionF-2.1.gc' => FiscalCatalog::TIPO_OPERACION_FACTURA,
        'ConceptoNotaCredito-2.1.gc' => FiscalCatalog::CONCEPTO_NOTA_CREDITO,
        'ConceptoNotaDebito-2.1.gc' => FiscalCatalog::CONCEPTO_NOTA_DEBITO,
        'TipoCodigoProducto-2.1.gc' => FiscalCatalog::TIPO_CODIGO_PRODUCTO,
        'TipoDocumento-2.1.gc' => FiscalCatalog::TIPO_DOCUMENTO_ELECTRONICO,
        'TipoAmbiente-2.1.gc' => FiscalCatalog::AMBIENTE,
    ];

    public function handle(): int
    {
        $carpeta = rtrim($this->option('desde') ?: base_path(self::CARPETA_POR_DEFECTO), '/\\');
        $simulacro = (bool) $this->option('dry-run');

        if (!is_dir($carpeta)) {
            $this->error("No existe la carpeta: {$carpeta}");
            $this->line('Indique dónde están los .gc con --desde="ruta".');

            return self::FAILURE;
        }

        $destino = database_path('data/dian');

        if (!$simulacro && !is_dir($destino)) {
            mkdir($destino, 0755, true);
        }

        $filas = [];
        $problemas = [];

        foreach (self::CATALOGOS as $archivo => $catalogo) {
            $ruta = $carpeta . DIRECTORY_SEPARATOR . $archivo;

            if (!is_file($ruta)) {
                $problemas[] = "{$archivo}: no está en la carpeta.";

                continue;
            }

            try {
                $codigos = $this->leer($ruta, $catalogo);
            } catch (RuntimeException $e) {
                $problemas[] = "{$archivo}: " . $e->getMessage();

                continue;
            }

            $filas[] = [$catalogo, $archivo, count($codigos)];

            if (!$simulacro) {
                file_put_contents(
                    $destino . DIRECTORY_SEPARATOR . $catalogo . '.json',
                    json_encode($codigos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                );
            }
        }

        $this->table(['Catálogo', 'Archivo', 'Códigos'], $filas);

        foreach ($problemas as $problema) {
            $this->warn('  · ' . $problema);
        }

        if ($simulacro) {
            $this->newLine();
            $this->comment('SIMULACRO: no se escribió nada. Quite --dry-run para generar los JSON.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Escritos en {$destino}. Revise el diff antes de confirmarlos.");
        $this->comment('Después: php artisan db:seed --class=FiscalCatalogSeeder');

        return $problemas === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Lee un archivo genericode.
     *
     * El formato es de OASIS: `ColumnSet` declara las columnas y cada
     * `Row` trae un `Value` por columna, referenciada por su id. No se
     * asume que las columnas se llamen `code` y `name` —hay listas que
     * usan otros nombres—, se toman las dos primeras declaradas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function leer(string $ruta, string $catalogo): array
    {
        $xml = @simplexml_load_file($ruta);

        if ($xml === false) {
            throw new RuntimeException('no se pudo leer como XML.');
        }

        $columnas = [];

        foreach ($xml->ColumnSet->Column ?? [] as $columna) {
            $columnas[] = (string) $columna['Id'];
        }

        if (count($columnas) < 2) {
            throw new RuntimeException('no declara al menos dos columnas.');
        }

        [$colCodigo, $colNombre] = [$columnas[0], $columnas[1]];

        $codigos = [];
        $orden = 0;

        foreach ($xml->SimpleCodeList->Row ?? [] as $fila) {
            $valores = $this->valoresDe($fila);

            $codigo = trim((string) ($valores[$colCodigo] ?? ''));
            $nombre = trim((string) ($valores[$colNombre] ?? ''));

            if ($codigo === '') {
                continue;
            }

            $extra = array_diff_key($valores, array_flip([$colCodigo, $colNombre]));

            $codigos[] = [
                'code' => $codigo,
                'name' => $nombre !== '' ? $nombre : $codigo,
                'parent_code' => $this->padreDe($catalogo, $codigo),
                'extra' => $extra !== [] ? $extra : null,
                'sort_order' => $orden++,
            ];
        }

        if ($codigos === []) {
            throw new RuntimeException('no trae ningún código.');
        }

        return $codigos;
    }

    /** @return array<string, string> */
    private function valoresDe(SimpleXMLElement $fila): array
    {
        $valores = [];

        foreach ($fila->Value ?? [] as $valor) {
            $valores[(string) $valor['ColumnRef']] = (string) $valor->SimpleValue;
        }

        return $valores;
    }

    /**
     * De qué cuelga un código.
     *
     * Solo los municipios: en el catálogo DANE el código del municipio
     * ya lleva dentro el de su departamento —05001 es Medellín y 05 es
     * Antioquia—, así que se extrae al importar y queda explícito, en
     * vez de tener que recortar la cadena en cada consulta.
     */
    private function padreDe(string $catalogo, string $codigo): ?string
    {
        if ($catalogo !== FiscalCatalog::MUNICIPIO || strlen($codigo) < 3) {
            return null;
        }

        return substr($codigo, 0, 2);
    }
}
