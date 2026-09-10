<?php

namespace App\Http\Controllers;

use App\Billing\Delivery\InvoicePackage;
use App\Billing\Events\ElectronicDocumentAccepted;
use App\Models\Branch;
use App\Models\ElectronicDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Qué pasó con cada documento electrónico ante la DIAN.
 *
 * POR QUÉ EXISTE ESTA PANTALLA
 * ----------------------------
 * Porque hasta ahora la única forma de saber si una factura llegó a la
 * DIAN, si la aceptó, o por qué la rechazó, era entrar al servidor por
 * SSH y consultar la base a mano. Eso deja al negocio ciego: una factura
 * rechazada no avisa, y sin mirar no se entera nadie hasta que el
 * cliente reclama o llega una revisión.
 *
 * LO QUE SE VE, Y POR QUÉ IMPORTA CADA COSA
 * ------------------------------------------
 * · El ESTADO. «Firmado» quiere decir que está esperando a
 *   transmitirse; si lleva ahí horas, algo no está corriendo.
 * · Los MOTIVOS DEL RECHAZO, uno por línea. La DIAN los devuelve
 *   pegados con « | » y son ilegibles de un vistazo.
 * · Los INTENTOS. Un documento con muchos intentos y sin aceptar es un
 *   problema de comunicación, no de contenido — y se distinguen.
 * · Si se le ENTREGÓ AL CLIENTE. Que la DIAN la valide no es que el
 *   cliente la tenga: son dos obligaciones distintas.
 *
 * SIN FILTROS SE MUESTRA LO ÚLTIMO
 * --------------------------------
 * La tabla crece con cada factura de cada mes. Se pagina en el servidor
 * —como la trazabilidad— en vez de volcarla entera al navegador.
 */
