<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Models\Client;
use App\Models\Contract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Support\PdfBranding;
use App\Tenancy\CurrentContext;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

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
        $this->middleware('check.permission:clients.export')->only('export', 'exportPdf');
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
        $clients = $this->aplicarFiltros($request)
            // El contrato y su plan vienen con el cliente: la columna de
            // contratos los pintaba vacios y, sin esto, cada fila seria
            // dos consultas mas.
            ->with(['contracts.plan', 'branch'])
            ->withCount('contracts')
            ->orderBy('name')
            ->paginate((int) $request->get('per_page', 15))
            ->withQueryString();

        return view('gestisp.clients.index', [
            'clients' => $clients,
            'resumen' => $this->resumen($request),
        ]);
    }

    /**
     * Excel de lo que se ve, no de toda la tabla.
     *
     * Antes era `Client::query()` a secas: bajaba la empresa entera sin
     * encabezados y sin respetar un solo filtro de la pantalla. Lo que
     * se descarga tiene que ser lo que se esta mirando — es el mismo
     * criterio que ya seguian los pagos y los movimientos de almacen.
     */
    public function export(Request $request)
    {
        return (new ClientsExport($this->aplicarFiltros($request)))
            ->download('clientes.xlsx');
    }

    /** El mismo listado, en PDF. */
    public function exportPdf(Request $request)
    {
        $clients = $this->aplicarFiltros($request)
            ->with(['contracts.plan'])
            ->withCount('contracts')
            ->orderBy('name')
            ->get();

        return PdfBranding::make('gestisp.clients.pdf', [
            'clients' => $clients,
            'resumen' => $this->resumen($request),
            'filtros' => $this->filtrosLegibles($request),
        ], landscape: true)->download('clientes.pdf');
    }

    /**
     * Los filtros del listado, en un solo sitio.
     *
     * Pantalla, Excel y PDF la comparten: asi lo que se descarga es
     * exactamente lo que se ve. Sin filtro de sucursal a proposito — el
     * cliente pertenece a la EMPRESA, y esa barrera ya la pone el
     * global scope de BelongsToCompany.
     */
    private function aplicarFiltros(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Client::query();

        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $valor = trim((string) $request->filter_value);

            match ($request->filter_field) {
                // Lista blanca: el campo viaja por la URL y sin esto
                // bastaria con escribir el nombre de otra columna.
                'identity_number', 'number_phone', 'email', 'type_client'
                    => $query->where($request->filter_field, 'like', "%{$valor}%"),

                // El nombre completo, para que «Juan Perez» encuentre al
                // cliente aunque nombre y apellido vivan en columnas
                // distintas.
                'name' => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$valor}%")
                    ->orWhere('last_name', 'like', "%{$valor}%")
                    ->orWhereRaw("CONCAT(name, ' ', COALESCE(last_name, '')) LIKE ?", ["%{$valor}%"])),

                // Por el numero de contrato: en el mostrador es lo unico
                // que trae el cliente apuntado en un papel.
                'contract_number' => $query->whereHas(
                    'contracts',
                    fn ($c) => $c->where('contract_number', 'like', "%{$valor}%"),
                ),

                default => null,
            };
        }

        // Con contratos, sin ellos, o con alguno en un estado concreto.
        match ($request->get('contratos')) {
            'si' => $query->has('contracts'),
            'no' => $query->doesntHave('contracts'),
            default => null,
        };

        if ($request->filled('estado')) {
            $query->whereHas('contracts', fn ($c) => $c->where('status', $request->estado));
        }

        return $query;
    }

    /**
     * Las cifras de la cabecera, sobre LO FILTRADO.
     *
     * Contarlas sobre toda la empresa daria un total que no explica la
     * tabla que se esta mirando.
     */
    private function resumen(Request $request): array
    {
        $base = $this->aplicarFiltros($request);

        return [
            'total' => (clone $base)->count(),
            'con_contrato' => (clone $base)->has('contracts')->count(),
            'sin_contrato' => (clone $base)->doesntHave('contracts')->count(),
            'juridicos' => (clone $base)->where('type_client', 'like', '%uridic%')->count(),
        ];
    }

    /** Lo que se filtro, en palabras, para el encabezado del PDF. */
    private function filtrosLegibles(Request $request): array
    {
        $etiquetas = [
            'name' => 'Nombre',
            'identity_number' => 'Documento',
            'number_phone' => 'Teléfono',
            'email' => 'Correo',
            'type_client' => 'Tipo de cliente',
            'contract_number' => 'N.º de contrato',
        ];

        $puestos = [];

        if ($request->filled('filter_field') && $request->filled('filter_value')) {
            $campo = $etiquetas[$request->filter_field] ?? $request->filter_field;
            $puestos[] = $campo . ': ' . $request->filter_value;
        }

        if ($request->get('contratos') === 'si') {
            $puestos[] = 'Solo con contrato';
        } elseif ($request->get('contratos') === 'no') {
            $puestos[] = 'Solo sin contrato';
        }

        if ($request->filled('estado')) {
            $puestos[] = 'Con un contrato ' . $request->estado;
        }

        return $puestos;
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
            // El CODIGO es lo que vale para la DIAN. Se valida contra
            // el catalogo y no contra una lista en el codigo: los
            // catalogos cambian por resolucion.
            'document_type_code' => [
                'required', 'string', 'max:5',
                Rule::exists('fiscal_catalogs', 'code')
                    ->where('catalog', \App\Models\FiscalCatalog::TIPO_DOCUMENTO)
                    ->where('active', true),
            ],
            // El texto libre se sigue guardando durante la transicion.
            'type_document' => 'nullable|string|max:50',
            'verification_digit' => 'nullable|string|size:1',
            'organization_type_code' => 'nullable|string|max:5',
            'fiscal_address' => 'nullable|string|max:255',
            'department_dane_code' => 'nullable|string|max:5',
            'municipality_dane_code' => 'nullable|string|max:5',
            'postal_code' => 'nullable|string|max:10',
            'country_code' => 'nullable|string|max:5',
            // El mismo documento no puede repetirse DENTRO de la
            // empresa: seria la misma persona dos veces. En OTRA
            // empresa si puede existir, y de hecho debe: son
            // contribuyentes distintos con datos aislados.
            'identity_number' => [
                'required', 'string', 'max:20',
                // Por el CODIGO y no por el texto: dos clientes con el
                // mismo numero y el mismo tipo son la misma persona, y
                // el texto libre podia escribirse de varias formas —
                // «Cedula de ciudadania» y «Cédula de ciudadanía» eran
                // dos tipos distintos para este unique.
                Rule::unique('clients', 'identity_number')
                    ->where('company_id', $contexto->companyId())
                    ->where('document_type_code', $request->input('document_type_code')),
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

        // El texto libre se sigue rellenando desde el catalogo durante
        // la transicion: hay pantallas y exportaciones que todavia lo
        // imprimen, y dejarlo vacio las dejaria en blanco. Es la misma
        // red de seguridad que branches.nit.
        $request->merge([
            'type_document' => \App\Models\FiscalCatalog::nombre(
                \App\Models\FiscalCatalog::TIPO_DOCUMENTO,
                $request->input('document_type_code'),
            ),
        ]);

        $cliente = Client::create($request->all());

        $this->guardarResponsabilidades($cliente, $request);

        return redirect()->action([ClientController::class, 'create'])
            ->with('success-create', 'Cliente creado con éxito');

    }

    /**
     * Guarda las responsabilidades fiscales marcadas.
     *
     * Se borran y se vuelven a crear en vez de comparar una a una:
     * son cuatro o cinco filas por cliente y la comparacion seria mas
     * codigo que valor. Va en transaccion para que no quede a medias.
     *
     * Solo se tocan si el formulario las mando: otros formularios
     * comparten este controlador y no las incluyen; sin esta
     * comprobacion se las borraria sin que nadie lo pidiera.
     */
    private function guardarResponsabilidades(Client $cliente, Request $request): void
    {
        if (!$request->has('tax_responsibilities')) {
            return;
        }

        $codigos = array_filter((array) $request->input('tax_responsibilities', []));

        DB::transaction(function () use ($cliente, $codigos) {
            $cliente->taxResponsibilities()->delete();

            foreach (array_unique($codigos) as $codigo) {
                $cliente->taxResponsibilities()->create(['responsibility_code' => $codigo]);
            }
        });
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
        // Los datos fiscales se validan aparte: son opcionales, asi
        // que quien solo viene a corregir un telefono no tiene que
        // rellenarlos. El numero de documento NO se edita — identifica
        // al cliente, y cambiarlo es crear otro.
        //
        // El TIPO de documento es distinto: si el cliente todavia no lo
        // tiene -uno de antes de la fase 8, que nacio sin codigo- se
        // puede fijar UNA vez, porque hasta ahora no habia ningun sitio
        // donde hacerlo y el informe de completitud fiscal lo exigia
        // sin que hubiera manera de resolverlo. En cuanto se guarda,
        // queda igual de bloqueado que el numero.
        $request->validate([
            'document_type_code' => [
                $client->document_type_code === null ? 'nullable' : 'prohibited',
                'string', 'max:5',
                Rule::exists('fiscal_catalogs', 'code')
                    ->where('catalog', \App\Models\FiscalCatalog::TIPO_DOCUMENTO)
                    ->where('active', true),
            ],
            'verification_digit' => 'nullable|string|size:1',
            'organization_type_code' => 'nullable|string|max:5',
            'fiscal_address' => 'nullable|string|max:255',
            'department_dane_code' => 'nullable|string|max:5',
            'municipality_dane_code' => 'nullable|string|max:5',
            'postal_code' => 'nullable|string|max:10',
            'country_code' => 'nullable|string|max:5',
        ]);

        //Actualizar datos
        $client->update([
           'number_phone' => $request->number_phone,
            'aditional_phone' => $request->aditional_phone,
            'email' => $request->email,
        ]);

        // Cada campo fiscal solo se toca si vino en la peticion: hay
        // formularios que comparten esta accion y no los incluyen, y
        // ponerlos a null borraria datos que nadie pidio borrar.
        $camposFiscales = [
            'verification_digit', 'organization_type_code', 'fiscal_address',
            'department_dane_code', 'municipality_dane_code', 'postal_code', 'country_code',
        ];

        // document_type_code entra en la misma lista, pero SOLO si el
        // cliente todavia no lo tenia: se completa una vez, no se edita.
        if ($client->document_type_code === null) {
            $camposFiscales[] = 'document_type_code';
        }

        $fiscales = array_filter(
            $request->only($camposFiscales),
            fn ($campo) => $request->has($campo),
            ARRAY_FILTER_USE_KEY,
        );

        if ($fiscales !== []) {
            // El texto libre se rellena tambien aqui cuando se fija el
            // tipo por primera vez, por la misma razon que en store():
            // hay pantallas y exportaciones que todavia lo imprimen.
            if (isset($fiscales['document_type_code'])) {
                $fiscales['type_document'] = \App\Models\FiscalCatalog::nombre(
                    \App\Models\FiscalCatalog::TIPO_DOCUMENTO,
                    $fiscales['document_type_code'],
                );
            }

            $client->update($fiscales);
        }

        $this->guardarResponsabilidades($client, $request);

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
