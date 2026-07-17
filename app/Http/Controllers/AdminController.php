<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

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
        $branchId = session('branch_id');
        $branch = Branch::where('id', $branchId)->first();
        $rol = Role::where('id', session('current_role_id'))->first() ;
        //Retornar la vista del index del dashboard
        return view('gestisp.index', compact('branch', 'rol'));
}

}
