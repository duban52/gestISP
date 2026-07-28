<?php

namespace App\Http\Controllers;

use App\Billing\Enums\RetentionType;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\PaymentRetention;
use App\Support\PdfBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CashRegisterController extends Controller
{
    //Proteger rutas
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:cashRegisters.index')->only('index');
        $this->middleware('check.permission:cashRegisters.create')->only('create', 'store');
        $this->middleware('check.permission:cashRegisters.edit')->only('edit', 'update');
        $this->middleware('check.permission:cashRegisters.destroy')->only('destroy');
        $this->middleware('check.permission:cash_register.status')->only('status');
        $this->middleware('check.permission:cash_register.open')->only('open');
        $this->middleware('check.permission:cash_register.close')->only('close');
        $this->middleware('check.permission:cash_register.summary')->only('summary');
    }

    /**
     * Resumen/cuadre de cajas de la sucursal por período.
     *
     * Lista todas las cajas abiertas en el rango de fechas (por
     * defecto, hoy) con sus totales, el desglose de ingresos por
     * método de pago y una fila de totales generales — el cuadre
     * entre los distintos puntos de cobro.
     */
    public function summary(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $from = $validated['start_date'] ?? now()->toDateString();
        $to = $validated['end_date'] ?? $from;

        $registers = CashRegister::with('user')
            ->where('branch_id', session('branch_id'))
            ->whereBetween('opened_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->orderBy('opened_at')
            ->get();

        // Desglose de ingresos por método de pago, por caja
        $methodBreakdown = CashRegisterTransaction::whereIn('cash_register_id', $registers->pluck('id'))
            ->where('transaction_type', 'Ingreso')
            ->select('cash_register_id', 'payment_method', DB::raw('SUM(amount) AS total'))
            ->groupBy('cash_register_id', 'payment_method')
            ->get()
            ->groupBy('cash_register_id');

        // Totales generales del período (todas las cajas del rango)
        $totals = [
            'initial' => $registers->sum('initial_amount'),
            'income' => $registers->sum('total_income'),
            'expenses' => $registers->sum('total_expenses'),
            'expected' => $registers->sum('expected_amount'),
            'final' => $registers->whereNotNull('final_amount')->sum('final_amount'),
            'difference' => $registers->whereNotNull('final_amount')->sum('difference'),
            'open_count' => $registers->where('status', 'open')->count(),
        ];

        // Totales por método de pago del período completo
        $methodTotals = $methodBreakdown->flatten()
            ->groupBy('payment_method')
            ->map(fn ($rows) => $rows->sum('total'));

        // Retenciones practicadas por los clientes en el período.
        //
        // NO forman parte del cuadre: ese dinero nunca estuvo en el
        // cajón, el cliente lo consignó al Estado a nombre nuestro.
        // Se muestran aparte, como nota informativa, porque sin ellas
        // quien concilie el recaudo contra las facturas ve un hueco
        // sin explicación: hay facturas marcadas como pagadas por más
        // dinero del que entró.
        $retentions = PaymentRetention::query()
            ->where('branch_id', session('branch_id'))
            ->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->get();

        $retentionTotals = [
            'total' => round((float) $retentions->sum('amount'), 2),
            'count' => $retentions->count(),
            'by_type' => $retentions->groupBy('type')->map(fn ($filas) => [
                'label' => RetentionType::tryFrom($filas->first()->type)?->etiqueta() ?? $filas->first()->type,
                'total' => round((float) $filas->sum('amount'), 2),
            ])->values(),
        ];

        return view('gestisp.cashRegisters.summary', compact(
            'registers', 'methodBreakdown', 'totals', 'methodTotals', 'retentionTotals', 'from', 'to'
        ));
    }
    /**
     * Display a listing of the resource.
     */
    /**
     * Pantalla de gestión de caja del usuario.
     *
     * Se arma en el servidor y no por AJAX como antes: la pantalla
     * necesita el desglose por método de pago y los últimos
     * movimientos, y pedirlos por partes dejaba la vista en blanco
     * mientras cargaba.
     *
     * El desglose por método importa más de lo que parece: el
     * esperado en caja suma TODOS los métodos, pero en el cajón solo
     * está el efectivo. Sin separarlos, quien cuenta el dinero
     * reporta un faltante por cada transferencia que recibió.
     */
    public function index()
    {
        $caja = CashRegister::where('user_id', auth()->id())
            ->where('branch_id', session('branch_id'))
            ->where('status', 'open')
            ->first();

        $movimientos = collect();
        $porMetodo = collect();

        if ($caja) {
            $movimientos = $caja->transactions()
                ->with([
                    'user',
                    'payment.invoice.contract.client',
                    'payment.contract.client',
                ])
                ->orderByDesc('id')
                ->get();

            // Ingresos menos egresos de cada método: es lo que debería
            // haber de cada forma de pago al cerrar.
            $porMetodo = $movimientos
                ->groupBy(fn ($m) => $m->payment_method ?: 'Sin método')
                ->map(fn ($filas) => [
                    'ingresos' => round((float) $filas->where('transaction_type', 'Ingreso')->sum('amount'), 2),
                    'egresos' => round((float) $filas->where('transaction_type', 'Egreso')->sum('amount'), 2),
                    'movimientos' => $filas->count(),
                ])
                ->sortByDesc('ingresos');
        }

        // Última caja cerrada: da contexto cuando no hay ninguna
        // abierta ("cerraste hace 2 horas con $X").
        $ultimoCierre = CashRegister::where('user_id', auth()->id())
            ->where('branch_id', session('branch_id'))
            ->where('status', 'closed')
            ->latest('closed_at')
            ->first();

        return view('gestisp.cashRegisters.index', compact(
            'caja', 'movimientos', 'porMetodo', 'ultimoCierre'
        ));
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
    public function show(CashRegister $cashRegister)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CashRegister $cashRegister)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CashRegister $cashRegister)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CashRegister $cashRegister)
    {
        //
    }

    // Método para abrir una nueva caja
    public function open(Request $request)
    {
        $branchId = session('branch_id');
        // Validamos los datos de apertura
        $validated = $request->validate([
            'initial_amount' => 'required|numeric|min:0',  // Monto inicial no negativo
            'opening_notes' => 'nullable|string'           // Notas opcionales
        ]);

        // Verificamos que el usuario no tenga otra caja abierta
        $activeRegister = CashRegister::where('user_id', auth()->id())
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        if ($activeRegister) {
            return response()->json([
                'error' => 'Ya tienes una caja abierta'
            ], 422);
        }

        // Creamos el registro de la nueva caja
        $cashRegister = CashRegister::create([
            'branch_id' => $branchId,
            'user_id' => auth()->id(),
            'initial_amount' => $validated['initial_amount'],
            // ?? null por lo mismo que en close(): el campo es
            // opcional y validate() no devuelve la clave si no viene.
            'opening_notes' => $validated['opening_notes'] ?? null,
            'opened_at' => now(),
            'status' => 'open'
        ]);

        return response()->json([
            'message' => 'Caja abierta correctamente',
            'cash_register' => $cashRegister
        ]);
    }

    public function close(Request $request)
    {
        $branchId = session('branch_id');
        // Buscar la última caja abierta para el usuario autenticado
        $cashRegister = CashRegister::where('user_id', auth()->id())
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        if (!$cashRegister) {
            return response()->json([
                'error' => 'No tienes ninguna caja abierta para cerrar'
            ], 422);
        }

        // Validar los datos de cierre
        $validated = $request->validate([
            'final_amount' => 'required|numeric|min:0',
            'closing_notes' => 'nullable|string'
        ]);

        // Actualizar el registro con los datos de cierre.
        //
        // closing_notes va con ?? null porque es opcional: si no se
        // envía, validate() no devuelve la clave y acceder a ella
        // tumbaba el cierre con un error 500. Funcionaba de milagro
        // porque el formulario siempre manda el campo, aunque venga
        // vacío; cualquier otro cliente (o un campo renombrado) lo
        // rompía.
        $cashRegister->update([
            'final_amount' => $validated['final_amount'],
            'closing_notes' => $validated['closing_notes'] ?? null,
            'closed_at' => now(),
            'status' => 'closed'
        ]);

        // Calcular los totales finales
        $cashRegister->calculateTotals();

        // Generar el comprobante de cierre (arqueo). Se precarga la
        // sucursal porque el encabezado del PDF la identifica.
        // Retenciones recibidas durante el turno.
        //
        // NO entran al arqueo: ese dinero nunca estuvo en el cajón, y
        // meterlo descuadraría el conteo. Van al comprobante por una
        // razón puramente operativa: el cajero recibió del cliente un
        // CERTIFICADO de retención en papel y tiene que entregarlo.
        // Sin ese certificado la empresa no puede descontar el
        // impuesto, así que el comprobante de cierre le sirve de
        // recordatorio de qué documentos debe pasar con la caja.
        $retentions = PaymentRetention::with(['contract.client', 'invoice'])
            ->whereHas('payment', fn ($q) => $q->where('cash_register_id', $cashRegister->id))
            ->orderBy('id')
            ->get();

        // El detalle de movimientos identifica al cliente de cada
        // cobro (contrato e identificación), así que se precarga la
        // cadena completa: sin esto son cuatro consultas por fila.
        // Los anticipos cuelgan del contrato sin pasar por factura,
        // de ahí las dos ramas.
        $pdf = PdfBranding::make('gestisp.cashRegisters.report', [
            'cashRegister' => $cashRegister->load([
                'transactions.user',
                'transactions.payment.invoice.contract.client',
                'transactions.payment.contract.client',
                'user',
                'branch',
            ]),
            'retentions' => $retentions,
        ]);

        // Guardar el PDF
        $pdfPath = 'cash_register_reports/cash_register_' . $cashRegister->id . '.pdf';
        Storage::disk('public')->put($pdfPath, $pdf->output());

        return response()->json([
            'message' => 'Caja cerrada correctamente',
            'cash_register' => $cashRegister->fresh(),
            'pdf_url' => asset('storage/' . $pdfPath)
        ]);
    }

    public function status()
    {
        $branchId = session('branch_id');
        $activeRegister = CashRegister::where('user_id', auth()->id())
            ->where('branch_id', $branchId)
            ->where('status', 'open')
            ->first();

        if ($activeRegister) {
            return response()->json([
                'status' => 'open',
                'initial_amount' => $activeRegister->initial_amount,
                'expected_amount' => $activeRegister->expected_amount // Asegúrate de que este campo exista o cámbialo según la lógica
            ]);
        }

        return response()->json([
            'status' => 'closed'
        ]);
    }

    // Método para generar reportes
    public function report(Request $request)
    {
        $branchId = session('branch_id');
        // Validamos los filtros del reporte
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'user_id' => 'nullable|exists:users,id'
        ]);

        // Construimos la consulta base
        $query = CashRegister::with(['transactions', 'user', $branchId])
            ->whereBetween('opened_at', [
                $validated['start_date'],
                $validated['end_date'] . ' 23:59:59'
            ]);

        // Si se especifica un usuario, filtramos por él
        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        $registers = $query->get();

        // Generamos el resumen
        $summary = [
            'total_income' => $registers->sum('total_income'),
            'total_expenses' => $registers->sum('total_expenses'),
            'total_difference' => $registers->sum('difference'),
            'register_count' => $registers->count(),
            // Agrupamos las transacciones por método de pago
            'by_payment_method' => CashRegisterTransaction::whereBetween('created_at', [
                $validated['start_date'],
                $validated['end_date'] . ' 23:59:59'
            ])
                ->selectRaw('payment_method, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get()
        ];

        return response()->json([
            'registers' => $registers,
            'summary' => $summary
        ]);
    }
}
