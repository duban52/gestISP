<?php

namespace App\Http\Controllers;

use App\Billing\Dian\DianReadiness;
use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\NumberingRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración de la facturación electrónica de una empresa.
 *
 * QUÉ SE ADMINISTRA AQUÍ
 * ----------------------
 * Las cuatro cosas sin las que no se puede emitir: la configuración
 * DIAN (identificador del software y su PIN), el certificado digital,
 * las resoluciones de numeración y sus rangos autorizados.
 *
 * Hasta ahora existían en la base y **solo se podían crear por
 * consola**, así que poner el sistema a facturar exigía un programador
 * delante.
 *
 * POR QUÉ CUELGA DE LA EMPRESA
 * ----------------------------
 * Porque todo esto es del CONTRIBUYENTE, no de una sede: la resolución
 * se la da la DIAN al NIT, el certificado identifica al NIT, y la
 * configuración es 1 a 1 con la empresa. Colgarlo del contexto de
 * sucursal haría posible configurar la empresa equivocada sin darse
 * cuenta — que con datos fiscales es exactamente lo que no puede pasar.
 *
 * DOS PERMISOS, NO CUATRO
 * -----------------------
 * `dian.index` para mirar y `dian.manage` para tocar. La división que
 * importa aquí no es crear/editar/borrar: quien lleva la facturación
 * necesita ver qué falta para poder emitir, y eso no le da por qué dar
 * acceso al certificado.
 *
 * LOS SECRETOS NO SE DEVUELVEN NUNCA
 * ----------------------------------
 * El PIN del software, la contraseña del certificado y la clave técnica
 * de la resolución están cifrados en la base y **no se pintan en el
 * formulario**. Se dejan en blanco: vacío significa «no lo cambies».
 * Un campo que devuelve el secreto lo pone en el HTML, en la caché del
 * navegador y en el historial de quien lo mire.
 */
class DianAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:dian.index')->only('panel', 'resolucion', 'actual');
        $this->middleware('check.permission:dian.manage')->except('panel', 'resolucion');
    }

    /**
     * Atajo desde el menú: la empresa en la que se está trabajando.
     *
     * El panel necesita saber DE QUÉ EMPRESA, porque todo esto es del
     * contribuyente. Desde el menú no hay forma de decirlo, así que se
     * usa la del contexto activo — que es precisamente la empresa en la
     * que uno está trabajando.
     *
     * Con varias empresas, cambiar de contexto cambia de panel. Es lo
     * correcto: configurar la DIAN de una empresa mientras se cree
     * estar en otra es exactamente lo que no puede pasar.
     */
    public function actual(\App\Tenancy\CurrentContext $contexto): RedirectResponse
    {
        $empresa = $contexto->activo() ? Company::find($contexto->companyId()) : null;

        if (!$empresa) {
            return redirect()
                ->route('companies.index')
                ->with('error', 'Elija la empresa cuya facturación electrónica quiere configurar.');
        }

        return redirect()->route('dian.panel', $empresa);
    }

    /**
     * El panel: qué falta, y todo lo que se puede configurar.
     */
    public function panel(Company $company, DianReadiness $revision): View
    {
        return view('gestisp.dian.panel', [
            'empresa' => $company,
            'revision' => $revision->revisar($company),
            'listo' => $revision->puedeEmitir($company),
            'configuracion' => $company->dianConfiguration,
            // La que se usaria HOY, para poder enseñarla de ejemplo en
            // el campo: asi se ve cual es sin tener que buscarla.
            'urlEnUso' => (new \App\Billing\Dian\Transport\DianEndpoints())->para(
                $company->dianConfiguration?->environment_code,
            ),
            'certificados' => DianCertificate::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderByDesc('active')
                ->orderByDesc('valid_until')
                ->get(),
            'resoluciones' => DianResolution::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->withCount('ranges')
                ->orderByDesc('active')
                ->orderByDesc('valid_until')
                ->get(),
        ]);
    }

    /**
     * Una resolución con sus rangos.
     *
     * Va en su propia pantalla y no en un desplegable del panel porque
     * los rangos son lo más delicado de todo: un prefijo o un número de
     * inicio mal puestos producen documentos con numeración que la DIAN
     * no autorizó.
     */
    public function resolucion(Company $company, DianResolution $resolution): View
    {
        $this->exigirQueSea($company, $resolution->company_id);

        return view('gestisp.dian.resolucion', [
            'empresa' => $company,
            'resolucion' => $resolution,
            'rangos' => $resolution->ranges()->withoutGlobalScopes()->with('branch')->orderBy('prefix')->get(),
            'sucursales' => $company->branches()->withoutGlobalScope('empresa')->orderBy('name')->get(),
        ]);
    }

    /**
     * Guarda la configuración DIAN.
     *
     * EL AMBIENTE NO SE CAMBIA DESDE AQUÍ
     * -----------------------------------
     * Pasar a producción es lo más delicado del sistema —a partir de
     * ahí se gastan consecutivos autorizados de verdad— y tiene su
     * propio guardián, que comprueba antes que esté todo. Aquí solo se
     * guardan los datos.
     */
    public function guardarConfiguracion(Request $request, Company $company): RedirectResponse
    {
        $datos = $request->validate([
            'software_id' => 'nullable|string|max:100',
            'software_pin' => 'nullable|string|max:100',
            'test_set_id' => 'nullable|string|max:60',
            // Opcional a proposito: lo normal es dejarla vacia y que el
            // sistema use la URL publica del ambiente. Esto es el
            // escape para cuando la DIAN la mueva o para una empresa
            // que pase por un proveedor tecnologico.
            'endpoint_override' => 'nullable|url|max:255',
        ], [
            'endpoint_override.url' => 'La dirección del servicio no parece una URL válida.',
        ], [
            'software_id' => 'identificador del software',
            'software_pin' => 'PIN del software',
            'test_set_id' => 'identificador del set de pruebas',
            'endpoint_override' => 'dirección del servicio',
        ]);

        $configuracion = DianConfiguration::withoutGlobalScopes()->firstOrNew([
            'company_id' => $company->id,
        ]);

        // `?? null` y no solo `?:`: validate() NO devuelve las claves
        // que no venian en el formulario, asi que leerlas a secas
        // revienta en cuanto alguien manda el formulario incompleto.
        $configuracion->software_id = ($datos['software_id'] ?? null) ?: null;
        $configuracion->test_set_id = ($datos['test_set_id'] ?? null) ?: null;
        $configuracion->endpoint_override = ($datos['endpoint_override'] ?? null) ?: null;

        // Vacío significa «no lo cambies», no «bórralo»: el campo llega
        // siempre en blanco porque el PIN no se devuelve nunca.
        if (filled($datos['software_pin'] ?? null)) {
            $configuracion->software_pin = $datos['software_pin'];
        }

        $configuracion->company_id = $company->id;
        $configuracion->save();

        return back()->with('success', 'Configuración DIAN guardada.');
    }

    /**
     * Registra una resolución de numeración.
     *
     * LA CLAVE TÉCNICA ES UN SECRETO
     * ------------------------------
     * No viaja en ningún XML: entra en el cálculo del CUFE y es lo que
     * impide que alguien lo falsifique conociendo solo los datos de la
     * factura. Va cifrada y no se devuelve.
     */
    public function guardarResolucion(Request $request, Company $company, ?DianResolution $resolution = null): RedirectResponse
    {
        // Ojo: cuando la ruta no trae {resolution}, Laravel NO pasa
        // null sino una instancia VACIA del modelo —y un objeto vacio es
        // truthy—. Hay que preguntar por ->exists, no por el objeto.
        $resolution = $resolution?->exists ? $resolution : null;

        if ($resolution) {
            $this->exigirQueSea($company, $resolution->company_id);
        }

        $datos = $request->validate([
            'resolution_number' => [
                'required', 'string', 'max:50',
                Rule::unique('dian_resolutions', 'resolution_number')
                    ->where('company_id', $company->id)
                    ->where('document_type_code', $request->input('document_type_code'))
                    ->ignore($resolution?->id),
            ],
            'document_type_code' => ['required', Rule::in([
                DianResolution::FACTURA,
                DianResolution::NOTA_CREDITO,
                DianResolution::NOTA_DEBITO,
            ])],
            'valid_from' => 'required|date',
            'valid_until' => 'required|date|after:valid_from',
            'technical_key' => [$resolution ? 'nullable' : 'required', 'string', 'max:255'],
            'active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:500',
        ], [
            'resolution_number.unique' => 'Ya hay una resolución con ese número para este tipo de documento.',
            'valid_until.after' => 'La vigencia tiene que terminar después de empezar.',
        ], [
            'resolution_number' => 'número de resolución',
            'valid_from' => 'inicio de vigencia',
            'valid_until' => 'fin de vigencia',
            'technical_key' => 'clave técnica',
        ]);

        $resolution ??= new DianResolution();

        $resolution->fill([
            'company_id' => $company->id,
            'resolution_number' => $datos['resolution_number'],
            'document_type_code' => $datos['document_type_code'],
            'valid_from' => $datos['valid_from'],
            'valid_until' => $datos['valid_until'],
            'active' => (bool) ($datos['active'] ?? false),
            'notes' => $datos['notes'] ?? null,
        ]);

        if (filled($datos['technical_key'] ?? null)) {
            $resolution->technical_key = $datos['technical_key'];
        }

        $resolution->save();

        return redirect()
            ->route('dian.resolucion', [$company, $resolution])
            ->with('success', 'Resolución guardada. Ahora regístrele su rango autorizado.');
    }

    /**
     * Guarda un rango autorizado.
     *
     * ES LO MÁS DELICADO DE TODA LA PANTALLA
     * --------------------------------------
     * De aquí sale el número de cada factura electrónica. Un prefijo o
     * un desde/hasta mal puestos producen documentos con numeración que
     * la DIAN no autorizó — y eso no se descubre al guardar, sino al
     * emitir.
     *
     * `current_number` NO se edita: es el consecutivo ya gastado.
     * Moverlo hacia atrás haría repetir números ya emitidos, y hacia
     * delante dejaría huecos que hay que justificar.
     */
    public function guardarRango(Request $request, Company $company, DianResolution $resolution, ?NumberingRange $range = null): RedirectResponse
    {
        $this->exigirQueSea($company, $resolution->company_id);

        // Igual que arriba: sin {range} en la ruta llega una instancia
        // vacia, no null.
        $range = $range?->exists ? $range : null;

        if ($range) {
            $this->exigirQueSea($company, $range->company_id);
        }

        $datos = $request->validate([
            'prefix' => 'required|string|max:10|regex:/^[A-Za-z0-9]+$/',
            'range_start' => 'required|integer|min:1',
            'range_end' => 'required|integer|gt:range_start',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('company_id', $company->id),
            ],
            'active' => 'nullable|boolean',
        ], [
            'prefix.regex' => 'El prefijo solo admite letras y números, sin espacios ni signos.',
            'range_end.gt' => 'El número final tiene que ser mayor que el inicial.',
            'branch_id.exists' => 'Esa sucursal no es de esta empresa.',
        ], [
            'range_start' => 'número inicial',
            'range_end' => 'número final',
            'branch_id' => 'sucursal',
        ]);

        $range ??= new NumberingRange();

        // El consecutivo ya gastado nunca puede quedar fuera del rango
        // nuevo: seria un rango que empieza despues de donde va.
        if ($range->exists && (int) $range->current_number > 0) {
            if ($datos['range_start'] > $range->current_number || $datos['range_end'] < $range->current_number) {
                return back()
                    ->withInput()
                    ->withErrors(['range_start' => sprintf(
                        'Este rango ya gastó hasta el %d: el nuevo rango tiene que contenerlo.',
                        $range->current_number,
                    )]);
            }
        }

        $range->fill([
            'dian_resolution_id' => $resolution->id,
            'branch_id' => ($datos['branch_id'] ?? null) ?: null,
            'prefix' => strtoupper($datos['prefix']),
            'range_start' => $datos['range_start'],
            'range_end' => $datos['range_end'],
            'active' => (bool) ($datos['active'] ?? false),
        ]);

        try {
            $range->save();
        } catch (\Illuminate\Database\QueryException $error) {
            // La base impide dos rangos ACTIVOS con el mismo prefijo en
            // la misma empresa: seria emitir dos veces el mismo numero
            // ante la DIAN aunque la base los viera distintos.
            if (str_contains($error->getMessage(), 'numbering_ranges_one_active_prefix')) {
                return back()
                    ->withInput()
                    ->withErrors(['prefix' => sprintf(
                        'Ya hay otro rango activo con el prefijo %s en esta empresa. Desactive el anterior primero.',
                        strtoupper($datos['prefix']),
                    )]);
            }

            throw $error;
        }

        return redirect()
            ->route('dian.resolucion', [$company, $resolution])
            ->with('success', 'Rango guardado.');
    }

    /**
     * Activa o desactiva un rango.
     *
     * No hay borrado: de un rango salieron números de facturas ya
     * emitidas, y borrarlo dejaría esos documentos sin poder decir con
     * qué resolución se emitieron.
     */
    public function alternarRango(Company $company, DianResolution $resolution, NumberingRange $range): RedirectResponse
    {
        $this->exigirQueSea($company, $range->company_id);

        try {
            $range->update(['active' => !$range->active]);
        } catch (\Illuminate\Database\QueryException $error) {
            if (str_contains($error->getMessage(), 'numbering_ranges_one_active_prefix')) {
                return back()->withErrors(['prefix' => sprintf(
                    'No se puede activar: ya hay otro rango activo con el prefijo %s.',
                    $range->prefix,
                )]);
            }

            throw $error;
        }

        return back()->with('success', $range->active ? 'Rango activado.' : 'Rango desactivado.');
    }

    /**
     * Que el registro sea de la empresa de la URL.
     *
     * Sin esto se podría editar la resolución de OTRA empresa metiendo
     * su id a mano — y con datos fiscales eso no es un bug cualquiera:
     * es emitir con la autorización de otro contribuyente.
     */
    private function exigirQueSea(Company $company, ?int $empresaDelRegistro): void
    {
        abort_unless($empresaDelRegistro === $company->id, 404);
    }
}
