<?php

namespace App\Http\Controllers;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Services\PaymentRegistrar;
use App\Exports\PaymentsExport;
use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Controlador de Pagos
 *
 * Gestiona el historial de pagos, el registro de nuevos pagos sobre
 * facturas (con integración a caja, órdenes técnicas de reconexión y
 * recibo PDF) y los reportes exportables en PDF y Excel.
 *
 * Los métodos index / exportPaymentsPDF / export comparten los mismos
 * filtros (cliente, rango de fechas) a través de applyFilters(), de
 * modo que lo que el usuario ve filtrado en pantalla es exactamente
 * lo que se exporta.
 */
class PaymentController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:payments.index')->only('index');
        $this->middleware('check.permission:payments.create')->only('create', 'store');
        $this->middleware('check.permission:payments.edit')->only('edit', 'update');
        $this->middleware('check.permission:payments.destroy')->only('destroy');
        $this->middleware('check.permission:payments.search')->only('search');
        $this->middleware('check.permission:payments.searchView')->only('searchView');
        $this->middleware('check.permission:payments.export')->only('exportPaymentsPDF');
        $this->middleware('check.permission:payments.export-excel')->only('export');
    }

    /**
     * Historial de pagos con filtros.
     *
     * La tabla usa DataTables del lado del cliente (búsqueda, orden y
     * paginación en el navegador), por eso se retorna la colección
     * completa filtrada, no paginada.
     *
     * IMPORTANTE: como los pagos crecen sin límite, si el usuario no
     * especifica un rango de fechas se muestra solo el mes actual.
     * Esto evita enviar miles de registros al navegador; para rangos
     * históricos el usuario usa el filtro de fechas.
     *
     * Se precargan las relaciones (invoice.contract.client, user) con
     * with() para evitar el problema N+1 en la tabla.
     */
    public function index(Request $request): View
    {
        $query = $this->applyFilters($request);

        // Sin rango de fechas explícito → limitar al mes actual
        $usingDefaultRange = !($request->filled('start_date') && $request->filled('end_date'));

        if ($usingDefaultRange) {
            $query->whereBetween('payment_date', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ]);
        }

        $payments = $query
            ->with(['invoice.contract.client', 'user'])
            ->orderByDesc('payment_date')
            ->get();

        return view('gestisp.payments.index', compact('payments', 'usingDefaultRange'));
    }

    /**
     * Exporta el historial filtrado a PDF.
     *
     * Usa los mismos filtros que index (sin el límite del mes actual:
     * el usuario decide el rango que quiere en el reporte).
     */
    public function exportPaymentsPDF(Request $request)
    {
        $payments = $this->applyFilters($request)
            ->with(['invoice.contract.client', 'user'])
            ->orderByDesc('payment_date')
            ->get();

        $pdf = PDF::loadView('gestisp.payments.report_pdf', compact('payments'));

        return $pdf->download('Reporte de pagos.pdf');
    }

    /**
     * Exporta todos los pagos a Excel.
     */
    public function export()
    {
        return (new PaymentsExport)->download('listado_de_pagos.xlsx');
    }

    /**
     * Construye la consulta de pagos con los filtros del request.
     *
     * Filtros soportados:
     * - Sucursal activa en sesión (siempre que exista)
     * - filter_field + filter_value: búsqueda por identidad, nombre
     *   o apellido del cliente (vía relaciones), o campos propios
     *   de la tabla payments
     * - start_date + end_date: rango de fechas de pago
     *
     * Este método es la única fuente de verdad de los filtros:
     * index y exportPaymentsPDF lo comparten para garantizar que
     * el reporte exportado coincide con lo mostrado en pantalla.
     */
    private function applyFilters(Request $request): Builder
    {
        $query = Payment::query();

        // Restringir a la sucursal activa
        if (session()->has('branch_id')) {
            $branchId = session('branch_id');

            $query->whereHas('invoice.contract.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            });
        }

        // Búsqueda por campo del cliente o del pago
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            // Mapa de campos permitidos: evita que se filtre por
            // columnas arbitrarias enviadas en el request
            $fieldMappings = [
                'client.identity_number' => 'clients.identity_number',
                'client.name'            => 'clients.name',
                'client.last_name'       => 'clients.last_name',
                'payments.payment_date'  => 'payments.payment_date',
            ];

            if (array_key_exists($field, $fieldMappings)) {
                $mappedField = $fieldMappings[$field];

                if (str_contains($mappedField, 'clients')) {
                    // Campos del cliente: filtrar a través de la relación
                    $query->whereHas('invoice.contract.client', function ($q) use ($mappedField, $value) {
                        $q->where(str_replace('clients.', '', $mappedField), 'like', "%{$value}%");
                    });
                } else {
                    // Campos propios de payments
                    $query->where($mappedField, 'like', "%{$value}%");
                }
            }
        }

        // Rango de fechas de pago
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('payment_date', [
                $request->start_date,
                $request->end_date,
            ]);
        }

        return $query;
    }

    /**
     * Vista de búsqueda de facturas pendientes para registrar pagos.
     */
    public function searchView(): View
    {
        return view('gestisp.payments.search');
    }

    /**
     * Busca facturas pendientes de pago por identidad o ID de cliente.
     *
     * Excluye facturas ya pagadas o refinanciadas ("Cargada a nueva
     * factura"), que no admiten pagos adicionales.
     */
    public function search(Request $request): View
    {
        $request->validate([
            'search_term' => 'required|string',
        ]);

        $query = Invoice::query();

        // Restringir a la sucursal activa
        if (session()->has('branch_id')) {
            $query->whereHas('contract', function ($q) {
                $q->where('branch_id', session('branch_id'));
            });
        }

        // Buscar por identidad o ID del cliente
        if ($request->filled('search_term')) {
            $term = $request->search_term;

            $query->whereHas('contract.client', function ($q) use ($term) {
                $q->where('identity_number', 'like', "%{$term}%")
                    ->orWhere('id', 'like', "%{$term}%");
            });
        }

        // Solo facturas que aún admiten pagos
        $query->whereNotIn('status', InvoiceStatus::notPayable());

        $perPage  = $request->get('per_page', 8);
        $invoices = $query->simplePaginate($perPage);

        return view('gestisp.payments.search', compact('invoices'));
    }

    /**
     * Registra un pago sobre una factura.
     *
     * Todo el proceso corre dentro de una transacción de base de
     * datos: si cualquier paso falla, se revierte completo.
     *
     * Flujo:
     * 1. Validar monto contra el saldo pendiente de la factura
     * 2. Exigir caja abierta para pagos en efectivo/tarjeta
     * 3. Crear el pago
     * 4. Actualizar el estado de la factura:
     *    - Pago total → "Pagada" + reactivar contrato si estaba en
     *      pre-suspensión, o generar orden técnica de reconexión si
     *      estaba suspendido
     *    - Pago parcial → "Pendiente Parcial"
     * 5. Registrar la transacción en la caja abierta
     * 6. Generar el recibo PDF y retornar su URL
     *
     * Responde en JSON porque el formulario de pago funciona vía
     * AJAX desde la vista de búsqueda de facturas.
     */
    public function store(Request $request, PaymentRegistrar $registrar)
    {
        DB::beginTransaction();

        try {
            $validated = $request->validate([
                'invoice_id'       => 'required|exists:invoices,id',
                'amount'           => 'required|numeric|min:0.01',
                'payment_method'   => 'required|string',
                'reference_number' => 'nullable|string',
                'notes'            => 'nullable|string',
            ]);

            // Toda la regla de negocio (saldo, caja, estados de
            // factura y contrato, orden de reconexión, movimiento
            // de caja) vive en el servicio; el recibo PDF queda
            // dentro de la misma transacción para conservar el
            // todo-o-nada del flujo original
            $payment = $registrar->register($validated, auth()->id(), session('branch_id'));

            $pdfPath = $this->storeReceiptPdf($payment);

            DB::commit();

            $payment->load('invoice');

            return response()->json([
                'success'     => true,
                'message'     => 'Pago registrado correctamente',
                'payment'     => [
                    'id'             => $payment->id,
                    'invoice_id'     => $payment->invoice_id,
                    'amount'         => number_format($payment->amount, 2),
                    'payment_method' => $payment->payment_method,
                ],
                'new_balance' => number_format($payment->invoice->getPendingAmount(), 2),
                'pdf_url'     => asset('storage/' . $pdfPath),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error en procesamiento de pago: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Genera el recibo PDF del pago en storage/temp y retorna su
     * ruta relativa en el disco público.
     */
    private function storeReceiptPdf(Payment $payment): string
    {
        if (!Storage::disk('public')->exists('temp')) {
            Storage::disk('public')->makeDirectory('temp');
        }

        $pdf = PDF::loadView('gestisp.payments.payment-receipt', [
            'payment' => $payment->load(['invoice.contract.client', 'user']),
            'company' => [
                'name'    => config('app.company_name', 'Nombre de la Empresa'),
                'address' => config('app.company_address', 'Dirección de la Empresa'),
                'phone'   => config('app.company_phone', 'Teléfono de la Empresa'),
                'email'   => config('app.company_email', 'Email de la Empresa'),
            ],
        ]);

        $pdfPath = 'temp/payment_' . $payment->id . '.pdf';
        Storage::disk('public')->put($pdfPath, $pdf->output());

        return $pdfPath;
    }

    /**
     * Genera y guarda el recibo PDF de un pago existente.
     * Retorna la ruta del archivo generado.
     */
    public function generatePaymentReceipt(Payment $payment): string
    {
        $pdf     = PDF::loadView('pdf.payment_receipt', compact('payment'));
        $pdfPath = storage_path('app/public/payment_receipts/' . $payment->id . '.pdf');
        $pdf->save($pdfPath);

        return $pdfPath;
    }
}
