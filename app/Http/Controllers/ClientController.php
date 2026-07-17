<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Models\Client;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:clients.index')->only('index');
        $this->middleware('check.permission:clients.create')->only('create', 'store');
        $this->middleware('check.permission:clients.edit')->only('edit', 'update');
        $this->middleware('check.permission:clients.destroy')->only('destroy');
        $this->middleware('check.permission:clients.search')->only('search');
        $this->middleware('check.permission:clients.searchView')->only('searchView');
        $this->middleware('check.permission:clients.export')->only('export');
    }
    public function searchView(){
        //Retornar a la vista del buscador
        return view('gestisp.clients.search');
    }

    public function search(Request $request)
    {


        // Validar el número de identidad
        $request->validate([
            'identity_number' => 'required|string|max:20',
        ]);

        // Buscar el cliente por número de identidad
        $client = Client::where('identity_number', $request->identity_number)
            ->where('branch_id', session('branch_id'))
            ->first();

        // Verificar si se encontró un cliente
        if (!$client) {
            return redirect()->action([ClientController::class, 'search'])->with('error', 'Cliente no encontrado.');
        }

        $contracts = $client->contracts;


        //Retornar a la vista del buscador
        return view('gestisp.clients.search', compact('client', 'contracts'));
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Client::query();

        // Filtra por la sucursal en sesión
        if (session()->has('branch_id')) {
            $query->where('branch_id', session('branch_id'));
        }

        // Verifica si hay un filtro adicional y aplica la búsqueda
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            // Usa "like" para búsquedas de texto y "where" para valores exactos
            if (in_array($field, ['name', 'type_client'])) {
                $query->where($field, 'like', '%' . $value . '%');
            } else {
                $query->where($field, $value);
            }
        }

        // Paginación flexible
        $perPage = $request->get('per_page', 8);
        $clients = $query->paginate($perPage);

        return view('gestisp.clients.index', compact('clients'));

    }

    public function export()
    {
        //Función para exportar los datos de los clientes a un excel
        return (new ClientsExport)->download('clients.xlsx');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
        return view('gestisp.clients.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
        $request->merge([
            'user_id' => Auth::user()->id,
            'branch_id' => session('branch_id'),
        ]);



        //Guardo la solicitud en una variable
        $client = $request->all();

        Client::create($client);

        return redirect()->action([ClientController::class, 'create'])
            ->with('success-create', 'Cliente creado con éxito');

    }

    /**
     * Display the specified resource.
     */
    public function show(Client $client)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Client $client)
    {
        //
        return view('gestisp.clients.edit', compact('client'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client)
    {
        //Actualizar datos
        $client->update([
           'number_phone' => $request->number_phone,
            'aditional_phone' => $request->aditional_phone,
            'email' => $request->email,
        ]);

        return redirect()->back()->with('success', 'Datos del cliente actualizados');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Client $client)
    {
        //
        $client->delete();

        return redirect()->action([ClientController::class, 'index'],  compact('client'))
            ->with('success-delete', 'Cliente eliminado con éxito');
    }
}
