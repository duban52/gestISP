<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Controlador de Usuarios
 *
 * Gestiona el CRUD de los usuarios internos del sistema (empleados
 * del ISP). Cada usuario puede pertenecer a varias sucursales y
 * tener un rol distinto en cada una; esa asignación vive en la
 * tabla pivote user_branch (branch_id + role_id).
 *
 * Los roles y permisos se manejan con Spatie Permission: al iniciar
 * sesión el usuario elige sucursal y el rol asociado queda activo
 * en sesión (current_role_id); el middleware check.permission
 * valida cada acción contra ese rol activo.
 */
class UserController extends Controller
{
    /**
     * Constructor: protege las rutas con autenticación y permisos.
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:users.index')->only('index');
        $this->middleware('check.permission:users.create')->only('create', 'store');
        $this->middleware('check.permission:users.edit')->only('edit', 'update');
        $this->middleware('check.permission:users.destroy')->only('destroy');
    }

    /**
     * Lista los usuarios del sistema.
     *
     * Se precargan las sucursales (la pivote incluye role_id) para
     * mostrarlas como badges sin caer en consultas N+1, y se pasa
     * el catálogo de roles indexado por id para resolver el nombre
     * del rol de cada asignación.
     *
     * Retorna la colección completa (sin paginar) porque la tabla
     * usa DataTables del lado del cliente.
     */
    public function index(): View
    {
        $users = User::with('branches')->get();
        $roleNames = Role::pluck('name', 'id');

        return view('gestisp.users.index', compact('users', 'roleNames'));
    }

    /**
     * Muestra el formulario de creación con sucursales y roles disponibles.
     */
    public function create(): View
    {
        $branches = Branch::all();
        $roles = Role::all();

        return view('gestisp.users.create', compact('branches', 'roles'));
    }

    /**
     * Guarda un nuevo usuario con sus asignaciones de sucursal/rol.
     *
     * Todo corre dentro de una transacción: si falla la asignación
     * de sucursales o roles, el usuario no queda creado a medias.
     * Los roles de Spatie se sincronizan a partir de los roles
     * elegidos por sucursal.
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $validatedData = $request->validate([
                'identity_number' => 'required|string|max:20|unique:users,identity_number',
                'name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'number_phone' => 'required|string|max:20',
                'address' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
                'branches' => 'required|array|min:1',
                'branches.*.branch_id' => 'required|exists:branches,id|distinct',
                'branches.*.role_id' => 'required|exists:roles,id',
            ], [
                'identity_number.unique' => 'El número de identidad ya está en uso.',
                'email.unique' => 'El correo electrónico ya está registrado.',
                'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
                'branches.required' => 'Debe asignar al menos una sucursal.',
                'branches.*.branch_id.required' => 'Debe seleccionar una sucursal en cada asignación.',
                'branches.*.branch_id.exists' => 'La sucursal seleccionada no es válida.',
                'branches.*.branch_id.distinct' => 'No puede asignar la misma sucursal dos veces.',
                'branches.*.role_id.required' => 'Debe seleccionar un rol en cada asignación.',
                'branches.*.role_id.exists' => 'El rol seleccionado no es válido.',
            ]);

            $user = DB::transaction(function () use ($validatedData) {
                $user = User::create([
                    'identity_number' => $validatedData['identity_number'],
                    'name' => $validatedData['name'],
                    'last_name' => $validatedData['last_name'],
                    'number_phone' => $validatedData['number_phone'],
                    'address' => $validatedData['address'],
                    'email' => $validatedData['email'],
                    'password' => Hash::make($validatedData['password']),
                ]);

                $this->syncBranchesAndRoles($user, $validatedData['branches']);

                return $user;
            });

            Log::info("Usuario creado con éxito: ID {$user->id}, Email: {$user->email}");

            return redirect()->route('users.index')->with('success-create', 'Usuario creado correctamente.');

        } catch (ValidationException $e) {
            Log::warning("Error de validación al crear usuario: " . json_encode($e->errors()));
            return redirect()->back()->withErrors($e->errors())->withInput();

        } catch (Exception $e) {
            Log::error("Error al crear usuario: " . $e->getMessage());
            return redirect()->back()->with('error', 'Ocurrió un error inesperado. Inténtalo nuevamente.')->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        //
    }

    /**
     * Muestra el formulario de edición con las asignaciones actuales.
     */
    public function edit(User $user): View
    {
        $branches = Branch::all();
        $roles = Role::all();
        // La relación ya trae role_id en la pivote (withPivot en el modelo)
        $userBranches = $user->branches;

        return view('gestisp.users.edit', compact('user', 'branches', 'roles', 'userBranches'));
    }

