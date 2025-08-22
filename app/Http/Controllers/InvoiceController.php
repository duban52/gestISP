<?php

namespace App\Http\Controllers;

use App\Jobs\GeneratePendingInvoicesPdf;
use App\Models\AditionalCharge;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\Facades\DNS1DFacade;
use Carbon\Carbon;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:invoices.index')->only('index');
        $this->middleware('check.permission:invoices.create')->only('create', 'store');
        $this->middleware('check.permission:invoices.edit')->only('edit', 'update');
        $this->middleware('check.permission:invoices.show')->only('show');
        $this->middleware('check.permission:invoices.destroy')->only('destroy');
        $this->middleware('check.permission:invoices.generate')->only('generateInvoices');
        $this->middleware('check.permission:invoices.download-pdf')->only('downloadInvoicePdf');
        $this->middleware('check.permission:invoices.generate_max_pdf')->only('generatePendingInvoicesPdf');
        $this->middleware('check.permission:invoices.check-pdf-status')->only('checkPdfStatus');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Actualizar facturas vencidas primero
        Invoice::whereIn('status', ['pendiente', 'Pendiente Parcial'])
            ->whereDate('due_date', '<', Carbon::today())
            ->update(['status' => 'vencida']);

        $totalPendding = 0;

        // Total pendiente
        if (session()->has('branch_id')) {
            $branchId = session('branch_id');

            $totalPendding = Invoice::whereIn('status', ['pendiente', 'Pendiente con riesgo de corte'])
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

    public function generateInvoices()
    {
        $branchId = session('branch_id');
        $today = now();

        // Formatear el mes en español
        $month_name = ucfirst($today->translatedFormat('F'));
        $year_month = $today->format('Y') . $today->format('m');

        $startOfMonth = $today->copy()->startOfMonth();
        $endOfMonth = $today->copy()->endOfMonth();

        // Primero, actualizar facturas vencidas
        $this->updateOverdueInvoices();

        // Obtener contratos activos de la sucursal
        $contracts = Contract::with(['client', 'plan.services', 'additionalCharges'])
            ->whereIn('status', ['Activo', 'Pre-suspensión'])
            ->whereHas('client', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId);
            })
            ->get();

        if ($contracts->isEmpty()) {
            return redirect()->route('invoices.index')
                ->with('error', 'No hay contratos para generar facturas.');
        }

        $generatedInvoices = 0;
        $skippedInvoices = 0;

        foreach ($contracts as $contract) {
            try {
                $result = $this->processContractInvoice($contract, $today, $startOfMonth, $endOfMonth, $month_name, $year_month);

                if ($result['generated']) {
                    $generatedInvoices++;
                } else {
                    $skippedInvoices++;
                }
            } catch (\Exception $e) {
                \Log::error("Error generando factura para contrato {$contract->id}: " . $e->getMessage());
                continue;
            }
        }

        $message = "Proceso completado. Facturas generadas: {$generatedInvoices}, Omitidas: {$skippedInvoices}";

        return redirect()->route('invoices.index')
            ->with('success', $message);
    }

    /**
     * Actualizar facturas vencidas y estados de contratos
     */
    private function updateOverdueInvoices()
    {
        // Actualizar facturas vencidas
        Invoice::whereIn('status', ['pendiente', 'Pendiente Parcial'])
            ->whereDate('due_date', '<', Carbon::today())
            ->update(['status' => 'vencida']);

        // Actualizar contratos con múltiples facturas vencidas
        $contracts = Contract::with('invoices')->get();

        foreach ($contracts as $contract) {
            $overdueCount = $contract->invoices()
                ->whereIn('status', ['vencida', 'Vencida'])
                ->count();

            if ($overdueCount >= 2 && $contract->status !== 'Suspendido') {
                $contract->update([
                    'status' => 'Suspendido',
                    'suspension_date' => now(),
                    'overdue_invoices_count' => $overdueCount
                ]);
            } else {
                $contract->update([
                    'overdue_invoices_count' => $overdueCount
                ]);
            }
        }
    }

    /**
     * Procesar facturación para un contrato específico
     */
    private function processContractInvoice($contract, $today, $startOfMonth, $endOfMonth, $month_name, $year_month)
    {
        // Verificar si el contrato está suspendido
        if ($contract->status === 'Suspendido') {
            return ['generated' => false, 'reason' => 'Contract suspended'];
        }

        // Verificar si ya existe factura para este período
        $existingInvoice = Invoice::where('contract_id', $contract->id)
            ->where('billed_year_month', $year_month)
            ->first();

        if ($existingInvoice) {
            return ['generated' => false, 'reason' => 'Invoice already exists for this period'];
        }

        // Obtener facturas vencidas que no han sido incluidas en otras facturas
        $overdueInvoices = Invoice::where('contract_id', $contract->id)
            ->whereIn('status', ['vencida', 'Vencida'])
            ->where('status', '!=', 'Cargada a nueva factura')
            ->get();

        $overdueInvoicesCount = $overdueInvoices->count();

        // Calcular período de facturación y prorrateo
        $billingPeriod = $this->calculateBillingPeriod($contract, $startOfMonth, $endOfMonth);
        $prorateMultiplier = $billingPeriod['prorate_multiplier'];

        // Determinar estado de la factura
        $hasOverdueInvoice = $overdueInvoicesCount > 0;
        $suspensionDate = $hasOverdueInvoice ? $today->copy()->addDays(24) : null;
        $status = $hasOverdueInvoice ? 'Pendiente con riesgo de corte' : 'pendiente';

        // Crear la factura
        $invoice = Invoice::create([
            'contract_id' => $contract->id,
            'user_id' => Auth::id(),
            'issue_date' => $today,
            'due_date' => $today->copy()->addDays(20),
            'billed_period' => $billingPeriod['period_full'],
            'billed_period_short' => $billingPeriod['period_short'],
            'billed_month_name' => $month_name,
            'billed_year_month' => $year_month,
            'suspension_date' => $suspensionDate,
            'tax' => 0,
            'total' => 0,
            'pending_invoice_amount' => 0,
            'status' => $status,
            'service_suspension_warning' => $hasOverdueInvoice,
        ]);

        // Agregar ítems a la factura
        $totals = $this->addInvoiceItems($invoice, $contract, $overdueInvoices, $prorateMultiplier);

        // Actualizar totales de la factura
        $invoice->update([
            'total' => $totals['total'],
            'tax' => $totals['tax'],
            'pending_invoice_amount' => $totals['pending_amount']
        ]);

        // Actualizar estado del contrato si es necesario
        if ($hasOverdueInvoice && $contract->status !== 'Pre-suspensión') {
            $contract->update([
                'status' => 'Pre-suspensión',
                'suspension_warning_date' => $suspensionDate
            ]);
        }

        return ['generated' => true, 'invoice_id' => $invoice->id];
    }

    /**
     * Calcular período de facturación y prorrateo
     */
    private function calculateBillingPeriod($contract, $startOfMonth, $endOfMonth)
    {
        $daysInMonth = $startOfMonth->diffInDays($endOfMonth) + 1;
        $prorateMultiplier = 1;

        $billedPeriod = $startOfMonth->format('d M') . ' al ' . $endOfMonth->format('d M Y');
        $billedPeriodShort = $startOfMonth->format('d') . ' al ' . $endOfMonth->format('d');

        // Verificar si el contrato fue activado después del inicio del mes
        if ($contract->activation_date && $contract->activation_date > $startOfMonth) {
            $activationDate = Carbon::parse($contract->activation_date);

            // Solo prorratear si la activación es en el mes actual
            if ($activationDate->isSameMonth($startOfMonth)) {
                $remainingDays = $activationDate->diffInDays($endOfMonth) + 1;
                $prorateMultiplier = $remainingDays / $daysInMonth;

                $billedPeriod = $activationDate->format('d M') . ' al ' . $endOfMonth->format('d M Y');
                $billedPeriodShort = $activationDate->format('d') . ' al ' . $endOfMonth->format('d');
            }
        }

        return [
            'period_full' => $billedPeriod,
            'period_short' => $billedPeriodShort,
            'prorate_multiplier' => $prorateMultiplier
        ];
    }

    /**
     * Agregar ítems a la factura
     */
    private function addInvoiceItems($invoice, $contract, $overdueInvoices, $prorateMultiplier)
    {
        $totalFactura = 0;
        $totalTax = 0;
        $totalPendingAmount = 0;

        // Agregar servicios del plan
        if ($contract->plan && $contract->plan->services) {
            foreach ($contract->plan->services as $service) {
                $basePrice = $service->base_price * $prorateMultiplier;
                $taxAmount = $service->tax_percentage > 0 ? $basePrice * ($service->tax_percentage / 100) : 0;
                $totalItem = $basePrice + $taxAmount;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $service->name,
                    'quantity' => 1,
                    'unit_price' => $service->base_price,
                    'percentage_tax' => $service->tax_percentage,
                    'tax' => $taxAmount,
                    'total' => $totalItem,
                ]);

                $totalFactura += $totalItem;
                $totalTax += $taxAmount;
            }
        }

        // Agregar cargos adicionales pendientes
        $pendingCharges = $contract->additionalCharges()
            ->where('status', 'pendiente')
            ->get();

        foreach ($pendingCharges as $charge) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $charge->description,
                'quantity' => 1,
                'unit_price' => $charge->amount,
                'percentage_tax' => 0,
                'tax' => 0,
                'total' => $charge->amount,
            ]);

            $charge->update(['status' => 'Facturado']);
            $totalFactura += $charge->amount;
        }

        // Incluir facturas vencidas como ítems
        foreach ($overdueInvoices as $pendingInvoice) {
            // Usar pending_invoice_amount si existe, sino usar total
            $pendingAmount = $pendingInvoice->pending_invoice_amount ?? $pendingInvoice->total;

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Factura vencida #' . $pendingInvoice->id . ' - Período: ' . $pendingInvoice->billed_period,
                'quantity' => 1,
                'unit_price' => $pendingAmount,
                'percentage_tax' => 0,
                'tax' => 0,
                'total' => $pendingAmount,
            ]);

            $totalFactura += $pendingAmount;
            $totalPendingAmount += $pendingAmount;

            // Marcar la factura como cargada
            $pendingInvoice->update(['status' => 'Cargada a nueva factura']);
        }

        return [
            'total' => $totalFactura,
            'tax' => $totalTax,
            'pending_amount' => $totalPendingAmount
        ];
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
