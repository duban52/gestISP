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

    public function __construct($branchId)
    {
        $this->branchId = $branchId;
    }

    public function handle()
    {
        try {
            // Obtener las facturas pendientes de la sucursal específica
            $invoices = Invoice::whereIn('status', [
                    InvoiceStatus::Pendiente->value,
                    InvoiceStatus::PendienteRiesgoCorte->value,
                ])
                ->whereHas('contract.client', function ($query) {
                    $query->where('branch_id', $this->branchId);
                })
                ->with(['contract.client', 'invoice_items'])
                ->get();

            // Registrar información en el log
            Log::info("Generando PDF para facturas pendientes de la sucursal: {$this->branchId}");

            // Eliminar el PDF anterior si existe
            $previousPdf = PdfReport::where('branch_id', $this->branchId)
                ->orderBy('created_at', 'desc')
                ->first();
            if ($previousPdf) {
                Storage::disk('public')->delete($previousPdf->pdf_path);
                Log::info("PDF anterior eliminado: {$previousPdf->pdf_path}");
            }

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

            // Guardar la ruta del PDF en la base de datos
            PdfReport::create([
                'branch_id' => $this->branchId,
                'pdf_path' => $pdfPath,
            ]);

            // Notificar al usuario que el PDF está listo
            $this->notifyUser($pdfPath);

        } catch (\Exception $e) {
            // Registrar el error en el log
            Log::error("Error al generar el PDF: " . $e->getMessage());

            // Relanzar la excepción para que Laravel la maneje
            throw $e;
        }
    }

    protected function notifyUser($pdfPath)
    {
        // Almacenar la ruta del PDF en la sesión para que el frontend pueda acceder a ella
        session()->flash('pdfPath', asset("storage/{$pdfPath}"));
        Log::info('Notificando al usuario sobre el PDF generado: ' . $pdfPath);
    }

}
