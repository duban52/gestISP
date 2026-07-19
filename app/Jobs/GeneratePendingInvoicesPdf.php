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

            // Generar y almacenar los códigos de barras para cada factura
            foreach ($invoices as $invoice) {
                $code = '0100' . $invoice->id . '000000' . $invoice->total; // Generar código único
                $barcodeData = DNS1DFacade::getBarcodePNG($code, 'C128'); // Generar código de barras en formato PNG

                // Definir la ruta de almacenamiento del código de barras
                $barcodePath = "barcodes/{$code}.png";
                Storage::disk('public')->put($barcodePath, base64_decode($barcodeData));

                // Guardar la URL del código de barras para su uso en la vista
                $barcodeUrls[$invoice->id] = asset("storage/{$barcodePath}");
            }

            // Generar el PDF con la vista y los datos necesarios
            $pdf = Pdf::loadView('gestisp.invoices.pending_invoices_pdf', compact('invoices', 'barcodeUrls', 'code'));

            // Configurar tamaño media carta (5.5" x 8.5") en puntos
            $pdf->setPaper([0, 0, 612.00, 419.53], 'portrait');
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
