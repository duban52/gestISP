<?php

namespace App\Http\Controllers;

use App\Exports\ClientsExport;
use App\Exports\ContractsExport;
use App\Models\AditionalCharge;
use App\Models\Branch;
use App\Models\Client;
use App\Billing\Services\TaxClassificationAdvisor;
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
use App\Tenancy\CurrentContext;
use Illuminate\Validation\Rule;

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
            'planes' => Plan::whereIn('branch_id', app(CurrentContext::class)->branchIds())->orderBy('name')->get(),
            // Los grupos son de la EMPRESA: el global scope ya los
            // acota, no hay que filtrar por sucursal. Se ofrecen todos
            // —incluidos los inactivos— porque este es un filtro de
            // busqueda: hay contratos que siguen en un grupo que dejo
            // de ofrecerse, y hay que poder encontrarlos.
            'gruposAfinidad' => \App\Models\AffinityGroup::ordenados()->get(),
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
        abort_if(!app(CurrentContext::class)->permiteSucursal($contract->branch_id), 403);

        return response()->json($diagnostico->para($contract));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Client $client)
    {
        $contexto = app(CurrentContext::class);

        // Los clientes son de la EMPRESA, no de la sucursal: el global
        // scope ya acota a la empresa del contexto y filtrar ademas por
        // sucursal escondia a quien se dio de alta en otra sede.
        $clients = Client::all();

        // Los planes SI son de la sucursal. Se traen los de todas las
        // sucursales alcanzables y la vista los filtra segun la que se
        // elija: con session('branch_id') a null —panel consolidado— el
        // desplegable salia vacio y no se podia crear ni un contrato.
        $plans = Plan::whereIn('branch_id', $contexto->branchIds())
            ->orderBy('name')
            ->get();

        $users = User::all(); // Todos los usuarios para asignar a un contrato

        // Los grupos de afinidad son de la EMPRESA: se ofrecen los
        // ACTIVOS —al dar de alta no tiene sentido ofrecer uno que ya
        // no se usa— y viene marcado el predeterminado.
        $gruposAfinidad = \App\Models\AffinityGroup::activos()->ordenados()->get();
        $grupoPorDefecto = \App\Models\AffinityGroup::porDefectoDelContexto();

        // Solo se pregunta la sucursal cuando hay mas de una alcanzable.
        $hayQueElegirSucursal = $contexto->hayQueElegirSucursal();
        $sucursales = $hayQueElegirSucursal ? $contexto->sucursalesElegibles() : collect();

        // Devolver la vista con los datos necesarios
        $colombiaLocations = ColombiaLocations::departmentsWithMunicipalities();

        return view('gestisp.contracts.create', compact(
            'clients', 'plans', 'users', 'client', 'colombiaLocations',
            'hayQueElegirSucursal', 'sucursales',
            'gruposAfinidad', 'grupoPorDefecto'
        ));
    }

    /**
     * Con que grupo nace el contrato.
     *
     * Si el formulario trae uno, tiene que ser de la empresa y estar
     * activo — se comprueba con una regla de validacion y no a mano,
     * para que el error salga junto al campo. El global scope de
     * empresa ya impide que valga uno de otra empresa: `Rule::exists`
     * consulta la tabla directamente, asi que la condicion de empresa
     * se pone explicita aqui.
     *
     * Si no trae ninguno, se asume el predeterminado. Y si la empresa
     * no tiene predeterminado, se deja NULO en vez de fallar: el
     * contrato se puede dar de alta igual y el listado tiene un filtro
     * "sin grupo asignado" para encontrarlos y clasificarlos despues.
     * Bloquear el alta por una configuracion que falta seria peor que
     * el problema que evita.
     */
    private function grupoParaElContrato(Request $request): ?int
    {
        $companyId = app(CurrentContext::class)->companyId();

        $request->validate([
            'affinity_group_id' => [
                'nullable',
                Rule::exists('affinity_groups', 'id')
                    ->where('company_id', $companyId)
                    ->where('active', true),
            ],
        ], [
            'affinity_group_id.exists' => 'Ese grupo de afinidad no existe en esta empresa o esta inactivo.',
        ]);

        if ($request->filled('affinity_group_id')) {
            return (int) $request->input('affinity_group_id');
        }

        return \App\Models\AffinityGroup::porDefectoDelContexto()?->id;
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

        // La sucursal del contrato: en panel consolidado viene del
        // formulario y se comprueba contra las del usuario; con una
        // sola alcanzable se asume; en modo independiente manda la
        // activa y lo que llegue se ignora.
        $branchId = app(CurrentContext::class)
            ->branchParaEscritura($request->input('branch_id'));

        // EL PLAN ES OBLIGATORIO.
        //
        // De el salen el precio, los servicios que se facturan y el IVA
        // de cada uno. Un contrato sin plan se daba de alta sin ruido y
        // luego no facturaba nada: no aparecia en la corrida mensual,
        // asi que el cliente quedaba con servicio y sin factura, y solo
        // se notaba al cuadrar el mes.
        //
        // Ademas tiene que ser DE ESA sucursal. La pantalla ya esconde
        // los demas, pero eso es ayuda visual: sin esta comprobacion se
        // podria asignar a un contrato de Bogota un plan de Medellin, y
        // el precio saldria del sitio equivocado.
        $request->validate([
            'plan_id' => [
                'required',
                Rule::exists('plans', 'id')->where('branch_id', $branchId),
            ],
        ], [
            'plan_id.required' => 'Elija el plan de servicio: sin plan el contrato no tiene precio '
                . 'ni servicios que facturar.',
            'plan_id.exists' => 'Ese plan no pertenece a la sucursal en la que se registra el contrato.',
        ]);

        // EL GRUPO DE CONTRATO.
        //
        // Es lo que decide que documento emite este contrato: factura
        // electronica —con firma digital y numeracion autorizada— o
        // documento interno. Hoy esa decision todavia no tiene
        // consecuencias tecnicas, pero el contrato se clasifica desde
        // ya para no tener que clasificar la cartera entera a la
        // carrera cuando las tenga.
        //
        // Si no llega ninguno se asume el predeterminado de la empresa.
        // Preguntarlo cuando hay una sola respuesta posible seria un
        // campo de mas en cada alta.
        $grupoId = $this->grupoParaElContrato($request);

        $request->merge([
            'user_id' => Auth::user()->id,
            'branch_id' => $branchId,
            'affinity_group_id' => $grupoId,
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
            // Hereda la del contrato: la orden es para instalar ESE
            // servicio, no puede estar en otra sede.
            'branch_id' => $contract->branch_id,
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
            ->with('success', 'Contrato creado exitosamente.' . $locationWarning)
            // Avisa, no bloquea: puede haber razones legitimas para que
            // el IVA no siga al estrato —un contrato empresarial en una
            // direccion de estrato bajo—, y quien decide la
            // clasificacion es quien lleva la contabilidad.
            ->with('avisos_fiscales', app(TaxClassificationAdvisor::class)->avisos($contract));
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
        // Grupos ACTIVOS para el desplegable de cambio. El grupo actual
        // se añade aunque este inactivo: si no, al abrir el modal
        // apareceria seleccionado otro distinto y guardar sin querer le
        // cambiaria la modalidad de facturacion al contrato.
        $gruposAfinidad = \App\Models\AffinityGroup::activos()->ordenados()->get();

        if ($contract->affinity_group_id && !$gruposAfinidad->contains('id', $contract->affinity_group_id)) {
            $gruposAfinidad = $gruposAfinidad
                ->push($contract->affinityGroup)
                ->filter()
                ->sortBy('sort_order')
                ->values();
        }
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
        return view('gestisp.contracts.show', compact('branches', 'clients', 'plans', 'users', 'contract', 'invoices', 'additionalCharges', 'technicalOrders', 'comments', 'napBoxes', 'gruposAfinidad'));
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
            // El grupo se valida aparte porque cambiarlo NO es como
            // cambiar el plan: cambia por que camino saldra la factura
            // de este contrato de aqui en adelante. Tiene que ser de la
            // empresa, y se comprueba aunque la pantalla ya lo limite —
            // lo que llega de un formulario no es de fiar.
            //
            // Se admite un grupo inactivo SOLO si es el que ya tenia:
            // guardar el modal sin tocar ese campo no puede fallar por
            // algo que se desactivo despues de asignarlo.
            $request->validate([
                'affinity_group_id' => [
                    'nullable',
                    Rule::exists('affinity_groups', 'id')
                        ->where('company_id', app(CurrentContext::class)->companyId())
                        ->where(fn ($q) => $contract->affinity_group_id
                            ? $q->where(fn ($sub) => $sub->where('active', true)
                                ->orWhere('id', $contract->affinity_group_id))
                            : $q->where('active', true)),
                ],
            ], [
                'affinity_group_id.exists' => 'Ese grupo de afinidad no existe en esta empresa o está inactivo.',
            ]);

            $cambios = [
                'plan_id' => $request->plan_id,
                'permanence_clause' => $request->permanence_clause,
            ];

            // Solo se toca si viene en la peticion: otros formularios
            // comparten esta rama y no mandan el grupo. Sin esto le
            // pondrian null y el contrato quedaria sin clasificar.
            if ($request->has('affinity_group_id')) {
                $cambios['affinity_group_id'] = $request->input('affinity_group_id') ?: null;
            }

            // El cambio queda en la trazabilidad por la auditoria
            // global: es un requisito del analisis fiscal, porque si un
            // contrato deja de facturar electronicamente hay que poder
            // responder quien lo decidio y cuando.
            $contract->update($cambios);
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


        return redirect()->back()
            ->with('success', 'Datos del contrato actualizados')
            ->with('avisos_fiscales', app(TaxClassificationAdvisor::class)->avisos($contract->fresh()));
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
            app(CurrentContext::class)->permiteSucursal($puerto->napBox->network->branch_id),
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
