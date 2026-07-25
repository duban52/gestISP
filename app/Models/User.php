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
        'avatar',
        'dark_mode',
        'email',
        'password',
        'is_active',
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
        'is_active' => 'boolean',
        'dark_mode' => 'boolean',
    ];


    //Relación con la tabla sucursales
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branch')->withPivot('role_id');
    }

    /* ================================================================
       Perfil del usuario

       Los tres métodos adminlte_* los llama la plantilla del menú de
       usuario (arriba a la derecha) cuando se activan las opciones
       usermenu_image / usermenu_desc / usermenu_profile_url en
       config/adminlte.php.
       ================================================================ */

    /** Nombre y apellido, para no repetir la concatenación. */
    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->last_name);
    }

    /**
     * Iniciales del usuario, para el avatar de respaldo.
     */
    public function getInitialsAttribute(): string
    {
        $iniciales = mb_substr($this->name ?? '', 0, 1) . mb_substr($this->last_name ?? '', 0, 1);

        return mb_strtoupper($iniciales ?: '?');
    }

    /**
     * Foto de perfil para el menú superior.
     *
     * Si el usuario no subió ninguna (o el archivo ya no está en
     * disco) se dibuja un avatar con sus iniciales, así el menú nunca
     * queda con una imagen rota.
     *
     * El avatar de respaldo se genera aquí mismo como SVG incrustado:
     * no depende de ningún servicio externo (un servidor sin salida a
     * internet lo mostraría igual) ni envía datos del usuario fuera.
     */
    public function adminlte_image(): string
    {
        if ($this->avatar && is_file(public_path('storage/' . $this->avatar))) {
            return asset('storage/' . $this->avatar);
        }

        return $this->avatarDeIniciales();
    }

    /**
     * Avatar SVG con las iniciales sobre un fondo de color.
     *
     * El color se deriva del identificador para que cada persona
     * conserve siempre el mismo y se distingan de un vistazo.
     */
    private function avatarDeIniciales(): string
    {
        $paleta = ['1F4E79', '2E7D32', '6A1B9A', 'AD1457', 'EF6C00', '00838F', '4527A0', 'B71C1C'];
        $fondo = $paleta[($this->id ?? 0) % count($paleta)];

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128">
            <rect width="128" height="128" fill="#{$fondo}"/>
            <text x="50%" y="50%" dy=".35em" text-anchor="middle"
                  font-family="Helvetica, Arial, sans-serif" font-size="54"
                  font-weight="bold" fill="#FFFFFF">{$this->initials}</text>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Texto bajo el nombre en el menú de usuario: el rol activo y la
     * sucursal en la que está trabajando ahora mismo.
     */
    public function adminlte_desc(): string
    {
        $rol = Role::find(session('current_role_id'));
        $sucursal = Branch::find(session('branch_id'));

        return collect([
            $rol?->name ? ucfirst($rol->name) : null,
            $sucursal?->name,
        ])->filter()->implode(' · ') ?: $this->email;
    }

    /** Destino del botón "Perfil" del menú de usuario. */
    public function adminlte_profile_url(): string
    {
        return route('profile.edit', [], false);
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

    /**
     * Número al que se le envía WhatsApp (para los avisos a técnicos).
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->number_phone;
    }

    // Trazabilidad: sesiones registradas del usuario
    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    // Trazabilidad: intentos de inicio de sesión fallidos
    public function failedLogins()
    {
        return $this->hasMany(FailedLogin::class);
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
