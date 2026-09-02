<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Models\Client;
use App\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Tenancy\CurrentContext;
use Illuminate\Validation\Rule;

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
    /**
     * Buscador de clientes.
     *
     * Un único cuadro de búsqueda que consulta TODOS los datos del
     * cliente a la vez (documento, nombres, apellidos, teléfonos,
     * correo y tipo), siempre dentro de la sucursal activa. Devuelve
     * todos los que coincidan, paginados, para poder elegir sobre
     * cuál actuar (ver contratos, editar o asignarle un contrato).
     *
     * La búsqueda viaja por la URL (GET) para que la paginación
     * funcione y el resultado se pueda guardar en favoritos.
     */
    public function searchView(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        // Sin término: solo se muestra el cuadro de búsqueda.
        if ($q === '') {
            return view('gestisp.clients.search', ['q' => '', 'clients' => null]);
        }

        // El cliente es de la EMPRESA: se busca en toda ella, no solo
        // en la sucursal desde la que se mira. Una persona puede tener
        // servicio en varias sedes y aqui hay que encontrarla igual.
        // El aislamiento entre empresas lo garantiza el global scope.
        $clients = Client::query()
            ->with('user', 'contracts')
            ->withCount('contracts')
            ->where(function ($sub) use ($q) {
                // Coincidencia en cualquiera de los campos del cliente
                foreach (['identity_number', 'name', 'last_name', 'number_phone', 'aditional_phone', 'email', 'type_client'] as $campo) {
                    $sub->orWhere($campo, 'like', "%{$q}%");
                }

                // Y también contra el nombre completo, para que
                // "Juan Pérez" encuentre al cliente aunque nombre y
                // apellido estén en columnas distintas
                $sub->orWhereRaw("CONCAT(name, ' ', COALESCE(last_name, '')) LIKE ?", ["%{$q}%"]);
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('gestisp.clients.search', compact('q', 'clients'));
    }

    /**
     * Compatibilidad: el buscador antiguo enviaba por POST. Se
     * redirige al buscador nuevo (GET) conservando el término.
     */
    public function search(Request $request): RedirectResponse
    {
        $q = $request->input('q', $request->input('identity_number', ''));

        return redirect()->route('clients.searchView', ['q' => $q]);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Client::query();

        // Sin filtro de sucursal: el cliente pertenece a la empresa.
        // El global scope de BelongsToCompany ya acota a la empresa del
        // contexto, que es la barrera que importa.

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
        // El cliente NO necesita sucursal: pertenece a la empresa.
        // branch_id se guarda solo como "sucursal de origen" —donde se
        // dio de alta— y en panel consolidado queda vacio, porque ahi
        // no hay una activa. Exigirlo hacia imposible crear un cliente
        // desde el panel consolidado.
        //
        // El CONTRATO si es de una sucursal. Esa es la division: el
        // cliente es de la empresa, el servicio es de la sede.
        $contexto = app(CurrentContext::class);

        $request->merge([
            'user_id' => Auth::user()->id,
            'branch_id' => $contexto->branchId(),
            'company_id' => $contexto->companyId(),
        ]);



        $request->validate([
            'type_document' => 'required|string|max:50',
            // El mismo documento no puede repetirse DENTRO de la
            // empresa: seria la misma persona dos veces. En OTRA
            // empresa si puede existir, y de hecho debe: son
            // contribuyentes distintos con datos aislados.
            'identity_number' => [
                'required', 'string', 'max:20',
                Rule::unique('clients', 'identity_number')
                    ->where('company_id', $contexto->companyId())
                    ->where('type_document', $request->input('type_document')),
            ],
            'name' => 'required|string|max:40',
            'last_name' => 'required|string|max:40',
            'type_client' => 'required|string|max:40',
            'number_phone' => 'required|string|max:20',
            'aditional_phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'birthday' => 'nullable|date',
        ], [
            'identity_number.unique' =>
                'Ya existe un cliente con ese documento en esta empresa. '
                . 'Búsquelo en el listado en vez de crearlo otra vez.',
        ]);

        Client::create($request->all());

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
