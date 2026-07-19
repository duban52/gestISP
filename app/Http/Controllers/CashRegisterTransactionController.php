<?php

namespace App\Http\Controllers;

use App\Exports\TransactionsReport;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CashRegisterTransactionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:transactions.index')->only('index');
        $this->middleware('check.permission:transactions.store')->only('create', 'store');
        $this->middleware('check.permission:transactions.history')->only('edit', 'update');
        $this->middleware('check.permission:transactions.export')->only('exportHistoryTransactionsPDF');
        $this->middleware('check.permission:transactions.export-excel')->only('export');
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        return view('gestisp.cashRegisters.transactions.index');
    }

    public function history(Request $request)
    {
        $query = CashRegisterTransaction::with('cashRegister.branch');

        // Filtrar por sucursal actual
        if (session()->has('branch_id')) {
            $branchId = session('branch_id');
            $query->whereHas('cashRegister.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            });
        }



        // Verificar si hay filtros adicionales para la búsqueda
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            // Mapear los campos de relaciones para su correcto uso
            $fieldMappings = [
                'transaction_type' => 'transaction_type',
                'payment_method' => 'payment_method',
                'amount' => 'amount',
            ];

            if (array_key_exists($field, $fieldMappings)) {
                $mappedField = $fieldMappings[$field];
                $query->where($mappedField, 'like', '%' . $value . '%');
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            // Verificar el formato enviado desde el formulario
            \Log::info('Fechas del request:', ['start_date' => $request->start_date, 'end_date' => $request->end_date]);

            // Asegúrate de usar el formato correcto (Y-m-d)
            $startDate = Carbon::createFromFormat('Y-m-d', trim($request->start_date))->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', trim($request->end_date))->endOfDay();

            // Registrar los valores procesados
            \Log::info('Fechas procesadas:', ['start_date' => $startDate, 'end_date' => $endDate]);

            $query->whereBetween('created_at', [$startDate, $endDate]);
        }


        // Paginación flexible
        $perPage = $request->get('per_page', 12);
        $transactions = $query->orderBy('created_at', 'desc')->simplePaginate($perPage);

        return view('gestisp.cashRegisters.transactions.history', compact('transactions'));
    }

    public function exportHistoryTransactionsPDF(Request $request){
        $query = CashRegisterTransaction::with('cashRegister.branch');

        // Filtrar por sucursal actual
        if (session()->has('branch_id')) {
            $branchId = session('branch_id');
            $query->whereHas('cashRegister.branch', function ($q) use ($branchId) {
                $q->where('id', $branchId);
            });
        }



        // Verificar si hay filtros adicionales para la búsqueda
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            // Mapear los campos de relaciones para su correcto uso
            $fieldMappings = [
                'transaction_type' => 'transaction_type',
                'payment_method' => 'payment_method',
                'amount' => 'amount',
            ];

            if (array_key_exists($field, $fieldMappings)) {
                $mappedField = $fieldMappings[$field];
                $query->where($mappedField, 'like', '%' . $value . '%');
            }
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            // Verificar el formato enviado desde el formulario
            \Log::info('Fechas del request:', ['start_date' => $request->start_date, 'end_date' => $request->end_date]);

            // Asegúrate de usar el formato correcto (Y-m-d)
            $startDate = Carbon::createFromFormat('Y-m-d', trim($request->start_date))->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', trim($request->end_date))->endOfDay();

            // Registrar los valores procesados
            \Log::info('Fechas procesadas:', ['start_date' => $startDate, 'end_date' => $endDate]);

            $query->whereBetween('created_at', [$startDate, $endDate]);
        }



        // Precargar el usuario: el PDF muestra quién registró cada
        // movimiento (evita N+1 en el detalle)
        $transactions = $query->with('user')->orderByDesc('created_at')->get();

        // El PDF informa el período consultado en su encabezado
        $from = $request->start_date;
        $to = $request->end_date;

        // Horizontal: el detalle tiene 8 columnas
        $pdf = \App\Support\PdfBranding::make(
            'gestisp.cashRegisters.transactions.report_pdf',
            compact('transactions', 'from', 'to'),
            landscape: true
        );

        return $pdf->download('Historial de movimientos de caja.pdf');
    }

    public function export()
    {
        //Función para exportar los datos de los clientes a un excel
        return (new TransactionsReport)->download('listado_de_transacciones.xlsx');
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
        // Validar los datos del formulario
        $validated = $request->validate([
            'transaction_type' => 'required|in:Ingreso,Egreso',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'description' => 'nullable|string'
        ]);

        // Buscar la caja abierta del usuario
        $cashRegister = CashRegister::where('user_id', auth()->id())
            ->where('status', 'open')
            ->first();

        if (!$cashRegister) {
            return response()->json([
                'error' => 'No tienes ninguna caja abierta para registrar el movimiento'
            ], 422);
        }

        // Crear la transacción
        $transaction = CashRegisterTransaction::create([
            'cash_register_id' => $cashRegister->id,
            'transaction_type' => $validated['transaction_type'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'description' => $validated['description'],
            'created_by' => auth()->id()
        ]);

        // Actualizar los totales de la caja
        $cashRegister->calculateTotals();

        return response()->json([
            'message' => 'Movimiento registrado correctamente',
            'transaction' => $transaction
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(CashRegisterTransaction $cashRegisterTransaction)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CashRegisterTransaction $cashRegisterTransaction)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CashRegisterTransaction $cashRegisterTransaction)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CashRegisterTransaction $cashRegisterTransaction)
    {
        //
    }
}
