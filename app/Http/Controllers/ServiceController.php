<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Controlador de Servicios
 *
 * Gestiona el CRUD de los servicios que ofrece el ISP (internet,
 * televisión, telefonía, etc.). Cada servicio define un precio base
 * y su porcentaje de impuesto, y pertenece a una sucursal.
 * Los planes se construyen a partir de estos servicios.
 */
class ServiceController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:services.index')->only('index');
        $this->middleware('check.permission:services.create')->only('create', 'store');
        $this->middleware('check.permission:services.edit')->only('edit', 'update');
        $this->middleware('check.permission:services.destroy')->only('destroy');
    }

    /**
     * Lista los servicios de la sucursal activa.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente, que se encarga de la
     * paginación, búsqueda y ordenamiento en el navegador.
     */
    public function index(): View
    {
        $services = Service::where('branch_id', session('branch_id'))->get();

        return view('gestisp.services.index', compact('services'));
    }

    /**
     * Muestra el formulario de creación de servicio.
     */
    public function create(): View
    {
        return view('gestisp.services.create');
    }

    /**
     * Guarda un nuevo servicio.
     *
     * El servicio queda asociado a la sucursal activa en sesión
     * y al usuario autenticado que lo creó.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateService($request);

        Service::create([
            'name'           => $validated['name'],
            'base_price'     => $validated['base_price'],
            'tax_percentage' => $validated['tax_percentage'],
            'user_id'        => Auth::id(),
            'branch_id'      => session('branch_id'),
        ]);

        return redirect()
            ->route('services.index')
            ->with('success-create', 'El servicio se ha creado correctamente.');
    }

    /**
     * Muestra el formulario de edición de un servicio.
     */
    public function edit(Service $service): View
    {
        return view('gestisp.services.edit', compact('service'));
    }

    /**
     * Actualiza un servicio existente.
     */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $validated = $this->validateService($request);

        $service->update([
            'name'           => $validated['name'],
            'base_price'     => $validated['base_price'],
            'tax_percentage' => $validated['tax_percentage'],
        ]);

        return redirect()
            ->route('services.index')
            ->with('success-update', 'El servicio se ha actualizado correctamente.');
    }

    /**
     * Elimina un servicio.
     *
     * Se verifica primero que no tenga planes asociados: eliminar
     * un servicio con planes activos dejaría los planes huérfanos
     * o fallaría por la llave foránea de la base de datos.
     */
    public function destroy(Service $service): RedirectResponse
    {
        // Bloquear la eliminación si hay planes que dependen del servicio
        if (method_exists($service, 'plans') && $service->plans()->exists()) {
            return back()->with(
                'error',
                'No se puede eliminar: el servicio tiene planes asociados.'
            );
        }

        $service->delete();

        return redirect()
            ->route('services.index')
            ->with('success-delete', 'El servicio se ha eliminado correctamente.');
    }

    /**
     * Reglas de validación compartidas entre store y update.
     *
     * - base_price: valor numérico positivo
     * - tax_percentage: porcentaje entre 0 y 100
     */
    private function validateService(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:255',
            'base_price'     => 'required|numeric|min:0',
            'tax_percentage' => 'required|numeric|min:0|max:100',
        ]);
    }
}
