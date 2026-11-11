<?php

namespace App\Http\Controllers;

use App\Billing\Enums\DiscountType;
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
        $this->middleware('check.permission:contracts.edit')->only('edit', 'update', 'descuento');
        $this->middleware('check.permission:contracts.destroy')->only('destroy');
        $this->middleware('check.permission:contracts.show')->only('show');
        $this->middleware('check.permission:contracts.export')->only('export', 'exportFiltered');
        $this->middleware('check.permission:contracts.show')->only('diagnostics');
        // Imprimir el contrato es verlo: quien puede abrir la ficha
        // puede sacar el documento que el cliente firma.
        $this->middleware('check.permission:contracts.show')->only('contratoPdf');
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
            // Solo los planes que todavia se venden: dar de alta con
            // uno retirado es como se repuebla un plan que se queria
            // dejar morir.
            'planes' => Plan::activos()->disponibles()->orderBy('name')->get(),
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
        // `disponibles()` trae los de la EMPRESA mas los propios de
        // las sedes alcanzables. La regla vive en SharedAcrossBranches:
        // repetirla aqui es como una pantalla se queda sin el catalogo
        // compartido y nadie se entera.
        $plans = Plan::activos()
            ->disponiblesEn($contexto->branchIds())
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

        return view('gestisp.contracts.create', compact(
            'clients', 'plans', 'users', 'client',
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
     * Lo que el formulario de alta puede escribir en un contrato.
     *
     * Todo lo que no esté aquí —el estado, el número, el descuento,
     * las fechas de facturación— tiene su propio camino, con sus
     * propias comprobaciones, y no entra por el alta aunque venga en
     * la petición. Ver el comentario de `store()`.
     */
    private const CAMPOS_DEL_ALTA = [
        'branch_id', 'affinity_group_id', 'client_id', 'plan_id', 'user_id',
        'department', 'municipality', 'neighborhood', 'address',
        'home_type', 'social_stratum', 'permanence_clause',
        'nap_port', 'cpe_sn', 'user_pppoe', 'password_pppoe',
        'ssid_wifi', 'password_wifi', 'comment',
    ];

    /**
     * El plan tiene que existir y estar disponible en la sucursal.
     *
     * Compartida entre el alta y el cambio de plan. Antes solo la
     * tenía el alta: el modal de «Datos del servicio» escribía
     * `plan_id` tal cual llegaba, así que por ahí se podía dejar el
     * contrato SIN plan —que es justo lo que el alta impide, porque un
     * contrato sin plan no factura—, ponerle un plan exclusivo de otra
     * sede, o mandar un id inexistente y recibir un error 500 de clave
     * foránea.
     *
     * `$planActual` es la excepción de siempre: guardar el modal sin
     * tocar el plan no puede fallar porque el que ya tenía se retiró o
     * es de cuando las reglas eran otras.
     */
    private function validarPlan(Request $request, int $branchId, ?int $planActual = null): void
    {
        $request->validate([
            'plan_id' => [
                'required',
                // Los `where` anidados NO sobran: sin el de fuera, el
                // `orWhere` se saldría del paréntesis y anularía la
                // condición de la sucursal — cualquier plan valdría.
                Rule::exists('plans', 'id')->where(
                    fn ($q) => $q->where(function ($w) use ($branchId, $planActual) {
                        $w->whereNull('branch_id')->orWhere('branch_id', $branchId);

                        if ($planActual) {
                            $w->orWhere('id', $planActual);
                        }
                    }),
                ),
            ],
            'permanence_clause' => ['nullable', 'integer', 'min:0'],
        ], [
            'plan_id.required' => 'Elija el plan de servicio: sin plan el contrato no tiene precio '
                . 'ni servicios que facturar.',
            'plan_id.exists' => 'Ese plan no pertenece a la sucursal en la que se registra el contrato.',
            'permanence_clause.integer' => 'La cláusula de permanencia va en meses, como número entero.',
            'permanence_clause.min' => 'La cláusula de permanencia no puede ser negativa.',
        ]);
    }

    /**
     * Los datos técnicos, contra el tamaño real de sus columnas.
     *
     * Ninguno es obligatorio —un contrato se da de alta antes de
     * instalarse— pero todos tienen un tope, y nadie lo comprobaba:
     * `cpe_sn` es un varchar(20), y un serial de 21 caracteres pasaba
     * de largo hasta MySQL, que respondía con un «Data too long» y la
     * pantalla con un error 500. El técnico que pega un serial con
     * espacios de más merece un mensaje, no una página de error.
     */
    private function validarDatosTecnicos(Request $request): void
    {
        $request->validate([
            'cpe_sn' => ['nullable', 'string', 'max:20'],
            'user_pppoe' => ['nullable', 'string', 'max:255'],
            'password_pppoe' => ['nullable', 'string', 'max:255'],
            'ssid_wifi' => ['nullable', 'string', 'max:255'],
            'password_wifi' => ['nullable', 'string', 'max:255'],
            'nap_port' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:255'],
        ], [
            'cpe_sn.max' => 'El serial del equipo no puede pasar de 20 caracteres. '
                . 'Revise que no se haya pegado con espacios o texto de más.',
            'user_pppoe.max' => 'El usuario PPPoE es demasiado largo (máximo 255 caracteres).',
            'password_pppoe.max' => 'La contraseña PPPoE es demasiado larga (máximo 255 caracteres).',
            'ssid_wifi.max' => 'El nombre de la red wifi es demasiado largo (máximo 255 caracteres).',
            'password_wifi.max' => 'La contraseña del wifi es demasiado larga (máximo 255 caracteres).',
            'comment.max' => 'El comentario no puede pasar de 255 caracteres.',
        ]);
    }

    /**
     * Los datos de la residencia que la tabla exige.
     *
     * `neighborhood`, `address` y `home_type` son NOT NULL en
     * `contracts`. Sin esto, dejar el barrio en blanco no daba un
     * mensaje: daba un error 500 con «Column 'neighborhood' cannot be
     * null», y quien lo veía no tenía forma de saber que le faltaba
     * escribir el barrio.
     *
     * Esta en un metodo aparte porque el alta y la edicion guardan los
     * MISMOS campos por dos caminos distintos: validarlo solo en el
     * alta habria dejado la edicion reventando igual.
     *
     * `address` no tiene campo propio en el formulario: se arma por
     * partes (ver Direccion y ComponerDirecciones) y llega vacia si no
     * se escribio ninguna. El parcial ya recoge los errores de
     * `address`, asi que el mensaje sale junto a los campos.
     *
     * NO se exige mas de lo que la tabla pide: departamento, municipio
     * y estrato admiten nulo, y hay contratos importados sin ellos.
     * Volverlos obligatorios aqui habria impedido guardar cualquier
     * cambio en esos contratos.
     */
    private function validarResidencia(Request $request): void
    {
        $request->validate([
            'neighborhood' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            // Sin lista cerrada a proposito: los contratos importados
            // traen valores que no estan en el desplegable, y con una
            // regla `in` no se podria ni abrir el modal para
            // corregirlos.
            'home_type' => ['required', 'string', 'max:255'],
            // Sin `string`: el desplegable manda "3" pero hay llamadas
            // que mandan el 3 entero, y la columna es de texto. El
            // `max` está solo para que un valor absurdamente largo no
            // acabe en un 1406 («data too long»), que es otro 500.
            'social_stratum' => ['nullable', 'max:20'],
            'department' => ['nullable', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
        ], [
            'neighborhood.required' => 'Escriba el barrio o la vereda de la residencia.',
            'address.required' => 'Escriba la dirección de la residencia: elija el tipo de vía '
                . 'y complete la nomenclatura.',
            'home_type.required' => 'Elija el tipo de vivienda.',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, ContractGeolocator $geolocator)
    {
        $this->validarResidencia($request);

        // El cliente no se elige en el formulario: se llega aqui desde
        // su ficha y viaja en un campo oculto. Se comprueba igual
        // porque sin cliente el contrato tampoco se puede insertar, y
        // el 500 era el mismo que con el barrio.
        //
        // Solo que exista: NO se filtra por empresa. Hay clientes
        // importados con `company_id` nulo, y exigirlo aqui habria
        // dejado a esos clientes sin poder contratar — un bloqueo peor
        // que el error que se esta arreglando.
        $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
        ], [
            'client_id.required' => 'Falta el cliente del contrato: vuelva a abrir el alta desde su ficha.',
            'client_id.exists' => 'Ese cliente no existe.',
        ]);

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
        // Ademas tiene que estar DISPONIBLE en esa sucursal: o es del
        // catalogo de la empresa (`branch_id` nulo) o es propio de esa
        // sede. La pantalla ya esconde los demas, pero eso es ayuda
        // visual: sin esta comprobacion se podria asignar por POST a un
        // contrato de Bogota un plan exclusivo de Medellin.
        //
        // Los dos `where` anidados NO sobran. Sin el de fuera, el
        // `orWhere` se saldria del parentesis y anularia la condicion
        // de la sucursal — cualquier plan valdria.
        $this->validarPlan($request, $branchId);
        $this->validarDatosTecnicos($request);

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
            // SOLO LO QUE EL FORMULARIO DE ALTA TIENE DERECHO A ESCRIBIR.
            //
            // Esto era `$request->except([...coordenadas])`: todo lo
            // demás que llegara en el POST entraba al contrato, y el
            // `$fillable` incluye el estado, el número, el descuento y
            // la fecha de inicio de facturación. Con una petición
            // manipulada se podía crear un contrato que naciera
            // «Activo» sin instalarse, con un descuento del 500 % —que
            // la acción de descuentos rechaza porque deja la factura
            // en negativo— o que no empezara a facturarse hasta 2099.
            //
            // Una lista de lo que SÍ se admite no envejece mal: un
            // campo nuevo en el modelo queda fuera hasta que alguien
            // decida a propósito que el alta puede escribirlo. Con la
            // lista de exclusiones pasaba al revés.
            //
            // Las coordenadas siguen fuera: entran después por
            // ContractGeolocator, que es quien anota QUIÉN ubicó,
            // CUÁNDO y CON QUÉ, y lo deja en la trazabilidad.
            $nuevo = Contract::create($request->only(self::CAMPOS_DEL_ALTA));

            app(ContractNumberGenerator::class)->asignar($nuevo);

            // LA ORDEN DE INSTALACIÓN NACE CON EL CONTRATO, O NO NACE
            // NINGUNO DE LOS DOS.
            //
            // Estaba fuera de la transacción. Si fallaba —y basta con
            // que falte un dato obligatorio de la orden— el contrato
            // quedaba creado y numerado pero sin orden de instalación:
            // nadie lo tenía en su lista de pendientes y el cliente se
            // quedaba esperando a un técnico que no sabía que existía.
            TechnicalOrder::create([
                'contract_id' => $nuevo->id,
                // Hereda la del contrato: la orden es para instalar ESE
                // servicio, no puede estar en otra sede.
                'branch_id' => $nuevo->branch_id,
                'created_by' => Auth::user()->id,
                'type' => 'Servicio',
                'status' => 'Pendiente',
                'detail' => 'Instalación de servicio (creación automática)',
                'initial_comment' => 'Instalación del servicio',
            ]);

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

        // Bienvenida al cliente por correo y WhatsApp. Va en cola:
        // no demora la creación del contrato. La orden de instalación
        // automática NO dispara un aviso aparte de "orden creada"
        // para no enviarle dos mensajes al cliente en el mismo
        // instante; la bienvenida cubre ese momento.
        $contract->loadMissing('client', 'plan', 'branch');
        optional($contract->client)->notify(new ClientWelcome($contract));

        // Redirigir con un mensaje de éxito
        // A la ficha del contrato recién creado y con su contrato en
        // pantalla: lo primero que hay que hacer con un alta es
        // imprimirlo y firmarlo.
        return redirect()->route('contracts.show', $contract)
            ->with('abrir_contrato_pdf', true)
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
        // Planes ACTIVOS para el desplegable de cambio. El plan actual
        // se añade aunque este retirado: si no, al abrir el modal
        // apareceria seleccionado otro distinto y guardar sin querer le
        // cambiaria el plan —y con el, lo que se le factura cada mes—.
        // Es el mismo cuidado que con los grupos de afinidad, unas
        // lineas mas abajo.
        $plans = Plan::activos()->orderBy('name')->get();

        if ($contract->plan_id && !$plans->contains('id', $contract->plan_id)) {
            $plans = $plans
                ->push($contract->plan)
                ->filter()
                ->sortBy('name')
                ->values();
        }
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
     * Pone o quita el descuento del contrato.
     *
     * Va aparte de update() a proposito: ahi las ramas se eligen por
     * que campos trae el formulario, y meter el descuento en esa cadena
     * seria hacerlo depender de adivinar cual de las pantallas envio.
     *
     * Quitar el descuento tambien REINICIA el contador: si manana se le
     * pone otro de dos meses, son dos meses nuevos, no los que le
     * quedaran del anterior.
     */
    public function descuento(Request $request, Contract $contract)
    {
        $datos = $request->validate([
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => 'nullable|numeric|min:0',
            'discount_months' => 'nullable|integer|min:1|max:60',
            'discount_reason' => 'nullable|string|max:255',
        ], [
            'discount_value.numeric' => 'El valor del descuento debe ser un numero.',
            'discount_months.*' => 'Los meses del descuento deben estar entre 1 y 60.',
        ]);

        $quitar = empty($datos['discount_type']) || (float) ($datos['discount_value'] ?? 0) <= 0;

        if ($quitar) {
            $contract->update([
                'discount_type' => null,
                'discount_value' => null,
                'discount_months' => null,
                'discount_applied' => 0,
                'discount_reason' => null,
            ]);

            return back()->with('success', 'Se quito el descuento del contrato.');
        }

        // Un porcentaje mayor que 100 dejaria la factura en negativo.
        if ($datos['discount_type'] === DiscountType::Porcentaje->value
            && (float) $datos['discount_value'] > 100) {
            return back()->with('error', 'Un descuento en porcentaje no puede pasar del 100%.');
        }

        $contract->update($datos + [
            'discount_months' => $datos['discount_months'] ?? null,
            // Empieza de cero: es un descuento nuevo.
            'discount_applied' => 0,
        ]);

        return back()->with('success', sprintf(
            'Descuento aplicado: %s%s.',
            $datos['discount_type'] === DiscountType::Porcentaje->value
                ? $datos['discount_value'] . '%'
                : '$' . number_format((float) $datos['discount_value'], 2, ',', '.'),
            $datos['discount_months'] ? " durante {$datos['discount_months']} mes(es)" : ' sin limite de meses',
        ));
    }

    /**
     * El contrato de servicios en PDF, para imprimir y firmar.
     *
     * Es el formato único que exige la CRC: el articulado es el mismo
     * para todos y lo que cambia son los datos del suscriptor, el plan
     * y la permanencia. Se genera cuando alguien lo pide y no se guarda
     * en disco: si mañana cambia un dato del cliente, el documento que
     * se imprime tiene que salir con el dato de hoy.
     */
    public function contratoPdf(Request $request, Contract $contract)
    {
        abort_unless(
            app(CurrentContext::class)->permiteSucursal($contract->branch_id),
            403,
            'Ese contrato pertenece a otra sucursal.',
        );

        $contract->loadMissing(['client', 'plan.services', 'branch']);
        $branch = $contract->branch;

        $pdf = \App\Support\PdfBranding::make('gestisp.contracts.pdf', [
            'contract' => $contract,
            'branch' => $branch,
            'company' => $branch?->company,
            'logoPath' => \App\Support\PdfBranding::logoPath($branch),
        ]);

        $nombre = 'contrato-' . str_replace([' ', '/'], '-', (string) $contract->numero_visible) . '.pdf';

        // Por defecto se muestra —el modal lo abre en un iframe— y solo
        // se descarga si lo piden: guardar un PDF que solo se iba a leer
        // llena la carpeta de descargas de archivos repetidos.
        return $request->boolean('descargar') ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Contract $contract)
    {
        // QUÉ FORMULARIO ES SE DECIDE POR LOS CAMPOS QUE TRAE, NO POR SI
        // VIENEN RELLENOS.
        //
        // Esto usaba `isset()`, que es falso cuando el campo llega
        // vacío. Los tres modales de la ficha comparten esta acción, y
        // un modal con sus campos en blanco no se reconocía como suyo:
        // la petición seguía de largo hasta la última rama, la de datos
        // técnicos, que escribe lo que reciba. Guardar «Datos del
        // servicio» con el plan y la permanencia vacíos dejaba en NULO
        // el serial del equipo, el usuario y la clave PPPoE y el wifi
        // del contrato — un formulario borrando los datos de otro, sin
        // un solo aviso.
        //
        // Con `has()` cada modal cae en su rama aunque venga vacío, y
        // es SU validación la que le dice qué falta.
        if ($request->hasAny(['neighborhood', 'address', 'home_type', 'social_stratum'])) {
            // El mismo 500 que el alta: borrar el barrio en el modal
            // de residencia intentaba escribir NULL en una columna que
            // no lo admite.
            $this->validarResidencia($request);

            $contract->update([
                'neighborhood' => $request->neighborhood,
                'address' => $request->address,
                'home_type' => $request->home_type,
                'social_stratum' => $request->social_stratum,
                'department' => $request->department,
                'municipality' => $request->municipality,
            ]);
        } elseif ($request->hasAny(['plan_id', 'permanence_clause'])) {
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

            // El plan pasa por la MISMA regla que en el alta. Esta rama
            // lo escribía tal cual llegaba: vacío dejaba el contrato sin
            // plan —y sin plan no factura—, y un id inexistente era un
            // error 500 de clave foránea.
            $this->validarPlan($request, (int) $contract->branch_id, $contract->plan_id);

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
            // Contra el tamaño real de las columnas: un serial de 21
            // caracteres en un varchar(20) era un error 500.
            $this->validarDatosTecnicos($request);

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
    /**
     * Los contratos no se eliminan.
     *
     * Este método estaba vacío: la ruta existía, y quien llegara a
     * ella recibía una página en blanco sin saber si había pasado
     * algo. No hay botón que la llame, pero una ruta que responde con
     * nada es una pantalla en blanco esperando a que alguien la pise.
     *
     * Un contrato con historia —facturas, pagos, órdenes— no se borra:
     * se RETIRA con una orden, que deja constancia de quién lo pidió y
     * libera el puerto, la cuenta PPPoE y la ONT (ver
     * ContractDecommissioner). La base además lo impide desde que las
     * facturas dejaron de borrarse en cascada.
     */
    public function destroy(Contract $contract)
    {
        return back()->with(
            'error',
            'Los contratos no se eliminan: tienen facturas, pagos y órdenes que son su historial. '
            . 'Para dar de baja este contrato, cree una orden de retiro de servicio.',
        );
    }

    public function export()
    {
        //Función para exportar los datos de los clientes a un excel
        return (new ContractsExport)->download('listado_de_contratos.xlsx');
    }
}
