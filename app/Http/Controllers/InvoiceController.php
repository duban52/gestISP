<?php

namespace App\Http\Controllers;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\InvoiceGenerator;
use App\Billing\Services\InvoiceVoider;
use App\Billing\Services\MonthlyBillingRun;
use App\Billing\Services\OverdueProcessor;
use App\Jobs\GeneratePendingInvoicesPdf;
use App\Models\BillingRun;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PdfReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Milon\Barcode\Facades\DNS1DFacade;
use App\Services\Audit\AuditLogger;
use App\Tenancy\CurrentContext;
use App\Support\BranchFilter;
use App\Support\PdfBranding;

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
        $this->middleware('check.permission:invoices.index')->only('index', 'billingRuns');
        $this->middleware('check.permission:invoices.create')->only('create', 'store');
        $this->middleware('check.permission:invoices.edit')->only('edit', 'update');
        $this->middleware('check.permission:invoices.show')->only('show');
        $this->middleware('check.permission:invoices.destroy')->only('destroy', 'voidInvoice');
        $this->middleware('check.permission:invoices.generate')->only('generateInvoices', 'generateForContract');
        $this->middleware('check.permission:invoices.download-pdf')->only('downloadInvoicePdf');
        $this->middleware('check.permission:invoices.generate_max_pdf')->only('generatePendingInvoicesPdf');
        $this->middleware('check.permission:invoices.check-pdf-status')->only('checkPdfStatus');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, OverdueProcessor $overdueProcessor)
    {
        // Actualizar facturas vencidas primero (en fase 5 esto
        // pasará a un comando programado diario y saldrá del GET)
        $overdueProcessor->markOverdueInvoices();

        $totalPendding = 0;

        // Total POR RECAUDAR de la sucursal: la suma de los saldos
        // de todas las facturas abiertas (pendientes, parciales,
        // con riesgo y vencidas). Antes solo sumaba el total de
        // las pendientes e ignoraba vencidas y abonos.
        if (app(CurrentContext::class)->activo()) {
            $totalPendding = Invoice::whereIn('status', InvoiceStatus::payable())
                ->whereIn('branch_id', app(CurrentContext::class)->branchIds())
                ->sum('pending_invoice_amount');
        }

        // El filtro va SOLO por la sucursal del contrato.
        //
        // Antes tambien exigia clients.branch_id, y desde que el
        // cliente pertenece a la EMPRESA esa columna es opcional: un
        // cliente creado en panel consolidado la tiene nula y sus
        // facturas desaparecian del listado. La sucursal donde se
        // presta el servicio —y donde se factura— es la del contrato.
        // Filtros del buscador. Se aplican ADEMAS del alcance, nunca
        // en su lugar: pedir por URL una sucursal o un grupo que no
        // corresponden cruza las dos condiciones y da cero resultados,
        // en vez de abrir nada y en vez de un 403 que confirmaria que
        // existen.
        $sucursalesPedidas = BranchFilter::normalizar($request->query('branch_id'));
        $gruposPedidos = BranchFilter::normalizar($request->query('affinity_group_id'));

        $invoices = Invoice::join('contracts', 'invoices.contract_id', '=', 'contracts.id')
            ->join('clients', 'contracts.client_id', '=', 'clients.id')
            ->whereIn('contracts.branch_id', app(CurrentContext::class)->branchIds())
            ->when($sucursalesPedidas !== [], fn ($q) => $q->whereIn('contracts.branch_id', $sucursalesPedidas))
            ->when($gruposPedidos !== [], fn ($q) => $q->whereIn('contracts.affinity_group_id', $gruposPedidos))
            ->select('invoices.*')
            // El listado muestra el número de contrato y la
            // identificación del cliente: se precargan para no hacer
            // dos consultas por cada fila de la tabla.
            // El grupo se precarga porque el listado lo muestra: sin
            // esto seria una consulta por fila.
            ->with(['contract.client', 'contract.affinityGroup', 'branch'])
            ->orderBy('invoices.created_at', 'desc')
            ->get(); // Cambiado de simplePaginate(10) a get()

        return view('gestisp.invoices.index', [
            'invoices' => $invoices,
            'totalPendding' => $totalPendding,
            'filtros' => $request->query(),
        ]);
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
        // Se cargan también las notas: la ficha muestra las
        // correcciones ya emitidas sobre esta factura.
        $invoice->load(['contract.client', 'contract.plan.services', 'invoice_items', 'notes']);

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
    public function generateInvoices(Request $request, MonthlyBillingRun $billingRun)
    {
        // La corrida es DE UNA SUCURSAL: recorre sus contratos y
        // consume su consecutivo. En consolidado hay que decir cual;
        // antes se pasaba null y no facturaba nada.
        $result = $billingRun->runForBranch(
            app(CurrentContext::class)->branchParaEscritura($request->input('branch_id')),
            Auth::id(),
        );

        if ($result['total_contracts'] === 0) {
            return redirect()->route('invoices.index')
                ->with('error', 'No hay contratos para generar facturas.');
        }

        $message = sprintf(
            'Proceso completado. Facturas generadas: %d, Omitidas: %d. Total facturado: $%s (IVA: $%s)',
            $result['generated'],
            $result['skipped'],
            number_format($result['total_billed'], 2),
            number_format($result['total_tax'], 2)
        );

        // Se lleva al usuario al detalle de lo que acaba de generar:
        // ahí ve factura por factura y puede descargar el reporte en
        // Excel, CSV o PDF para archivarlo de inmediato.
        if (!empty($result['billing_run_id'])) {
            return redirect()
                ->route('billing_runs.show', $result['billing_run_id'])
                ->with('success', $message . '. Descargue el reporte para guardarlo.');
        }

        return redirect()->route('invoices.index')
            ->with('success', $message);
    }

    /**
     * Factura UN contrato, desde su ficha.
     *
     * POR QUÉ EXISTE, SI YA ESTÁ LA CORRIDA MENSUAL
     * ---------------------------------------------
     * Porque la corrida es de toda la sucursal y hay casos sueltos que
     * no esperan al mes: un contrato que se instaló ayer, uno que quedó
     * fuera del lote porque estaba suspendido y ya se reconectó, o el
     * cliente que viene al mostrador a pagar y todavía no tiene factura.
     *
     * Hasta ahora la única salida era lanzar la corrida entera de la
     * sucursal, que factura a todo el mundo, o crear la factura a mano
     * — que es exactamente como se gasta un consecutivo autorizado sin
     * las reglas que lo protegen.
     *
     * ES EL MISMO CAMINO, NO UNO PARALELO
     * -----------------------------------
     * Llama a `InvoiceGenerator::generateForContract()`, que es el mismo
     * método que usa la corrida en su bucle. Eso importa: la decisión de
     * si la factura es electrónica, el rango del que sale su número, el
     * congelado del grupo de afinidad y el evento `InvoiceIssued` —que
     * dispara el documento DIAN y el aviso al cliente— son idénticos.
     *
     * Un segundo camino con sus propias reglas es como se acaba
     * emitiendo una factura electrónica sin CUFE.
     *
     * LA ÚNICA DIFERENCIA: NO HAY CORRIDA
     * -----------------------------------
     * Va con `billing_run_id` en null, que es lo que el generador ya
     * contemplaba («null si se creó por otra vía»). Por eso se anota
     * explícitamente en la trazabilidad: sin corrida a la que
     * pertenecer, esta es la única huella de quién la emitió y por qué
     * apareció una factura fuera del lote del mes.
     */
    public function generateForContract(
        Contract $contract,
        InvoiceGenerator $generator,
        AuditLogger $auditor,
    ) {
        // Un contrato de otra sucursal no se factura desde aquí, ni
        // aunque alguien ponga su id en la URL: emitir en una sede
        // ajena consume SU consecutivo autorizado.
        abort_unless(
            app(CurrentContext::class)->permiteSucursal($contract->branch_id),
            403,
            'Este contrato no es de una sucursal a la que tenga acceso.',
        );

        // El generador LANZA cuando no hay rango autorizado y la
        // factura es electrónica. La corrida mensual lo atrapa y sigue
        // con el resto; aquí no hay resto, pero tampoco puede salir un
        // 500: quien pulsó el botón necesita leer qué falta.
        try {
            $resultado = $generator->generateForContract($contract, now(), Auth::id());
        } catch (\RuntimeException $error) {
            return back()->with('error', $error->getMessage());
        }

        if (!($resultado['generated'] ?? false)) {
            return back()->with('error', $this->motivoDeNoFacturar($resultado, $contract));
        }

        $factura = Invoice::findOrFail($resultado['invoice_id']);

        $auditor->action(
            'created',
            sprintf('Factura %s emitida individualmente desde el contrato %s',
                $factura->displayNumber(), $contract->numero_visible ?? $contract->id),
            [
                'contrato_id' => $contract->id,
                'factura_id' => $factura->id,
                'tipo' => $factura->document_kind,
                'total' => $factura->total,
            ],
            $factura,
            'facturacion',
        );

        return redirect()
            ->route('invoices.show', $factura)
            ->with('success', sprintf(
                'Factura %s generada por $%s.',
                $factura->displayNumber(),
                number_format((float) $factura->total, 2, ',', '.'),
            ));
    }

    /**
     * Por qué no se facturó, en español y con salida.
     *
     * El generador devuelve motivos en inglés pensados para el log de
     * la corrida. Aquí los ve una persona delante de la pantalla, así
     * que además de traducirlos hay que decirle qué hacer: el caso más
     * frecuente —ya está facturado este mes— se resuelve enseñándole la
     * factura que ya existe, no repitiendo que no se pudo.
     *
     * @param  array<string, mixed>  $resultado
     */
    private function motivoDeNoFacturar(array $resultado, Contract $contract): string
    {
        return match ($resultado['reason'] ?? null) {
            'Contract suspended' =>
                'El contrato está suspendido: no se le factura hasta reconectarlo.',

            'Nothing to bill' =>
                'Este contrato no tiene nada que facturar: se quedó sin plan y no tiene cargos '
                . 'pendientes. Asígnele un plan antes de emitir.',

            'Invoice already exists for this period' => sprintf(
                'Este contrato ya tiene factura del período %s (%s). '
                . 'Una segunda gastaría otro consecutivo por el mismo servicio.',
                now()->format('m/Y'),
                Invoice::where('contract_id', $contract->id)
                    ->where('billed_year_month', now()->format('Ym'))
                    ->value('full_number') ?? 'sin número',
            ),

            default => 'No se pudo generar la factura de este contrato.',
        };
    }

    /**
     * Reporte gerencial de corridas de facturación de la sucursal:
     * cada generación con sus conteos y totales facturados.
     */
    public function billingRuns()
    {
        $runs = BillingRun::with('user')
            ->whereIn('branch_id', app(CurrentContext::class)->branchIds())
            ->orderByDesc('executed_at')
            ->get();

        return view('gestisp.invoices.billing_runs', compact('runs'));
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

            // Los datos DIAN (CUFE, QR, resolucion). Null si la factura
            // es interna, que es la mayoria: entonces el bloque no se
            // pinta y el PDF queda como siempre.
            $dian = app(\App\Billing\Dian\GraphicRepresentation::class)->para($invoice);

            // Generar el PDF usando la vista
            $pdf = Pdf::loadView('gestisp.invoices.pdf', compact('invoice', 'barcodeUrl', 'codeString', 'dian'));
            $pdf->setPaper(PdfBranding::MEDIA_CARTA, 'portrait');
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
    public function generatePendingInvoicesPdf(Request $request)
    {
        // El PDF masivo sale por sucursal, con su membrete y su
        // numeracion. En consolidado hay que decir de cual.
        GeneratePendingInvoicesPdf::dispatch(
            app(CurrentContext::class)->branchParaEscritura($request->input('branch_id')),
        );

        return redirect()->route('invoices.index')
            ->with('success', 'La generación del PDF de facturas pendientes ha sido encolada. No cierre ni recargue la página hasta ser notificado');
    }

    public function checkPdfStatus(Request $request)
    {
        $pdfReport = PdfReport::whereIn('branch_id', app(CurrentContext::class)->branchIds())
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
