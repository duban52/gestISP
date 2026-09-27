<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Convierte un documento de `docs/` en PDF.
 *
 *     php artisan docs:pdf Facturacion
 *     php artisan docs:pdf Facturacion --abrir
 *
 * POR QUÉ UN COMANDO Y NO UN PDF HECHO A MANO
 * -------------------------------------------
 * Porque el manual cambia. Un PDF armado una vez y guardado se queda
 * describiendo un sistema que ya no existe, y nadie lo nota: el papel
 * no avisa de que está viejo. Con esto hay UNA fuente —el `.md` del
 * repositorio, que se revisa en el mismo commit que el código— y el
 * PDF es una salida que se vuelve a sacar en un segundo.
 *
 * NO HACE FALTA INSTALAR NADA
 * ---------------------------
 * CommonMark y dompdf ya están en el proyecto: el primero porque
 * Laravel lo trae, el segundo porque es con el que se imprimen las
 * facturas. Traer una herramienta nueva para esto habría sido pagar
 * una dependencia por algo que ya estaba pagado.
 */
class DocsToPdf extends Command
{
    protected $signature = 'docs:pdf
                            {documento : Nombre del archivo en docs/, sin la extensión}
                            {--abrir : Abre el PDF al terminar}';

    protected $description = 'Convierte un documento de docs/ en PDF';

    public function handle(): int
    {
        $nombre = str_replace(['..', '/', '\\'], '', (string) $this->argument('documento'));
        $origen = base_path('docs/' . $nombre . '.md');

        if (!is_file($origen)) {
            $this->error('No existe docs/' . $nombre . '.md');
            $this->line('Disponibles: ' . collect(glob(base_path('docs/*.md')))
                ->map(fn ($ruta) => basename($ruta, '.md'))
                ->implode(', '));

            return self::FAILURE;
        }

        $markdown = file_get_contents($origen);
        $destino = base_path('docs/' . $nombre . '.pdf');

        // La tabla NO viene en el CommonMark de serie, y este manual es
        // medio tablas: sin la extensión saldrían como párrafos de
        // barras verticales.
        $entorno = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $entorno->addExtension(new CommonMarkCoreExtension());
        $entorno->addExtension(new TableExtension());

        $cuerpo = (new MarkdownConverter($entorno))->convert($markdown)->getContent();

        // Fuera el primer encabezado: ya es la portada, y repetirlo
        // dentro gasta una hoja entera para decir lo mismo.
        $cuerpo = preg_replace('/^\s*<h1>.*?<\/h1>/s', '', $cuerpo, 1);

        $pdf = Pdf::loadView('docs.pdf', [
            'titulo' => $this->tituloDe($markdown, $nombre),
            'cuerpo' => $cuerpo,
            'generado' => now()->translatedFormat('d \d\e F \d\e Y'),
        ]);

        $pdf->setPaper('letter', 'portrait');
        file_put_contents($destino, $pdf->output());

        $this->info('Listo: ' . $destino);

        if ($this->option('abrir')) {
            // Windows, que es donde se desarrolla. En otro sistema no
            // hace nada y tampoco estorba.
            @exec('start "" "' . $destino . '"');
        }

        return self::SUCCESS;
    }

    /** El primer encabezado del documento, que es su título. */
    private function tituloDe(string $markdown, string $respaldo): string
    {
        return preg_match('/^#\s+(.+)$/m', $markdown, $m)
            ? trim($m[1])
            : $respaldo;
    }
}
