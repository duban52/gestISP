<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'identity_number',
        'name',
        'last_name',
        'number_phone',
        'address',
        'email',
        'password',
        'selected_branch_id'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];


    //Relación con la tabla sucursales
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branch')->withPivot('role_id');
    }


    //Relación con clientes

    public function clients()
    {
        return $this->hasMany(User::class);
    }

    //Relación con servicios
    public function services(){
        return $this->hasMany(Service::class);
    }

    //Relación con planes
    public function plans(){
        return $this->hasMany(Plan::class);
    }

    //Relación con contratos
    public function contracts(){
        return $this->hasMany(Contract::class);
    }

    //Relación con pagos
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    //Relación con cargos adicionales

    public function additionalCharges()
    {
        return $this->hasMany(Contract::class);
    }

    //Relacion con facturas
    public function invoices(){
        return $this->hasMany(Invoice::class);
    }

    //Relación con cajas
    public function cashRegisters(){
        return $this->hasMany(CashRegister::class);
    }

    //Relación con trasacciones de caja
    public function cashRegisterTransactions()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    //Relación con almacenes
    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    public function materialMovements()
    {
        return $this->hasMany(MaterialMovement::class);
    }
    public function technicalOrders(){
        return $this->hasMany(TechnicalOrder::class, 'created_by');
    }

    // Relación con órdenes técnicas asignadas
    public function assignedTechnicalOrders()
    {
        return $this->hasMany(TechnicalOrder::class, 'user_assigned');
    }

    // Relación con verificaciones de órdenes técnicas
    public function technicalOrderVerifications()
    {
        return $this->hasMany(TechnicalOrderVerification::class, 'verified_by');
    }

    //Método para asignar sucursal a un usuario:

    public function assignBranch($branchId, $roleId)
    {
        $branch = Branch::find($branchId);

        if ($branch) {
            $this->branches()->attach($branch, ['role_id' => $roleId]);
        } else {
            throw new \Exception("Branch with ID '{$branchId}' not found.");
        }
    }
    public function getCurrentRole()
    {
        $branchId = session('branch_id'); // Obtener la sucursal actual desde la sesión

        if ($branchId) {
            // Obtener el rol del usuario en la sucursal actual
            $branch = $this->branches()->where('id', $branchId)->first();
            if ($branch) {
                return $branch->pivot->role_id; // Devuelve el role_id asociado a la sucursal
            }
        }

        return null; // Si no hay sucursal seleccionada, devuelve null
    }
    public function can($permission, $arguments = [])
    {
        // Obtener el rol activo desde la sesión
        $currentRoleId = session('current_role_id');

        if ($currentRoleId) {
            // Obtener el rol
            $role = Role::find($currentRoleId);

            // Verificar si el rol tiene el permiso
            if ($role && $role->hasPermissionTo($permission)) {
                return true;
            }
        }

        // Si no tiene el permiso, usar la lógica por defecto de Laravel
        return parent::can($permission, $arguments);
    }



}