class ElectronicDocumentLogController extends Controller
{
    /** Cuántos días se muestran cuando no se pide un rango. */
    private const DIAS_POR_DEFECTO = 30;

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:dian.documents');
        // Reenviar le manda un correo a un cliente: no basta con poder
        // mirar.
        $this->middleware('check.permission:dian.documents.resend')->only('reenviar');
    }

    public function index(Request $request): View
    {
        $desde = $request->input('desde', now()->subDays(self::DIAS_POR_DEFECTO)->toDateString());
        $hasta = $request->input('hasta');

        $documentos = ElectronicDocument::query()
            ->with(['invoice.contract.client', 'note'])
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->when($request->filled('estado'), fn ($q) => $q->where('status', $request->input('estado')))
            ->when($request->filled('ambiente'), fn ($q) => $q->where('environment_code', $request->input('ambiente')))
            ->when($request->filled('sin_entregar'), fn ($q) => $q->whereNull('delivered_at'))
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $texto = $request->input('buscar');

                return $q->where(function ($sub) use ($texto) {
                    $sub->where('cufe', 'like', "%{$texto}%")
                        ->orWhere('last_error', 'like', "%{$texto}%")
                        ->orWhereHas('invoice', fn ($f) => $f->where('full_number', 'like', "%{$texto}%"));
                });
            })
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString();

        return view('gestisp.dian.log.index', [
            'documentos' => $documentos,
            'resumen' => $this->resumen($desde, $hasta),
            'estados' => $this->estados(),
            'sucursales' => Branch::orderBy('name')->get(['id', 'name']),
            'diasPorDefecto' => self::DIAS_POR_DEFECTO,
            'usandoRangoPorDefecto' => !$request->filled('desde') && !$request->filled('hasta'),
        ]);
    }

    public function show(ElectronicDocument $document): View
    {
        $document->load(['invoice.contract.client', 'note', 'transmissions']);

        return view('gestisp.dian.log.show', [
            'documento' => $document,
            'motivos' => $this->motivos($document->last_error),
            'estados' => $this->estados(),
            'puedeReenviar' => $this->puedeReenviar(),
        ]);
    }

    /**
     * ¿El rol de la sesión puede reenviarle la factura al cliente?
     *
     * Se resuelve aquí y no en la vista, y con `checkPermissionTo`, que
     * es el metodo que NO lanza excepcion si el permiso todavia no
     * existe en la base. Un permiso declarado en el codigo pero sin
     * sembrar debe ocultar el boton, nunca tumbar la pantalla con un
     * 500 — es el mismo criterio que ya aplica `CheckPermission`.
     */
    private function puedeReenviar(): bool
    {
        $rol = Role::find(session('current_role_id'));

        return (bool) $rol?->checkPermissionTo('dian.documents.resend');
    }

    /**
     * Vuelve a entregarle la factura al cliente.
     *
     * POR QUÉ HACE FALTA UN BOTÓN
     * ---------------------------
     * Porque el correo falla por cosas que no son culpa del sistema: un
     * buzón lleno, una dirección mal escrita que luego se corrige, el
     * servidor de correo caído un rato. Sin esto, la única salida era
     * entrar al servidor y correr un comando.
     *
     * SE BORRA `delivered_at` A PROPÓSITO
     * -----------------------------------
     * Es la guarda que impide entregar dos veces. Aquí se quita porque
     * alguien está pidiendo explícitamente que se vuelva a mandar: la
     * guarda existe contra los reintentos automáticos, no contra una
     * decisión humana.
     */
    public function reenviar(ElectronicDocument $document): RedirectResponse
    {
        if ($document->status !== ElectronicDocument::ACEPTADO) {
            return back()->with('error', 'Solo se puede entregar una factura que la DIAN haya validado.');
        }

        $factura = $document->invoice;

        if (!$factura || !$factura->contract?->client) {
            return back()->with('error', 'Esta factura no tiene cliente al que entregársela.');
        }

        if (!app(InvoicePackage::class)->disponiblePara($factura)) {
            return back()->with(
                'error',
                'No hay paquete que entregar: falta el XML firmado o el acuse de la DIAN. '
                    . 'Recupérelo con «dian:recuperar-acuses».',
            );
        }

        $document->forceFill(['delivered_at' => null])->save();

        ElectronicDocumentAccepted::dispatch($document->refresh());

        return back()->with(
            'success',
            'Se encoló el envío a ' . $factura->contract->client->email . '. '
                . 'Si en unos minutos sigue sin entregarse, revise el registro de errores.',
        );
    }

    /**
     * Cuántos hay en cada estado dentro del rango.
     *
     * Es lo primero que hay que ver al entrar: si hay rechazados, eso es
     * lo urgente; si hay firmados sin transmitir, es que algo no está
     * corriendo.
     */
    private function resumen(?string $desde, ?string $hasta): array
    {
        $conteos = ElectronicDocument::query()
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sinEntregar = ElectronicDocument::query()
            ->where('status', ElectronicDocument::ACEPTADO)
            ->whereNull('delivered_at')
            ->when($desde, fn ($q) => $q->whereDate('created_at', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereDate('created_at', '<=', $hasta))
            ->count();

        return [
            'aceptados' => (int) $conteos->get(ElectronicDocument::ACEPTADO, 0),
            'rechazados' => (int) $conteos->get(ElectronicDocument::RECHAZADO, 0),
            'esperando' => (int) $conteos->get(ElectronicDocument::FIRMADO, 0)
                + (int) $conteos->get(ElectronicDocument::GENERADO, 0),
            'sin_entregar' => $sinEntregar,
        ];
    }

    /**
     * Los motivos del rechazo, uno por línea.
     *
     * La DIAN los devuelve pegados con « | » en un solo texto. Así como
     * vienen son ilegibles, y son justo lo que hay que corregir antes de
     * volver a emitir.
     */
    private function motivos(?string $error): array
    {
        if (!$error) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $error))));
    }

    /** @return array<string, string> estado => etiqueta legible */
    private function estados(): array
    {
        return [
            ElectronicDocument::BORRADOR => 'Borrador',
            ElectronicDocument::GENERADO => 'Generado, sin firmar',
            ElectronicDocument::FIRMADO => 'Firmado, esperando envío',
            ElectronicDocument::ENVIADO => 'Enviado',
            ElectronicDocument::ACEPTADO => 'Aceptado por la DIAN',
            ElectronicDocument::RECHAZADO => 'Rechazado por la DIAN',
        ];
    }
}
