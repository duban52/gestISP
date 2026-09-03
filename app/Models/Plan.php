<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'branch_id',
    ];

    //Relación con usuarios
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    //Relación con servicios

    public function services()
    {
        return $this->belongsToMany(Service::class);
    }

    //Relación con contratos
    public function contracts(){
        return $this->hasMany(Contract::class);
    }


    //Relación con sucursales
    public function branch(){
        // Es belongsTo y no hasMany: el plan tiene branch_id, no al
        // reves. El hasMany buscaba branches.plan_id -que no existe- y
        // reventaba en cuanto algo hacia eager load de esta relacion
        // (PlanController::index la usa con ->with(['services', 'branch'])).
        return $this->belongsTo(Branch::class);
    }


    //Método para asignar servicios a un plan:

    public function assignService(string $serviceName)
    {
        $service = Service::where('name', $serviceName)->first();

        if ($service) {
            $this->services()->attach($service);
        } else {
            throw new \Exception("Service with name '{$serviceName}' not found.");
        }
    }
}
