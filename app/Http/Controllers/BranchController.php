<?php

namespace App\Http\Controllers;

use App\Billing\Enums\BillingMode;
use App\Billing\Enums\ProrationMode;
use App\Models\Branch;
use App\Models\Company;
use App\Models\BranchBillingSetting;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Controlador de Sucursales (Branches)
 *
 * Gestiona el CRUD completo de las sucursales del sistema.
 * Cada sucursal agrupa clientes, contratos, OLTs, routers, cajas y almacenes,
 * funcionando como unidad de negocio independiente dentro de GestISP.
 */
class BranchController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     *
     * Cada acción del CRUD requiere el permiso correspondiente,
     * validado por el middleware check.permission.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:branches.index')->only('index');
        $this->middleware('check.permission:branches.create')->only('create', 'store');
        $this->middleware('check.permission:branches.edit')->only('edit', 'update');
        $this->middleware('check.permission:branches.destroy')->only('destroy');
    }

    /**
     * Lista todas las sucursales.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente, que se encarga de la
     * paginación, búsqueda y ordenamiento en el navegador.
     */
    public function index(): View
    {
        $branches = Branch::all();

        return view('gestisp.branches.index', compact('branches'));
    }

    /**
     * Muestra el formulario de creación de sucursal.
     */
    public function create(Request $request): View
    {
        return view('gestisp.branches.create', [
            // Al venir desde la ficha de una empresa llega elegida.
            'empresaElegida' => $request->integer('company') ?: null,
            'empresas' => Company::orderBy('legal_name')->get(),
            // La sucursal todavia no existe, asi que su configuracion de
            // facturacion son los valores por defecto.
            'facturacion' => BranchBillingSetting::DEFAULTS,
            'prorationModes' => ProrationMode::cases(),
            'billingModes' => BillingMode::cases(),
        ]);
    }

    /**
     * Guarda una nueva sucursal en la base de datos.
     *
     * Si el request incluye una imagen (logo de la sucursal),
     * se almacena en storage/app/public/branches y se guarda la ruta.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateBranch($request);

        // Almacenar la imagen si fue enviada
        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('branches', 'public');
        }

        // La configuracion de facturacion va en su propia tabla y se
        // valida aparte. Si el formulario no la manda —otra pantalla,
        // una peticion armada a mano— la sucursal nace con los valores
        // por defecto, igual que antes.
        $facturacion = $request->validate([
            'proration_mode' => ['sometimes', Rule::enum(ProrationMode::class)],
            'billing_mode' => ['sometimes', Rule::enum(BillingMode::class)],
            'billing_day' => [
                Rule::requiredIf(fn () => $request->input('billing_mode') === BillingMode::Automatico->value),
                'nullable', 'integer', 'min:1', 'max:31',
            ],
            'due_days' => 'sometimes|integer|min:1|max:90',
            'suspension_threshold' => 'sometimes|integer|min:1|max:12',
            'suspension_days' => 'sometimes|integer|min:1|max:90',
        ], [
            'billing_day.required' => 'Indique el día del mes en que debe facturarse.',
        ]);

        $sucursal = Branch::create($validated);

        if ($facturacion !== []) {
            // El dia solo tiene sentido en automatico.
            if (($facturacion['billing_mode'] ?? null) !== BillingMode::Automatico->value) {
                $facturacion['billing_day'] = null;
            }

            BranchBillingSetting::forBranch($sucursal->id)->update($facturacion);
        }

        // ACCESO A LA SUCURSAL RECIEN CREADA
        //
        // El acceso se concede POR SUCURSAL (user_branch), no por
        // empresa. Una sucursal sin usuarios es invisible: no sale en
        // los listados, no se puede elegir como contexto y no se puede
        // ni editar. Quien la crea suele necesitarla, asi que se le
        // ofrece marcado; pero es una casilla y no un automatismo,
        // porque conceder acceso es conceder acceso.
        if ($request->boolean('darme_acceso', true)) {
            $usuario = Auth::user();

            $usuario->branches()->syncWithoutDetaching([
                $sucursal->id => ['role_id' => session('current_role_id')],
            ]);
        }

        // Se vuelve a la ficha de la EMPRESA: es donde se ve la sucursal
        // nueva junto a sus hermanas. El listado de sucursales esta
        // acotado al contexto activo y, si la sucursal es de otra
        // empresa, ahi no aparecería.
        return redirect()
            ->route('companies.show', $sucursal->company_id)
            ->with('success', 'Sucursal creada exitosamente.');
    }

    /**
     * Muestra el detalle de una sucursal específica.
     */
    public function show(Branch $branch): View
    {
        return view('gestisp.branches.show', compact('branch'));
    }

    /**
     * Muestra el formulario de edición de una sucursal.
     */
    public function edit(Branch $branch): View
    {
        $empresas = Company::orderBy('legal_name')->get();

        // Configuración de facturación (se crea con los defaults
        // históricos si la sucursal aún no tiene)
        $billingSettings = BranchBillingSetting::forBranch($branch->id);
        $prorationModes = ProrationMode::cases();
        $billingModes = BillingMode::cases();

        return view('gestisp.branches.edit', compact('branch', 'billingSettings', 'prorationModes', 'billingModes', 'empresas'));
    }

    /**
     * Actualiza los datos de una sucursal existente.
     *
     * Si el usuario sube una nueva imagen, la anterior se elimina
     * del disco antes de guardar la nueva, evitando archivos huérfanos.
     */
    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $this->validateBranch($request, $branch->id);

        // Configuración de facturación de la sucursal (modo de
        // prorrateo, plazos y umbral de suspensión) — las reglas
        // que consumen los servicios de app/Billing/Services
        $billingValidated = $request->validate([
            'proration_mode' => ['required', Rule::enum(ProrationMode::class)],
            // `sometimes`: por esta ruta pasan tambien payloads que no
            // mandan la configuracion de facturacion. Exigirlo rompia
            // esa otra pantalla sin tener nada que ver con ella; si no
            // viene, el modo se queda como estaba.
            'billing_mode' => ['sometimes', Rule::enum(BillingMode::class)],
            // El día solo se exige —y solo se guarda— en automático:
            // dejarlo puesto en una sucursal que volvió a manual haría
            // creer que sigue programada.
            'billing_day' => [
                Rule::requiredIf(fn () => $request->input('billing_mode') === BillingMode::Automatico->value),
                'nullable', 'integer', 'min:1', 'max:31',
            ],
            'due_days' => 'required|integer|min:1|max:90',
            'suspension_threshold' => 'required|integer|min:1|max:12',
            'suspension_days' => 'required|integer|min:1|max:90',
        ], [
            'proration_mode.required' => 'Debe elegir el modo de facturación del primer mes.',
            'billing_day.required' => 'Indique el día del mes en que debe facturarse.',
            'billing_day.*' => 'El día de facturación debe estar entre 1 y 31.',
            'due_days.*' => 'Los días de plazo deben estar entre 1 y 90.',
            'suspension_threshold.*' => 'El umbral de suspensión debe estar entre 1 y 12 facturas.',
            'suspension_days.*' => 'Los días hasta el corte deben estar entre 1 y 90.',
        ]);

        // Reemplazar la imagen si se envió una nueva
        if ($request->hasFile('image')) {
            // Eliminar la imagen anterior del disco
            File::delete(public_path('storage/' . $branch->image));

            // Almacenar la nueva imagen
            $validated['image'] = $request->file('image')->store('branches', 'public');
        }

        $branch->update($validated);

        // Volver a manual borra el dia: dejarlo puesto haria creer que
        // la sucursal sigue programada cuando ya no lo esta.
        if (array_key_exists('billing_mode', $billingValidated)
            && $billingValidated['billing_mode'] !== BillingMode::Automatico->value) {
            $billingValidated['billing_day'] = null;
        }

        BranchBillingSetting::forBranch($branch->id)->update($billingValidated);

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal actualizada exitosamente.');
    }

    /**
     * Elimina una sucursal.
     *
     * NOTA: si la sucursal tiene registros relacionados (clientes,
     * contratos, OLTs, etc.) la eliminación fallará por las llaves
     * foráneas de la base de datos.
     */
    public function destroy(Branch $branch): RedirectResponse
    {
        $branch->delete();

        return redirect()
            ->route('branches.index')
            ->with('success', 'Sucursal eliminada exitosamente.');
    }

    /**
     * Reglas de validación compartidas entre store y update.
     *
     * @param Request  $request  Datos del formulario
     * @param int|null $ignoreId ID de la sucursal a excluir de la regla
     *                           unique (solo en update, para permitir
     *                           conservar el mismo nombre)
     */
    private function validateBranch(Request $request, ?int $ignoreId = null): array
    {
        // El nombre es unico POR EMPRESA, no en todo el sistema: dos
        // empresas distintas pueden tener cada una su "Sucursal
        // Principal". La restriccion de la base ya es compuesta
        // (branches_company_name_unique); esta regla tiene que decir lo
        // mismo o rechazaria nombres perfectamente validos.
        $nombreUnico = Rule::unique('branches', 'name')
            ->where('company_id', $request->input('company_id'))
            ->ignore($ignoreId);

        // El prefijo se guarda en mayúsculas: da igual cómo lo escriba
        // quien edita la sucursal, los contratos quedan uniformes.
        if ($request->filled('contract_prefix')) {
            $request->merge([
                'contract_prefix' => mb_strtoupper(trim($request->input('contract_prefix'))),
            ]);
        }

        return $request->validate([
            // La sucursal SIEMPRE pertenece a una empresa: es de donde
            // sale su identidad fiscal. El NIT ya no se escribe aqui.
            //
            // Al EDITAR es opcional: por esta misma ruta pasa la
            // configuracion de facturacion de la sucursal, que no manda
            // el campo. Exigirlo alli rompia esa pantalla sin que
            // tuviera nada que ver.
            'company_id'             => ($ignoreId ? 'sometimes|' : 'required|') . 'exists:companies,id',
            'name'                   => ['required', 'string', 'max:40', $nombreUnico],
            // Letras del número de contrato (ENG → ENG000001). Se
            // guardan siempre en mayúsculas y sin símbolos para que el
            // consecutivo quede uniforme.
            'contract_prefix'        => 'nullable|string|max:10|regex:/^[A-Za-z0-9]+$/',
            'country'                => 'required|string|max:60',
            'department'             => 'required|string|max:60',
            'municipality'           => 'required|string|max:60',
            'address'                => 'required|string|max:255',
            'number_phone'           => 'required|string|max:20',
            'additional_number'      => 'nullable|string|max:20',
            'image'                  => 'nullable|image',
            'moving_price'           => 'nullable|numeric',
            'reconnection_price'     => 'nullable|numeric',
            'message_custom_invoice' => 'nullable|string',
            'observation'            => 'nullable|string',
        ]);
    }
}
