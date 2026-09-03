<?php

namespace App\Http\Controllers;

use App\Models\AffinityGroup;
use App\Models\Company;
use App\Models\FiscalCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administracion de empresas.
 *
 * QUE ES UNA EMPRESA AQUI
 * -----------------------
 * El contribuyente: un NIT. Es la raiz del sistema y de ella cuelgan
 * las sucursales. Lo que la distingue de una sucursal es que aqui vive
 * lo FISCAL —identidad tributaria, y mas adelante el certificado
 * digital, las resoluciones de numeracion y la configuracion DIAN—,
 * mientras que la sucursal guarda lo OPERATIVO.
 *
 * POR QUE NO SE PUEDE BORRAR UNA EMPRESA
 * --------------------------------------
 * Porque de ella cuelgan sucursales, y de esas, clientes, contratos y
 * facturas. Borrarla seria borrar la operacion entera de un
 * contribuyente por un clic. Se desactiva, que deja el historico en
 * pie y quita el acceso.
 */
class CompanyController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:companies.index')->only('index', 'show');
        $this->middleware('check.permission:companies.create')->only('create', 'store');
        $this->middleware('check.permission:companies.edit')->only('edit', 'update');
    }

    public function index(): View
    {
        return view('gestisp.companies.index', [
            // withCount SIN la barrera: la relacion branches() lleva el
            // global scope de empresa, asi que contaria solo las de la
            // empresa del contexto activo. En esta pantalla se estan
            // viendo OTRAS empresas, y el contador salia en cero
            // mientras la tabla de al lado si listaba sus sucursales.
            'empresas' => Company::withCount([
                'branches' => fn ($q) => $q->withoutGlobalScope('empresa'),
            ])->orderBy('legal_name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('gestisp.companies.create', ['empresa' => new Company()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);
        $datos['logo'] = $this->guardarLogo($request) ?? null;

        $empresa = Company::create($datos);

        $this->guardarResponsabilidades($empresa, $request);

        // Toda empresa nace con un grupo de afinidad predeterminado.
        //
        // Sin el, los contratos de esa empresa nacerian sin clasificar
        // y nadie se enteraria hasta el dia de facturar. Se crea aqui y
        // no se le pide al usuario porque en el momento de dar de alta
        // la empresa todavia no sabe que grupos va a necesitar: este es
        // el punto de partida razonable, y se edita o se añaden mas
        // desde su propio modulo.
        //
        // Es el mismo grupo «GEN — General» que la migracion creo para
        // las empresas que ya existian, asi que todas parten igual.
        AffinityGroup::create([
            'company_id' => $empresa->id,
            'code' => AffinityGroup::CODIGO_GENERAL,
            'name' => 'General',
            'description' => 'Grupo predeterminado. Sus contratos emiten documento interno.',
            'is_default' => true,
            'active' => true,
        ]);

        return redirect()
            ->route('companies.show', $empresa)
            ->with('success', 'Empresa creada con su grupo de afinidad «General». Ahora puede darle sucursales.');
    }

    public function show(Company $company): View
    {
        $sucursales = $company->branches()
            ->withoutGlobalScope('empresa')
            // Para poder avisar de las que no tiene nadie: una sucursal
            // sin usuarios es invisible y nadie puede entrar a ella.
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return view('gestisp.companies.show', [
            'empresa' => $company,
            'sucursales' => $sucursales,
            'total' => $sucursales->count(),
        ]);
    }

    public function edit(Company $company): View
    {
        return view('gestisp.companies.edit', ['empresa' => $company]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $datos = $this->validar($request, $company);

        // Pasar a consolidado con una sola sucursal no tiene sentido y
        // confunde: el panel "de todas las sedes" mostraria una.
        if (($datos['operation_mode'] ?? null) === Company::MODO_CONSOLIDADO
            && $company->branches()->withoutGlobalScope('empresa')->count() < 2) {
            return back()
                ->withInput()
                ->withErrors(['operation_mode' =>
                    'El panel consolidado necesita al menos dos sucursales. Con una sola no cambia nada.']);
        }

        if ($nuevo = $this->guardarLogo($request)) {
            // El anterior se borra solo cuando el nuevo ya esta en
            // disco: si el guardado fallara, la empresa se quedaria
            // sin logo en todas sus facturas.
            if ($company->logo) {
                Storage::disk('public')->delete($company->logo);
            }

            $datos['logo'] = $nuevo;
        }

        $company->update($datos);

        $this->guardarResponsabilidades($company, $request);

        return redirect()
            ->route('companies.show', $company)
            ->with('success', 'Empresa actualizada.');
    }

    /**
     * Guarda las responsabilidades fiscales marcadas.
     *
     * Misma forma que ClientController::guardarResponsabilidades(): se
     * borran y se vuelven a crear en vez de comparar una a una, y solo
     * se tocan si el formulario las mandó -el checkbox ausente no debe
     * borrar lo que ya había-.
     */
    private function guardarResponsabilidades(Company $empresa, Request $request): void
    {
        if (!$request->has('tax_responsibilities')) {
            return;
        }

        $codigos = array_filter((array) $request->input('tax_responsibilities', []));

        DB::transaction(function () use ($empresa, $codigos) {
            $empresa->taxResponsibilities()->delete();

            foreach (array_unique($codigos) as $codigo) {
                $empresa->taxResponsibilities()->create(['responsibility_code' => $codigo]);
            }
        });
    }

    /**
     * Guarda el logo si vino uno, y devuelve su ruta.
     *
     * El logo es de la EMPRESA y no de la sucursal: es identidad del
     * contribuyente, las cinco sedes imprimen el mismo. Ademas, en
     * panel consolidado no hay UNA sucursal de la que sacarlo.
     */
    private function guardarLogo(Request $request): ?string
    {
        if (!$request->hasFile('logo')) {
            return null;
        }

        return $request->file('logo')->store('companies', 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Company $company = null): array
    {
        return $request->validate([
            'legal_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            // Sale del catalogo y no de una lista escrita a mano -es el
            // mismo fallo que ya se corrigio en el cliente-, asi que se
            // valida contra el, igual que alla.
            'document_type_code' => [
                'required', 'string', 'max:5',
                Rule::exists('fiscal_catalogs', 'code')
                    ->where('catalog', FiscalCatalog::TIPO_DOCUMENTO)
                    ->where('active', true),
            ],
            // Dos empresas no pueden compartir identificacion fiscal:
            // serian el mismo contribuyente por duplicado.
            'document_number' => [
                'required', 'string', 'max:20',
                Rule::unique('companies', 'document_number')
                    ->where('document_type_code', $request->input('document_type_code'))
                    ->ignore($company?->id),
            ],
            'verification_digit' => 'nullable|string|max:1',
            'address' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',

            // ---- Datos fiscales adicionales (deuda de la fase 8):
            // existian en la tabla pero el formulario nunca los pidio,
            // asi que el informe de completitud los exigia sin que
            // hubiera manera de completarlos. Opcionales y sin validar
            // contra el catalogo, la misma decision que ya se tomo para
            // los campos equivalentes del cliente. ----
            'organization_type_code' => 'nullable|string|max:5',
            'department_dane_code' => 'nullable|string|max:5',
            'municipality_dane_code' => 'nullable|string|max:5',
            'postal_code' => 'nullable|string|max:10',

            'operation_mode' => ['required', Rule::in([
                Company::MODO_INDEPENDIENTE,
                Company::MODO_CONSOLIDADO,
            ])],
            'active' => 'nullable|boolean',
            'logo' => 'nullable|image|max:2048',
        ], [
            'document_number.unique' => 'Ya existe una empresa con esa identificación.',
        ]);
    }
}
