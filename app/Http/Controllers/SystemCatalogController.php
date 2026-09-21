<?php

namespace App\Http\Controllers;

use App\Models\ContractStatusOption;
use App\Models\TechnicalOrderDetail;
use App\Models\TechnicalOrderType;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Los estados de contrato y los tipos de orden, configurables.
 *
 * RESERVADO AL SUPERADMINISTRADOR, y no por permiso marcable: lo que
 * se toca aquí decide a quién se le factura, qué contratos tienen
 * servicio y qué le pasa a los equipos de un cliente al cerrar una
 * orden. Un permiso concedido por descuido bastaría para dejar sin
 * facturar a media base de clientes.
 *
 * LO QUE ES `is_system` NO SE RENOMBRA NI SE BORRA
 * ------------------------------------------------
 * Son los estados y detalles que el código nombra por su valor:
 * «Activo», «Suspendido», «corte de servicio»… La facturación, la
 * suspensión por mora y el cierre de órdenes preguntan por ellos. Se
 * pueden describir, colorear, reordenar y hasta desactivar —para que
 * dejen de ofrecerse— pero cambiarles el nombre rompería el sistema en
 * sitios que no dan la cara hasta que alguien factura.
 */
class SystemCatalogController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
        $this->middleware(['auth', 'superadmin']);
    }

    public function index(): View
    {
        return view('gestisp.system.catalogo', [
            'estados' => ContractStatusOption::orderBy('sort_order')->orderBy('name')->get(),
            'tipos' => TechnicalOrderType::with(['details' => fn ($q) => $q->orderBy('sort_order')])
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    // ==================== Estados de contrato ====================

    public function storeStatus(Request $request): RedirectResponse
    {
        $datos = $this->validarEstado($request);

        $estado = ContractStatusOption::create($datos + ['is_system' => false]);

        $this->anotar('Creó el estado de contrato «' . $estado->name . '»', $estado, $datos);

        return back()->with('success', "Estado «{$estado->name}» creado.");
    }

    public function updateStatus(Request $request, ContractStatusOption $estado): RedirectResponse
    {
        $datos = $this->validarEstado($request, $estado);

        // El nombre de un estado del sistema es lo que el código
        // compara. Se ignora en vez de rechazar: el resto del
        // formulario —la descripción, el color, si factura— sí se
        // guarda, y es lo que la persona venía a cambiar.
        if ($estado->is_system) {
            unset($datos['name']);
        }

        // «CEDIDO» SOLO SE DESCRIBE. Sus reglas son las que hacen segura
        // la cesion: inactivo para que no se pueda asignar a mano —eso
        // dispararia la baja definitiva sobre equipos que usa otro— y
        // sin facturacion, porque su servicio lo paga ya el contrato
        // nuevo. Tocarlas aqui romperia la cesion sin avisar.
        if ($estado->name === \App\Billing\Enums\ContractStatus::Cedido->value) {
            $datos = array_intersect_key($datos, array_flip(['description', 'color', 'sort_order']));
        }

        $estado->update($datos);

        $this->anotar('Modificó el estado de contrato «' . $estado->name . '»', $estado, $datos);

        return back()->with('success', "Estado «{$estado->name}» actualizado.");
    }

    public function destroyStatus(ContractStatusOption $estado): RedirectResponse
    {
        if ($estado->is_system) {
            return back()->with('error', 'Los estados del sistema no se pueden borrar. Desactívelo si no quiere que se ofrezca.');
        }

        // CON CONTRATOS DENTRO NO SE BORRA. Quedarían con un estado que
        // nada sabe interpretar: ni si se les factura, ni si tienen
        // servicio.
        $enUso = DB::table('contracts')->where('status', $estado->name)->count();

        if ($enUso > 0) {
            return back()->with('error', sprintf(
                '%d contrato(s) están en «%s». Cámbielos de estado antes de borrarlo, o desactívelo.',
                $enUso,
                $estado->name,
            ));
        }

        $nombre = $estado->name;
        $estado->delete();

        $this->anotar('Borró el estado de contrato «' . $nombre . '»', null, ['estado' => $nombre]);

        return back()->with('success', "Estado «{$nombre}» borrado.");
    }

    // ==================== Tipos de orden ====================

    public function storeType(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'name' => 'required|string|max:40|unique:technical_order_types,name',
            'description' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);

        $tipo = TechnicalOrderType::create($datos + ['active' => true, 'is_system' => false]);

        $this->anotar('Creó el tipo de orden «' . $tipo->name . '»', $tipo, $datos);

        return back()->with('success', "Tipo de orden «{$tipo->name}» creado.");
    }

    public function updateType(Request $request, TechnicalOrderType $tipo): RedirectResponse
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('technical_order_types', 'name')->ignore($tipo->id)],
            'description' => 'nullable|string|max:255',
            'active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);

        $datos['active'] = $request->boolean('active');

        if ($tipo->is_system) {
            unset($datos['name']);
        }

        $tipo->update($datos);

        $this->anotar('Modificó el tipo de orden «' . $tipo->name . '»', $tipo, $datos);

        return back()->with('success', "Tipo «{$tipo->name}» actualizado.");
    }

    public function destroyType(TechnicalOrderType $tipo): RedirectResponse
    {
        if ($tipo->is_system) {
            return back()->with('error', 'Los tipos del sistema no se pueden borrar. Desactívelo si no quiere que se ofrezca.');
        }

        if ($tipo->details()->exists()) {
            return back()->with('error', 'Este tipo tiene detalles. Bórrelos primero.');
        }

        $nombre = $tipo->name;
        $tipo->delete();

        $this->anotar('Borró el tipo de orden «' . $nombre . '»', null, ['tipo' => $nombre]);

        return back()->with('success', "Tipo «{$nombre}» borrado.");
    }

    // ==================== Detalles de orden ====================

    public function storeDetail(Request $request): RedirectResponse
    {
        $datos = $this->validarDetalle($request);

        // La clave normalizada es con la que se agrupan los informes y
        // con la que el cierre de órdenes encuentra su fila. Se deriva
        // del nombre para que nadie tenga que entenderla.
        $datos['key'] = $this->clave($datos['name']);

        if (TechnicalOrderDetail::where('key', $datos['key'])->exists()) {
            return back()->with('error', 'Ya existe un detalle que se normaliza igual que «' . $datos['name'] . '».');
        }

        $detalle = TechnicalOrderDetail::create($datos + ['is_system' => false]);

        $this->anotar('Creó el detalle de orden «' . $detalle->name . '»', $detalle, $datos);

        return back()->with('success', "Detalle «{$detalle->name}» creado.");
    }

    public function updateDetail(Request $request, TechnicalOrderDetail $detalle): RedirectResponse
    {
        $datos = $this->validarDetalle($request);

        // El nombre de un detalle del sistema no se cambia: su clave
        // normalizada es la que llevan MILES de órdenes ya cerradas, y
        // cambiarla las dejaría fuera de los informes.
        if ($detalle->is_system) {
            unset($datos['name']);
        } else {
            $datos['key'] = $this->clave($datos['name']);
        }

        $detalle->update($datos);

        $this->anotar('Modificó el detalle de orden «' . $detalle->name . '»', $detalle, $datos);

        return back()->with('success', "Detalle «{$detalle->name}» actualizado.");
    }

    public function destroyDetail(TechnicalOrderDetail $detalle): RedirectResponse
    {
        if ($detalle->is_system) {
            return back()->with('error', 'Los detalles del sistema no se pueden borrar. Desactívelo si no quiere que se ofrezca.');
        }

        $enUso = DB::table('technical_orders')->where('detail', $detalle->name)->count();

        if ($enUso > 0) {
            return back()->with('error', sprintf(
                '%d orden(es) usan «%s». Desactívelo en vez de borrarlo, para que su histórico siga entendiéndose.',
                $enUso,
                $detalle->name,
            ));
        }

        $nombre = $detalle->name;
        $detalle->delete();

        $this->anotar('Borró el detalle de orden «' . $nombre . '»', null, ['detalle' => $nombre]);

        return back()->with('success', "Detalle «{$nombre}» borrado.");
    }

    // ==================== Apoyo ====================

    /** @return array<string, mixed> */
    private function validarEstado(Request $request, ?ContractStatusOption $estado = null): array
    {
        $datos = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('contract_statuses', 'name')->ignore($estado?->id),
            ],
            'description' => 'nullable|string|max:255',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ], [
            'name.unique' => 'Ya existe un estado con ese nombre.',
        ]);

        // Las casillas no llegan cuando están desmarcadas: sin esto,
        // desmarcar «se le factura» no lo desmarcaría nunca.
        return $datos + [
            'bills' => $request->boolean('bills'),
            'auto_bills' => $request->boolean('auto_bills'),
            'has_service' => $request->boolean('has_service'),
            'is_final' => $request->boolean('is_final'),
            'active' => $request->boolean('active'),
        ];
    }

    /** @return array<string, mixed> */
    private function validarDetalle(Request $request): array
    {
        $datos = $request->validate([
            'technical_order_type_id' => 'required|exists:technical_order_types,id',
            'name' => 'required|string|max:80',
            // Solo estados ACTIVOS. Uno desactivado lo esta por algo:
            // «Cedido» solo se alcanza por la cesion, y llevar un
            // contrato ahi cerrando una orden dispararia la baja
            // definitiva —liberar el puerto, borrar la ONT— sobre un
            // servicio que sigue funcionando para otro.
            'target_contract_status' => [
                'nullable',
                Rule::exists('contract_statuses', 'name')->where('active', true),
            ],
            'pppoe_action' => ['required', Rule::in([
                TechnicalOrderDetail::SIN_ACCION,
                TechnicalOrderDetail::DESHABILITAR,
                TechnicalOrderDetail::HABILITAR,
            ])],
            'ont_action' => ['required', Rule::in([
                TechnicalOrderDetail::SIN_ACCION,
                TechnicalOrderDetail::DESHABILITAR,
                TechnicalOrderDetail::HABILITAR,
            ])],
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ], [
            'target_contract_status.exists' => 'Ese estado no existe en el catálogo o está desactivado.',
        ]);

        return $datos + ['active' => $request->boolean('active')];
    }

    /**
     * La clave con la que se agrupa un detalle.
     *
     * Misma normalización que OrderDetailMap: sin tildes y en
     * minúsculas. Es lo que hace que «Instalación de servicio» y
     * «Instalacion de servicio» sean lo mismo.
     */
    private function clave(string $nombre): string
    {
        // Las MISMAS reglas con las que se busca: si la clave se
        // guardara con otras —quitar tildes pero no los paréntesis—, un
        // detalle nuevo con paréntesis no se encontraría nunca al
        // cerrar su orden.
        return \App\Reports\Support\OrderDetailMap::normalizar($nombre);
    }

    private function anotar(string $texto, $modelo, array $datos): void
    {
        $this->auditLogger->action('system.catalog', $texto, $datos, $modelo, 'sistema');
    }
}
