<?php

namespace App\Http\Controllers;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\InvoiceVoider;
use App\Billing\Services\MonthlyBillingRun;
use App\Billing\Services\OverdueProcessor;
use App\Jobs\GeneratePendingInvoicesPdf;
use App\Models\Invoice;
use App\Models\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\Facades\DNS1DFacade;

/**
 * Controlador de Facturas
 *
 * Solo maneja HTTP: la lógica de negocio vive en los servicios de
 * app/Billing/Services (MonthlyBillingRun, InvoiceGenerator,
 * OverdueProcessor). Los estados válidos están en
 * App\Billing\Enums\InvoiceStatus.
 */
class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:invoices.index')->only('index');
        $this->middleware('check.permission:invoices.create')->only('create', 'store');
        $this->middleware('check.permission:invoices.edit')->only('edit', 'update');
        $this->middleware('check.permission:invoices.show')->only('show');
        $this->middleware('check.permission:invoices.destroy')->only('destroy', 'voidInvoice');
        $this->middleware('check.permission:invoices.generate')->only('generateInvoices');
        $this->middleware('check.permission:invoices.download-pdf')->only('downloadInvoicePdf');
        $this->middleware('check.permission:invoices.generate_max_pdf')->only('generatePendingInvoicesPdf');
        $this->middleware('check.permission:invoices.check-pdf-status')->only('checkPdfStatus');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(OverdueProcessor $overdueProcessor)
    {
        // Actualizar facturas vencidas primero (en fase 5 esto
        // pasará a un comando programado diario y saldrá del GET)
        $overdueProcessor->markOverdueInvoices();

        $totalPendding = 0;

        // Total pendiente
        if (session()->has('branch_id')) {
            $branchId = session('branch_id');

            $totalPendding = Invoice::whereIn('status', [
                    InvoiceStatus::Pendiente->value,
                    InvoiceStatus::PendienteRiesgoCorte->value,
                ])
                ->whereHas('contract', function ($query) use ($branchId) {
                    $query->where('branch_id', $branchId);
                })
                ->sum('total');
        }

        $branchId = session('branch_id');

        // Cambiar simplePaginate() por get() para DataTables
        $invoices = Invoice::join('contracts', 'invoices.contract_id', '=', 'contracts.id')
            ->join('clients', 'contracts.client_id', '=', 'clients.id')
            ->where('clients.branch_id', $branchId)
            ->where('contracts.branch_id', $branchId)
            ->select('invoices.*')
            ->orderBy('invoices.created_at', 'desc')
            ->get(); // Cambiado de simplePaginate(10) a get()

        return view('gestisp.invoices.index', compact('invoices', 'totalPendding'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Invoice $invoice)
    {
        $invoice->load(['contract.client', 'contract.plan.services', 'invoice_items']);

        $code = '123456789012';
        $barcode = DNS1DFacade::getBarcodeHTML($code, 'C128');

        return view('gestisp.invoices.show', compact('invoice', 'barcode'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Invoice $invoice)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Invoice $invoice)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Invoice $invoice)
    {
        //
    }

    /**
     * Anula una factura (nunca se elimina: cambia a estado Anulada
     * con motivo, usuario y fecha). Las reglas de negocio viven en
     * InvoiceVoider.
     */
    public function voidInvoice(Request $request, Invoice $invoice, InvoiceVoider $voider)
    {
        $validated = $request->validate([
            'void_reason' => 'required|string|max:255',
        ], [
            'void_reason.required' => 'Debe indicar el motivo de la anulación.',
        ]);

        try {
            $voider->void($invoice, $validated['void_reason'], Auth::id());

            return redirect()->route('invoices.index')
                ->with('success-delete', "Factura {$invoice->displayNumber()} anulada correctamente.");

        } catch (\RuntimeException $e) {
            return redirect()->route('invoices.index')->with('error', $e->getMessage());
        }
    }
    /**
     * Ejecuta la corrida de facturacion mensual de la sucursal
     * activa. Toda la logica vive en MonthlyBillingRun /
     * InvoiceGenerator / OverdueProcessor (app/Billing/Services).
     */
    public function generateInvoices(MonthlyBillingRun $billingRun)
    {
        $result = $billingRun->runForBranch(session('branch_id'), Auth::id());

        if ($result['total_contracts'] === 0) {
            return redirect()->route('invoices.index')
                ->with('error', 'No hay contratos para generar facturas.');
        }

        $message = "Proceso completado. Facturas generadas: {$result['generated']}, Omitidas: {$result['skipped']}";

        return redirect()->route('invoices.index')
            ->with('success', $message);
    }


    /**
     * Método para crear PDF
     */
    public function downloadInvoicePdf($id)
    {
        $invoice = Invoice::with(['contract.client', 'invoice_items'])->findOrFail($id);

        $code = '0100' . str_pad($invoice->id, 8, '0', STR_PAD_LEFT) . str_pad($invoice->total * 100, 10, '0', STR_PAD_LEFT);
        $codeString = $code;

        try {
            // Generar la imagen como PNG
            $barcodeData = DNS1DFacade::getBarcodePNG($code, 'C128');

            // Guardar la imagen en un archivo temporal
            $barcodePath = 'barcodes/' . $code . '.png';
            Storage::disk('public')->put($barcodePath, base64_decode($barcodeData));

            $barcodeUrl = asset('storage/' . $barcodePath);

            // Generar el PDF usando la vista
            $pdf = Pdf::loadView('gestisp.invoices.pdf', compact('invoice', 'barcodeUrl', 'codeString'));
            $pdf->setPaper([0, 0, 612.00, 419.53], 'portrait');
            $pdf->getDomPDF()->set_option('isRemoteEnabled', true);

            return $pdf->download('factura_' . $invoice->id . '.pdf');

        } catch (\Exception $e) {
            \Log::error("Error generando PDF para factura {$id}: " . $e->getMessage());
            return redirect()->back()->with('error', 'Error al generar el PDF de la factura.');
        }
    }

    /**
     * PDF Masivo
     */
    public function generatePendingInvoicesPdf()
    {
        $branchId = session('branch_id');

        GeneratePendingInvoicesPdf::dispatch($branchId);

        return redirect()->route('invoices.index')
            ->with('success', 'La generación del PDF de facturas pendientes ha sido encolada. No cierre ni recargue la página hasta ser notificado');
    }

    public function checkPdfStatus(Request $request)
    {
        $branchId = session('branch_id');

        $pdfReport = PdfReport::where('branch_id', $branchId)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($pdfReport) {
            return response()->json([
                'pdfPath' => asset("storage/{$pdfReport->pdf_path}"),
                'timestamp' => $pdfReport->created_at->timestamp,
            ]);
        }

        return response()->json([
            'pdfPath' => null,
            'message' => 'El PDF aún no está listo.',
        ]);
    }
}
