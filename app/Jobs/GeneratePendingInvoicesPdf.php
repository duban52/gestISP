<?php

namespace App\Jobs;

use App\Billing\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PdfReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Milon\Barcode\Facades\DNS1DFacade;
use App\Support\PdfBranding;

class GeneratePendingInvoicesPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $branchId;

    /** El registro que sigue esta generación. */
    protected int $reportId;

    /**
     * Mil facturas tardan. Que el trabajo muera a los 60 segundos por
     * defecto dejaría el informe colgado en «generando» para siempre.
     */
    public $timeout = 1800;

    public function __construct($branchId, int $reportId)
    {
        $this->branchId = $branchId;
        $this->reportId = $reportId;
    }

    public function handle()
    {
        $report = PdfReport::withoutGlobalScopes()->find($this->reportId);

        if (!$report) {
            return;
        }

        try {
            // LA SUCURSAL ES LA DE LA FACTURA, NO LA DEL CLIENTE.
            //
            // Esto filtraba por `contract.client.branch_id`, y un
            // cliente puede tener la sucursal en NULO: son de la
            // empresa, no de una sede concreta. Con eso no coincidía
            // ninguna factura y el PDF salía con una sola página en
            // blanco — sin error, sin aviso, y pareciendo que el
            // generador estaba roto.
            //
            // La factura sabe de qué sucursal es, que además es la que
            // pone el membrete y gasta el consecutivo.
            $invoices = Invoice::whereIn('status', [
                    InvoiceStatus::Pendiente->value,
                    InvoiceStatus::PendienteRiesgoCorte->value,
                ])
                ->whereHas('contract', fn ($q) => $q->where('branch_id', $this->branchId))
                ->with(['contract.client', 'contract.branch', 'invoice_items'])
                ->get();

            Log::info("Generando PDF para facturas pendientes de la sucursal: {$this->branchId}", [
                'informe' => $report->id,
                'facturas' => $invoices->count(),
            ]);

            $report->update([
                'status' => PdfReport::GENERANDO,
                'total' => $invoices->count(),
                'procesadas' => 0,
            ]);

            // EL PDF ANTERIOR NO SE BORRA TODAVÍA.
            //
            // Se borraba aquí, antes de generar nada. Si la generación
            // fallaba —o si no había ni una factura que imprimir—, el
            // usuario se quedaba sin el nuevo Y sin el viejo. Ahora se
            // borra al final, cuando ya hay uno que lo sustituya.
            $anterior = PdfReport::withoutGlobalScopes()
                ->where('branch_id', $this->branchId)
                ->where('id', '!=', $report->id)
                ->whereNotNull('pdf_path')
                ->orderByDesc('created_at')
                ->first();

            // Array para almacenar las rutas de los códigos de barras
            $barcodeUrls = [];

            // ...y el numero legible de cada uno, POR FACTURA.
            //
            // Antes se pasaba a la vista una sola variable $code, que
            // era la del ULTIMO codigo generado en el bucle. La vista
            // la imprime debajo del codigo de barras de CADA factura,
            // asi que todas las paginas mostraban el mismo numero bajo
            // barras distintas: el numero solo coincidia con su barra
            // en la ultima factura del PDF.
            //
            // Y si no habia ninguna factura pendiente, $code no
            // llegaba a existir y compact() reventaba con
            // "Undefined variable $code" — es decir, el PDF masivo
            // fallaba justo cuando no habia nada que imprimir.
            $barcodeCodes = [];

            // CUFE, QR y resolucion de cada factura electronica del
            // lote. Van indexados por id igual que los codigos de
            // barras: un solo bloque comun imprimiria el CUFE de una
            // factura en todas las demas.
            $dianes = [];
            $representacion = app(\App\Billing\Dian\GraphicRepresentation::class);

            // DE DÓNDE SALE LA BARRA DE PROGRESO.
            //
            // Este bucle es el grueso de la espera: por cada factura
            // genera un código de barras, lo escribe en disco y arma
            // su representación gráfica DIAN. El render final de
            // dompdf es un solo paso que no se puede partir, así que
            // el porcentaje cubre la preparación y el último tramo se
            // anuncia aparte. Es honesto: mide lo que de verdad lleva
            // el tiempo, en vez de inventar un porcentaje bonito.
            $hechas = 0;

            // Guardar el avance en CADA factura serían mil escrituras
            // en la tabla para una barra que nadie mira con esa
            // precisión.
            $cadaCuantas = max(1, (int) ceil($invoices->count() / 50));

            // Generar y almacenar los códigos de barras para cada factura
            foreach ($invoices as $invoice) {
                $code = '0100' . $invoice->id . '000000' . $invoice->total; // Generar código único
                $barcodeData = DNS1DFacade::getBarcodePNG($code, 'C128'); // Generar código de barras en formato PNG

                // Definir la ruta de almacenamiento del código de barras
                $barcodePath = "barcodes/{$code}.png";
                Storage::disk('public')->put($barcodePath, base64_decode($barcodeData));

                // Guardar la URL del código de barras para su uso en la vista
                $barcodeUrls[$invoice->id] = asset("storage/{$barcodePath}");
                $barcodeCodes[$invoice->id] = $code;

                // Los datos DIAN de ESTA factura. Null en las internas,
                // y entonces su bloque no se pinta.
                $dianes[$invoice->id] = $representacion->para($invoice);

                $hechas++;

                if ($hechas % $cadaCuantas === 0 || $hechas === $invoices->count()) {
                    $report->update(['procesadas' => $hechas]);
                }
            }

            // El logo por RUTA DE DISCO, una por sucursal. Con
            // `asset()` dompdf tiene que pedirselo al servidor por
            // HTTP, y desde el propio servidor —o desde este job, que
            // corre en cola— eso falla y las facturas salen sin logo.
            $logos = [];

            foreach ($invoices as $invoice) {
                $sucursal = $invoice->contract?->branch;

                if ($sucursal && !array_key_exists($sucursal->id, $logos)) {
                    $logos[$sucursal->id] = PdfBranding::logoPath($sucursal);
                }
            }

            $pdf = Pdf::loadView(
                'gestisp.invoices.pending_invoices_pdf',
                compact('invoices', 'barcodeUrls', 'barcodeCodes', 'dianes', 'logos'),
            );

            // Configurar tamaño media carta (5.5" x 8.5") en puntos
            $pdf->setPaper(PdfBranding::MEDIA_CARTA, 'portrait');
            $pdf->getDomPDF()->set_option('isRemoteEnabled', true);

            // Definir la ruta del PDF generado
            $timestamp = now()->timestamp;
            $pdfPath = "pending_invoices/pending_invoices_{$timestamp}.pdf";
            Storage::disk('public')->put($pdfPath, $pdf->output());

            // Registrar éxito en el log
            Log::info("PDF generado correctamente: {$pdfPath}");

            $report->update([
                'pdf_path' => $pdfPath,
                'status' => PdfReport::LISTO,
                'finished_at' => now(),
            ]);

            // AHORA sí se borra el anterior: ya hay uno que lo
            // sustituye. Borrarlo al principio dejaba al usuario sin
            // ninguno si la generación fallaba.
            if ($anterior) {
                Storage::disk('public')->delete($anterior->pdf_path);
                $anterior->update(['pdf_path' => null]);
                Log::info("PDF anterior eliminado: {$anterior->pdf_path}");
            }

        } catch (\Throwable $e) {
            // EL FALLO SE GUARDA DONDE LO VE QUIEN ESPERA.
            //
            // Antes solo iba al log: la pantalla seguía preguntando
            // «¿hay PDF?», le contestaban que sí con el del mes
            // pasado, y nadie se enteraba de que la generación había
            // reventado.
            $report->update([
                'status' => PdfReport::FALLIDO,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            Log::error('Error al generar el PDF: ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Cuando el trabajo muere sin pasar por el `catch`: se agotó el
     * tiempo, se quedó sin memoria o el worker se reinició. Sin esto
     * el informe se quedaba en «generando» para siempre y la pantalla
     * giraba indefinidamente.
     */
    public function failed(\Throwable $e): void
    {
        PdfReport::withoutGlobalScopes()
            ->where('id', $this->reportId)
            ->where('status', '!=', PdfReport::LISTO)
            ->update([
                'status' => PdfReport::FALLIDO,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
    }
}
