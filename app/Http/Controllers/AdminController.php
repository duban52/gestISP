<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use App\Tenancy\CurrentContext;

class AdminController extends Controller
{
    /**
     * Constructor: protege el dashboard con autenticación y el
     * permiso gestisp.index (todos los roles del seeder lo tienen).
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('check.permission:gestisp.index')->only('index');
    }
    //
    public function index(){
        // En panel consolidado NO hay una sucursal activa: se trabajan
        // varias a la vez y session('branch_id') es null. La vista daba
        // "Attempt to read property name on null" nada mas entrar.
        $branchId = app(CurrentContext::class)->branchId();
        $branch = $branchId ? Branch::find($branchId) : null;
        $rol = Role::find(session('current_role_id'));
        $empresa = Company::find(session('company_id'));

        // Que se muestra como "donde estas": la sucursal si hay una, y
        // si no, cuantas sedes abarca el panel.
        $alcance = $branch?->name
            ?? (session('branch_ids')
                ? 'todas sus sucursales (' . count(session('branch_ids')) . ')'
                : null);

        //Retornar la vista del index del dashboard
        return view('gestisp.index', compact('branch', 'rol', 'empresa', 'alcance'));
}

}
