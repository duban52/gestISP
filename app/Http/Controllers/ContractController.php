<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Exports\ContractsExport;
use App\Models\AditionalCharge;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Notifications\ClientWelcome;
use App\Support\ColombiaLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContractController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:contracts.index')->only('index');
        $this->middleware('check.permission:contracts.create')->only('create', 'store');
        $this->middleware('check.permission:contracts.edit')->only('edit', 'update');
        $this->middleware('check.permission:contracts.destroy')->only('destroy');
        $this->middleware('check.permission:contracts.show')->only('show');
        $this->middleware('check.permission:contracts.export')->only('export');
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Inicializa la consulta base
        $query = Contract::query();

        // Filtrar por la sucursal actual si está configurada en la sesión
        if (session()->has('branch_id')) {
            $query->where('branch_id', session('branch_id'));
        }

        // Verificar si hay filtros adicionales para la búsqueda
        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $field = $request->filter_field;
            $value = $request->filter_value;

            // Mapear los campos de relaciones para su correcto uso
            $fieldMappings = [
                'client.identity_number' => 'clients.identity_number',
                'client.name' => 'clients.name',
                'client.last_name' => 'clients.last_name',
                'client.number_phone' => 'clients.number_phone',
                'client.email' => 'clients.email',
                'client.type_client' => 'clients.type_client',
                'contract.id' => 'contracts.id',
                'contract.address' => 'contracts.address',
                'contract.cpe_sn' => 'contracts.cpe_sn',
                'contract.user_pppoe' => 'contracts.user_pppoe',
                'contract.status' => 'contracts.status',
                'contract.social_stratum' => 'contracts.social_stratum',
                'contract.activation_date' => 'contracts.activation_date',
                'plan.name' => 'plans.name',
            ];

            if (array_key_exists($field, $fieldMappings)) {
                $mappedField = $fieldMappings[$field];

                // Aplicar los filtros, manejando relaciones
                if (str_contains($mappedField, 'clients')) {
                    $query->whereHas('client', function ($query) use ($mappedField, $value) {
                        $query->where(str_replace('clients.', '', $mappedField), 'like', '%' . $value . '%');
                    });
                } elseif (str_contains($mappedField, 'plans')) {
                    $query->whereHas('plan', function ($query) use ($mappedField, $value) {
                        $query->where(str_replace('plans.', '', $mappedField), 'like', '%' . $value . '%');
                    });
                } else {
                    $query->where($mappedField, 'like', '%' . $value . '%');
                }
            }
        }

        // Paginación flexible
        $perPage = $request->get('per_page', 8);
        $contracts = $query->get();

        return view('gestisp.contracts.index', compact('contracts'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Client $client)
    {
        // Obtener los datos necesarios para el formulario de creación
        $clients = Client::where('branch_id', session('branch_id'))->get(); // Todos los clientes de la sucursal
        $plans = Plan::where('branch_id', session('branch_id'))->get(); // Todos los planes disponibles
        $users = User::all(); // Todos los usuarios para asignar a un contrato

        // Devolver la vista con los datos necesarios
        $colombiaLocations = ColombiaLocations::departmentsWithMunicipalities();

        return view('gestisp.contracts.create', compact(
            'clients', 'plans', 'users', 'client', 'colombiaLocations'
        ));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        $request->merge([
            'user_id' => Auth::user()->id,
            'branch_id' => session('branch_id')
        ]);


        // Crear el contrato con los datos del formulario

        $contract = Contract::create($request->all());

        //Creación de orden automática al crear contrato

        TechnicalOrder::create([
            'contract_id' => $contract->id,
            'branch_id' => session('branch_id'),
            'created_by' => Auth::user()->id,
            'type' => 'Servicio',
            'status' => 'Pendiente',
            'detail' => 'Instalación de servicio (creación automática)',
            'initial_comment' => 'Instalación del servicio'
        ]);

        // Bienvenida al cliente por correo y WhatsApp. Va en cola:
        // no demora la creación del contrato. La orden de instalación
        // automática NO dispara un aviso aparte de "orden creada"
        // para no enviarle dos mensajes al cliente en el mismo
        // instante; la bienvenida cubre ese momento.
        $contract->loadMissing('client', 'plan', 'branch');
        optional($contract->client)->notify(new ClientWelcome($contract));

        // Redirigir con un mensaje de éxito
        return redirect()->route('contracts.index')->with('success', 'Contrato creado exitosamente.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Contract $contract)
    {
        //
        // Obtener los datos
        $branches = Branch::all(); // Todas las sucursales
        $clients = Client::all(); // Todos los clientes
        $plans = Plan::all(); // Todos los planes disponibles
        $users = User::all(); // Todos los usuarios para asignar a un contrato
        // Las tablas de las pestañas usan DataTables del lado del
        // cliente: se entregan las colecciones completas (sin paginar)
        // con las relaciones precargadas para evitar consultas N+1.
        $invoices = Invoice::where('contract_id', $contract->id)
                ->orderBy('updated_at', 'desc')
                ->get();
        $additionalCharges = AditionalCharge::where('contract_id', $contract->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
        $technicalOrders = TechnicalOrder::where('contract_id', $contract->id)
            ->with(['assignedUser', 'createdBy', 'materials.material', 'contract.client', 'contract.plan'])
            ->orderBy('created_at', 'desc')
            ->get();
        $comments = $contract->comments()->with('user')->get();

        // Devolver la vista con los datos necesarios
        return view('gestisp.contracts.show', compact('branches', 'clients', 'plans', 'users', 'contract', 'invoices', 'additionalCharges', 'technicalOrders', 'comments'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Contract $contract)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Contract $contract)
    {
        if (isset($request->neighborhood) || isset($request->address) || isset($request->home_type) || isset($request->social_stratum) ){
            $contract->update([
                'neighborhood' => $request->neighborhood,
                'address' => $request->address,
                'home_type' => $request->home_type,
                'social_stratum' => $request->social_stratum,
                'department' => $request->department,
                'municipality' => $request->municipality,
            ]);
        }elseif (isset($request->plan_id) || isset($request->permanence_clause)){
            $contract->update([
                'plan_id' => $request->plan_id,
                'permanence_clause' => $request->permanence_clause,
            ]);
        }else{
            $contract->update([
                'nap_port' => $request->nap_port,
                'cpe_sn' => $request->cpe_sn,
                'user_pppoe' => $request->user_pppoe,
                'password_pppoe' => $request->password_pppoe,
                'ssid_wifi' => $request->ssid_wifi,
                'password_wifi' => $request->password_wifi,
            ]);
        }


        return redirect()->back()->with('success', 'Datos del contrato actualizados');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Contract $contract)
    {
        //
    }

    public function export()
    {
        //Función para exportar los datos de los clientes a un excel
        return (new ContractsExport)->download('listado_de_contratos.xlsx');
    }
}
