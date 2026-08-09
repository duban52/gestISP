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
use App\Services\ContractDiagnostics;
use App\Services\ContractGeolocator;
use App\Services\ContractNumberGenerator;
use App\Services\ContractQuery;
use App\Support\ColombiaLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

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
        $this->middleware('check.permission:contracts.export')->only('export', 'exportFiltered');
        $this->middleware('check.permission:contracts.show')->only('diagnostics');
    }
    /**
     * Display a listing of the resource.
     */
    /**
     * Listado de contratos con filtros combinables.
     *
     * Toda la lógica vive en App\Services\ContractQuery, que también
     * usa la exportación: así el Excel contiene EXACTAMENTE lo que se
     * está viendo en pantalla, sin dos juegos de filtros que puedan
     * separarse con el tiempo.
     *
     * Las columnas visibles las elige el usuario; el catálogo está en
     * el mismo servicio y se valida contra él, porque las claves
     * llegan del navegador.
     */
    public function index(Request $request, ContractQuery $consulta)
    {
        $filtros = $request->all();

        $contracts = $consulta->construir($filtros)->get();

        return view('gestisp.contracts.index', [
            'contracts' => $contracts,
            'columnas' => ContractQuery::columnas(),
            'columnasActivas' => ContractQuery::columnasValidas($request->input('columnas')),
            'planes' => Plan::where('branch_id', session('branch_id'))->orderBy('name')->get(),
            // Para el filtro por caja. Solo el código y el nombre: no
            // hacen falta los puertos y son cientos de cajas.
            'cajasNap' => \App\Models\NapBox::deSucursal()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'estados' => \App\Billing\Enums\ContractStatus::cases(),
            'filtros' => $filtros,
            // Totales de lo filtrado: es lo primero que se mira al
            // sacar un listado de cartera.
            'totalSaldo' => (float) $contracts->sum('saldo_pendiente'),
        ]);
    }

    /**
     * Exporta a Excel el listado tal como está filtrado.
     *
     * Recibe los mismos parámetros que el listado y los pasa por el
     * mismo servicio: lo que se descarga no puede diferir de lo que
     * se ve.
     */
    public function exportFiltered(Request $request, ContractQuery $consulta)
    {
        $columnas = ContractQuery::columnasValidas($request->input('columnas'));
        $contratos = $consulta->construir($request->all())->get();

        app(\App\Services\Audit\AuditLogger::class)->action(
            'contracts.exported',
            sprintf('Exportó un listado de %d contrato(s) con %d columna(s)', $contratos->count(), count($columnas)),
            [
                'registros' => $contratos->count(),
                'columnas' => $columnas,
                'filtros' => array_filter($request->except(['columnas', '_token'])),
            ],
            null,
            'contratos',
        );

        return Excel::download(
            new \App\Exports\ContractsFilteredExport($contratos, $columnas),
            'contratos-' . now()->format('Y-m-d') . '.xlsx',
        );
    }

    /**
     * Diagnóstico rápido de la conexión (JSON, para la ficha).
     *
     * Va por AJAX y no dentro de show() porque consultar el Mikrotik
     * es una llamada de red que puede tardar o fallar: la ficha del
     * contrato tiene que abrir al instante y este bloque llenarse
     * después. Si el router no responde, se dice y ya.
     *
     * NO se registra en la trazabilidad: es una consulta de lectura
     * que dispara la propia pantalla al abrirse, y anotarla llenaría
     * la bitácora de ruido sin contar nada que hiciera una persona.
     */
    public function diagnostics(Contract $contract, ContractDiagnostics $diagnostico)
    {
        abort_if((int) $contract->branch_id !== (int) session('branch_id'), 403);

        return response()->json($diagnostico->para($contract));
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
    public function store(Request $request, ContractGeolocator $geolocator)
    {
        // La ubicación de la vivienda es OPCIONAL: se valida aparte
        // para poder rechazar una coordenada imposible sin impedir dar
        // de alta un contrato que todavía no se ha ido a ubicar.
        $location = $request->validate([
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'location_source' => 'nullable|string|in:mapa,dispositivo,orden',
        ], [
            'latitude.required_with' => 'Faltó la latitud: vuelva a marcar el punto sobre el mapa.',
            'longitude.required_with' => 'Faltó la longitud: vuelva a marcar el punto sobre el mapa.',
        ]);

        $request->merge([
            'user_id' => Auth::user()->id,
            'branch_id' => session('branch_id')
        ]);


        // Crear el contrato con su número consecutivo de sucursal.
        // Va en una transacción porque el generador bloquea la fila de
        // la sucursal para que dos altas simultáneas no repitan número.
        $contract = DB::transaction(function () use ($request) {
            // Las coordenadas se excluyen del alta masiva: entran
            // después por ContractGeolocator, que es quien anota QUIÉN
            // ubicó, CUÁNDO y CON QUÉ, y lo deja en la trazabilidad.
            $nuevo = Contract::create($request->except(['latitude', 'longitude', 'location_source']));

            app(ContractNumberGenerator::class)->asignar($nuevo);

            return $nuevo;
        });

        // Fuera de la transacción a propósito: una coordenada rara no
        // puede tumbar el alta de un contrato que por lo demás está
        // bien. Como mucho el contrato nace sin ubicar, y se avisa.
        $locationWarning = null;

        if (filled($location['latitude'] ?? null)) {
            try {
                $geolocator->locate(
                    $contract,
                    (float) $location['latitude'],
                    (float) $location['longitude'],
                    $location['location_source'] ?? Contract::LOCATION_SOURCE_MAP,
                );
            } catch (\RuntimeException $e) {
                $locationWarning = ' ' . $e->getMessage();
            }
        }

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
        return redirect()->route('contracts.index')
            ->with('success', 'Contrato creado exitosamente.' . $locationWarning);
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
                // Las retenciones se precargan porque el estado de
                // cuenta las muestra: sin ellas, una factura saldada
                // con retención se ve como "Pagada" con un pago menor
                // al total y parece un error.
                ->with('retentions')
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

        // Cajas NAP de la sucursal para el selector de datos técnicos.
        // Se precargan sus puertos con el contrato de cada uno porque
        // el formulario solo debe ofrecer los que están libres.
        $napBoxes = \App\Models\NapBox::deSucursal()
            ->with(['ports.contract'])
            ->orderBy('code')
            ->get();

        // La caja del puerto actual se precarga para poder enlazarla
        // desde los datos técnicos sin una consulta por cada visita.
        // locatedBy sale en la ficha de ubicación: quién marcó el punto
        // es lo que permite preguntarle si algo no cuadra.
        $contract->loadMissing('napPort.napBox', 'locatedBy');

        // Devolver la vista con los datos necesarios
        return view('gestisp.contracts.show', compact('branches', 'clients', 'plans', 'users', 'contract', 'invoices', 'additionalCharges', 'technicalOrders', 'comments', 'napBoxes'));
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
                'cpe_sn' => $request->cpe_sn,
                'user_pppoe' => $request->user_pppoe,
                'password_pppoe' => $request->password_pppoe,
                'ssid_wifi' => $request->ssid_wifi,
                'password_wifi' => $request->password_wifi,
            ]);

            // El puerto de NAP no es un campo más: ocupar o liberar uno
            // cambia la disponibilidad de la caja y queda en la
            // trazabilidad, así que pasa por el servicio del módulo.
            //
            // Si el puerto se ocupó entre que se abrió el formulario y
            // se guardó, el servicio avisa con un mensaje que se
            // entiende; el resto de datos técnicos ya quedó guardado, y
            // así se dice para que nadie crea que se perdió todo.
            try {
                $this->actualizarPuertoNap($request, $contract);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                // TRAMPA: los abort() de Laravel lanzan HttpException,
                // que EXTIENDE RuntimeException. Sin este catch primero,
                // el de abajo se tragaría el 403 de "esa caja es de otra
                // sucursal" y lo convertiría en un redirect con mensaje,
                // es decir, en un control de acceso que no controla nada.
                throw $e;
            } catch (\RuntimeException $e) {
                return redirect()->back()->with(
                    'error',
                    'Se guardaron los datos técnicos, pero no el puerto: ' . $e->getMessage(),
                );
            }
        }


        return redirect()->back()->with('success', 'Datos del contrato actualizados');
    }

    /**
     * Instala o saca el contrato de un puerto de caja NAP.
     *
     * Antes el campo "nap_port" era texto libre y cada quien lo escribía
     * a su manera, así que no servía para saber si una caja tenía cupo.
     * Ahora el formulario manda el id del puerto y aquí se delega en
     * OdnManager, que es quien valida ocupación, mantiene el texto
     * legible en sintonía y deja el rastro en trazabilidad.
     *
     * Si el formulario no trae el campo (por ejemplo, una pantalla
     * antigua que solo actualiza el wifi) no se toca nada: solo se
     * actúa cuando el dato viene de verdad.
     */
    private function actualizarPuertoNap(Request $request, Contract $contract): void
    {
        if (!$request->has('nap_port_id')) {
            return;
        }

        $manager = app(\App\Services\OdnManager::class);
        $puertoId = $request->input('nap_port_id');

        // Vacío = "sin caja asignada": se libera el que tuviera.
        if (blank($puertoId)) {
            $manager->liberarPuerto($contract);

            return;
        }

        $puerto = \App\Models\NapPort::with('napBox.network')->findOrFail($puertoId);

        // El id del puerto llega del navegador: se comprueba que la caja
        // sea de la sucursal activa (la caja hereda la sucursal de su
        // red) para que nadie pueda instalar un contrato en la red de
        // otra sede manipulando el formulario.
        abort_unless(
            (int) $puerto->napBox->network->branch_id === (int) session('branch_id'),
            403,
        );

        // Nada que hacer si ya está en ese mismo puerto: evita una
        // entrada de trazabilidad por cada guardado del formulario.
        if ((int) $contract->nap_port_id === (int) $puerto->id) {
            return;
        }

        $manager->asignarPuerto($contract, $puerto);
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