    /**
     * Actualiza los datos del usuario y sus asignaciones.
     *
     * La contraseña solo se cambia si se envía un valor; el campo
     * vacío conserva la actual. Las asignaciones de sucursal/rol
     * se reemplazan por completo y los roles de Spatie se vuelven
     * a sincronizar (antes solo se actualizaba la pivote y los
     * roles quedaban desactualizados).
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'number_phone' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'email' => 'required|email|unique:users,email,' . $user->id,
                'password' => 'nullable|string|min:6',
                'branches' => 'required|array|min:1',
                'branches.*.branch_id' => 'required|exists:branches,id|distinct',
                'branches.*.role_id' => 'required|exists:roles,id',
            ], [
                'email.unique' => 'El correo electrónico ya está registrado.',
                'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
                'branches.required' => 'El usuario debe conservar al menos una sucursal asignada.',
                'branches.*.branch_id.required' => 'Debe seleccionar una sucursal en cada asignación.',
                'branches.*.branch_id.exists' => 'La sucursal seleccionada no es válida.',
                'branches.*.branch_id.distinct' => 'No puede asignar la misma sucursal dos veces.',
                'branches.*.role_id.required' => 'Debe seleccionar un rol en cada asignación.',
                'branches.*.role_id.exists' => 'El rol seleccionado no es válido.',
            ]);

            DB::transaction(function () use ($request, $user, $validatedData) {
                $data = [
                    'name' => $validatedData['name'],
                    'last_name' => $validatedData['last_name'],
                    'number_phone' => $validatedData['number_phone'],
                    'address' => $validatedData['address'],
                    'email' => $validatedData['email'],
                ];

                if ($request->filled('password')) {
                    $data['password'] = Hash::make($validatedData['password']);
                }

                $user->update($data);

                // Reemplazar por completo las asignaciones actuales
                $user->branches()->detach();
                $this->syncBranchesAndRoles($user, $validatedData['branches']);
            });

            return redirect()->route('users.index')->with('success-update', 'Usuario actualizado correctamente.');

        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();

        } catch (Exception $e) {
            Log::error("Error al actualizar usuario {$user->id}: " . $e->getMessage());
            return redirect()->back()->with('error', 'Ocurrió un error inesperado. Inténtalo nuevamente.')->withInput();
        }
    }

    /**
     * Elimina un usuario del sistema.
     *
     * Restricciones:
     *  - Un usuario no puede eliminarse a sí mismo.
     *  - Si el usuario tiene registros asociados (pagos, órdenes,
     *    movimientos, etc.) la base de datos bloquea la eliminación
     *    por integridad referencial y se informa el motivo.
     */
    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')
                ->with('error', 'No puedes eliminar tu propio usuario.');
        }

        try {
            DB::transaction(function () use ($user) {
                $user->branches()->detach();
                $user->syncRoles([]);
                $user->delete();
            });

            return redirect()->route('users.index')->with('success-delete', 'Usuario eliminado correctamente.');

        } catch (QueryException $e) {
            Log::warning("Eliminación de usuario {$user->id} bloqueada por registros asociados: " . $e->getMessage());

            return redirect()->route('users.index')
                ->with('error', 'No se puede eliminar el usuario porque tiene registros asociados (pagos, órdenes, movimientos, etc.).');
        }
    }

    /**
     * Asigna las sucursales al usuario y sincroniza sus roles de
     * Spatie con los roles elegidos por sucursal, de modo que
     * model_has_roles siempre refleje lo que dice user_branch.
     *
     * @param array<int, array{branch_id: int|string, role_id: int|string}> $branches
     */
    private function syncBranchesAndRoles(User $user, array $branches): void
    {
        $roleIds = [];

        foreach ($branches as $branchData) {
            $user->branches()->attach($branchData['branch_id'], ['role_id' => $branchData['role_id']]);
            $roleIds[] = $branchData['role_id'];
        }

        $roles = Role::whereIn('id', array_unique($roleIds))->pluck('name')->all();
        $user->syncRoles($roles);
    }
}
