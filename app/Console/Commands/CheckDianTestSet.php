<?php

namespace App\Console\Commands;

use App\Billing\Dian\Transport\DianTestSetTransport;
use App\Billing\Dian\Transport\SoapDianTransport;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pregunta a la DIAN cómo quedó el set de pruebas.
 *
 *     php artisan dian:estado-set --empresa=3
 *     php artisan dian:estado-set --empresa=3 --zipkey=abc123
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * `dian:set-de-pruebas` entrega el lote y recibe una `ZipKey`. Eso es
 * todo lo que se sabía: entregado. Si la DIAN rechazaba un documento,
 * no había forma de enterarse desde gestISP —ni de cuál, ni de por
 * qué—, y corregir se convertía en mirar los diez XML a ver cuál tenía
 * la culpa.
 *
 * Esto presenta la ZipKey a `GetStatusZip` y saca el veredicto del
 * lote y, cuando viene, el de cada documento.
 *
 * ENTREGADO NO ES APROBADO, Y PROCESADO TAMPOCO
 * ---------------------------------------------
 * El servicio contesta con un código de proceso («00» = procesado) que
 * es distinto del veredicto. Por eso se exige también el `IsValid`
 * antes de decir que está aprobado: confundirlos haría creer que la
 * habilitación está hecha cuando solo está leída.
 *
 * SIEMPRE GUARDA LA RESPUESTA CRUDA
 * ---------------------------------
 * Esta operación no se había usado nunca contra el servicio real. El
 * lector entiende las etiquetas conocidas, pero lo que no reconozca no
 * se lo inventa: el XML entero queda en un archivo para poder afinarlo
 * con una respuesta de verdad en la mano. Es barato y evita deducir la
 * forma de un servicio ajeno, que en este proyecto ya salió caro.
 */
class CheckDianTestSet extends Command
{
    protected $signature = 'dian:estado-set
                            {--empresa= : La empresa que se habilita}
                            {--zipkey= : La ZipKey a consultar, si no es la última guardada}';

    protected $description = 'Consulta a la DIAN el resultado del set de pruebas entregado';

    public function handle(DianTestSetTransport $transporte): int
    {
        $empresa = Company::find($this->option('empresa'));

        if (!$empresa) {
            $this->error('Indique una empresa existente: --empresa=ID');

            return self::FAILURE;
        }

        $configuracion = $empresa->dianConfiguration;
        $zipKey = trim((string) $this->option('zipkey')) ?: $configuracion?->test_set_zip_key;

        if (blank($zipKey)) {
            $this->error('No hay ninguna ZipKey que consultar.');
            $this->line('La devuelve el envío del set: <options=bold>php artisan dian:set-de-pruebas --empresa='
                . $empresa->id . '</>');
            $this->line('Si la tiene de un envío anterior, pásela con --zipkey=');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Consultando el set de %s (ZipKey %s)...',
            $empresa->nombreVisible(),
            $zipKey,
        ));

        $respuesta = $transporte->consultarSet($zipKey, $configuracion?->endpoint_override);

        if ($respuesta->resultado !== TransmissionResult::ACEPTADO) {
            $this->error('No se pudo consultar:');

            foreach ($respuesta->errores as $error) {
                $this->line('  · ' . $error);
            }

            return self::FAILURE;
        }

        $donde = $this->guardarLaRespuesta($empresa, $zipKey, (string) $respuesta->respuesta);
        $estado = SoapDianTransport::interpretarEstadoDelSet((string) $respuesta->respuesta);

        $this->mostrar($estado, $empresa);

        $this->newLine();
        $this->line('Respuesta completa: <options=bold>' . $donde . '</>');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function mostrar(array $estado, Company $empresa): void
    {
        $this->newLine();

        if ($estado['codigo'] !== null) {
            $this->line('Código: <options=bold>' . $estado['codigo'] . '</> ' . ($estado['descripcion'] ?? ''));
        }

        if ($estado['mensaje']) {
            $this->line($estado['mensaje']);
        }

        if ($estado['documentos'] !== []) {
            $this->newLine();
            $this->table(
                ['Documento', '¿Válido?', 'Estado'],
                collect($estado['documentos'])->map(fn (array $documento) => [
                    $documento['archivo'] ?? '—',
                    $documento['valido'] ?? '—',
                    $documento['descripcion'] ?? '—',
                ])->all(),
            );
        }

        if ($estado['errores'] !== []) {
            $this->newLine();
            $this->warn('La DIAN reporta:');

            foreach ($estado['errores'] as $error) {
                $this->line('  · ' . $error);
            }
        }

        $this->newLine();

        if ($estado['aprobado']) {
            $this->info('El set está APROBADO.');
            $this->line('Encienda producción con: <options=bold>php artisan dian:habilitar --empresa='
                . $empresa->id . '</>');

            return;
        }

        $this->warn('El set TODAVÍA NO está aprobado.');
        $this->line('Corrija lo que la DIAN reporta, vuelva a emitir esos documentos');
        $this->line('y mande el set otra vez con: <options=bold>php artisan dian:set-de-pruebas --empresa='
            . $empresa->id . '</>');
    }

    /**
     * Deja la respuesta entera en disco.
     *
     * Con la hora en el nombre: consultar el mismo set varias veces es
     * lo normal —se consulta hasta que la DIAN se decide—, y pisar la
     * anterior borraría justo la comparación que hace falta para ver
     * qué cambió.
     */
    private function guardarLaRespuesta(Company $empresa, string $zipKey, string $xml): string
    {
        $ruta = sprintf(
            'dian/sets/%d/%s-%s.xml',
            $empresa->id,
            now()->format('Ymd-His'),
            substr($zipKey, 0, 12),
        );

        Storage::disk('local')->put($ruta, $xml);

        return Storage::disk('local')->path($ruta);
    }
}
