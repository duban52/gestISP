<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Controlador de Roles
 *
 * Gestiona el CRUD de roles y la asignación de permisos (Spatie
 * Permission). Cada permiso tiene formato modulo.accion (ej.
 * users.index) y una descripción legible; los formularios los
 * agrupan por módulo para facilitar la selección.
 *
 * El rol se asocia al usuario por sucursal en la tabla pivote
 * user_branch y se activa en sesión (current_role_id) al elegir
 * sucursal en el login; contra ese rol valida el middleware
 * check.permission.
 */
class RoleController extends Controller
{
    /**
     * Etiquetas legibles para agrupar los permisos por módulo en
     * los formularios. La clave es el prefijo del permiso (lo que
     * va antes del punto). Varios prefijos históricos apuntan al
     * mismo módulo (ej. warehouse/warehouses).
     */
    private const MODULE_LABELS = [
        'gestisp' => 'Dashboard',
        'branches' => 'Sucursales',
        'services' => 'Servicios',
        'plans' => 'Planes',
        'clients' => 'Clientes',
        'contracts' => 'Contratos',
        'invoices' => 'Facturas',
        'additionalCharges' => 'Cargos adicionales',
        'payments' => 'Pagos',
        'cashRegisters' => 'Cajas',
        'cash_register' => 'Cajas',
        'transactions' => 'Movimientos de caja',
        'warehouses' => 'Almacenes',
        'warehouse' => 'Almacenes',
        'materials' => 'Materiales',
        'categories' => 'Categorías de materiales',
        'movements' => 'Movimientos de material',
        'technicals_orders' => 'Órdenes técnicas',
        'technical_order' => 'Órdenes técnicas',
        'technical_orders' => 'Órdenes técnicas',
        'routers' => 'Routers',
        'olts' => 'OLTs',
        'onts' => 'ONTs',
        'pppoe' => 'Cuentas PPPoE',
        'users' => 'Usuarios',
        'roles' => 'Roles',
    ];

    /**
     * Constructor: protege las rutas con autenticación y permisos.
     * Antes este controlador no tenía protección y cualquier
     * usuario autenticado podía gestionar roles.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:roles.index')->only('index');
        $this->middleware('check.permission:roles.create')->only('create', 'store');
        $this->middleware('check.permission:roles.edit')->only('edit', 'update');
        $this->middleware('check.permission:roles.destroy')->only('destroy');
    }

    /**
     * Lista los roles del sistema.
     *
     * Se cuenta el número de permisos con withCount() y los
     * usuarios asignados a cada rol consultando la pivote
     * user_branch (que es donde realmente vive la asignación
     * rol-usuario por sucursal).
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente.
     */
    public function index(): View
    {
        $roles = Role::withCount('permissions')->get();

        // Usuarios distintos asignados a cada rol a través de las sucursales
        $usersPerRole = DB::table('user_branch')
            ->select('role_id', DB::raw('COUNT(DISTINCT user_id) AS total'))
            ->groupBy('role_id')
            ->pluck('total', 'role_id');

        return view('gestisp.roles.index', compact('roles', 'usersPerRole'));
    }

    /**
     * Muestra el formulario de creación con los permisos agrupados
     * por módulo.
     */
    public function create(): View
    {
        $permissionGroups = $this->permissionsByModule();

        return view('gestisp.roles.create', compact('permissionGroups'));
    }

    /**
     * Guarda un nuevo rol con sus permisos.
     *
     * Se usa syncPermissions() en lugar de permissions()->sync()
     * porque además de sincronizar la relación limpia la caché de
     * permisos de Spatie; con el sync() directo los cambios no se
     * reflejaban hasta que expiraba la caché.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name',
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ], [
            'name.required' => 'El nombre del rol es obligatorio.',
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'permissions.*.exists' => 'Uno de los permisos seleccionados no es válido.',
        ]);

        $role = Role::create(['name' => $validated['name']]);

        $role->syncPermissions($this->permissionsFromIds($validated['permissions'] ?? []));

        return redirect()->route('roles.index')
            ->with('success-create', 'Rol creado con éxito');
    }

    /**
     * Display the specified resource.
     */
    public function show(Role $role)
    {
        //
    }

    /**
     * Muestra el formulario de edición con los permisos agrupados
     * por módulo y los ids de los permisos que el rol ya tiene.
     */
    public function edit(Role $role): View
    {
        $permissionGroups = $this->permissionsByModule();
        $rolePermissionIds = $role->permissions->pluck('id')->all();

        return view('gestisp.roles.edit', compact('permissionGroups', 'role', 'rolePermissionIds'));
    }

    /**
     * Actualiza el nombre y los permisos del rol.
     */
    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ], [
            'name.required' => 'El nombre del rol es obligatorio.',
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'permissions.*.exists' => 'Uno de los permisos seleccionados no es válido.',
        ]);

        $role->update(['name' => $validated['name']]);

        $role->syncPermissions($this->permissionsFromIds($validated['permissions'] ?? []));

        return redirect()->route('roles.index')
            ->with('success-update', 'Rol modificado con éxito');
    }

    /**
     * Elimina un rol.
     *
     * Restricciones:
     *  - El rol superadministrador no puede eliminarse.
     *  - Un rol con usuarios asignados (vía user_branch) no puede
     *    eliminarse: primero hay que reasignar esos usuarios.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->name === 'superadministrador') {
            return redirect()->route('roles.index')
                ->with('error', 'El rol superadministrador no puede eliminarse.');
        }

        $usersWithRole = DB::table('user_branch')->where('role_id', $role->id)->count();

        if ($usersWithRole > 0) {
            return redirect()->route('roles.index')
                ->with('error', "No se puede eliminar el rol porque tiene {$usersWithRole} asignación(es) de usuario. Reasigne esos usuarios primero.");
        }

        $role->delete();

        return redirect()->route('roles.index')
            ->with('success-delete', 'Rol eliminado con éxito');
    }

    /**
     * Convierte los ids que envía el formulario (llegan como
     * strings) en modelos Permission. syncPermissions() interpreta
     * los strings como NOMBRES de permiso, así que pasarle los ids
     * directamente lanza PermissionDoesNotExist.
     *
     * @param array<int, int|string> $ids
     * @return Collection<int, Permission>
     */
    private function permissionsFromIds(array $ids): Collection
    {
        return Permission::whereIn('id', $ids)->get();
    }

    /**
     * Agrupa todos los permisos por módulo para pintarlos en los
     * formularios organizados por secciones. Los grupos conservan
     * el orden de creación de los permisos (orden del seeder).
     *
     * @return Collection<string, Collection<int, Permission>>
     */
    private function permissionsByModule(): Collection
    {
        return Permission::orderBy('id')->get()
            ->groupBy(function (Permission $permission) {
                $prefix = explode('.', $permission->name)[0];

                return self::MODULE_LABELS[$prefix] ?? ucfirst($prefix);
            });
    }
}
