<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Las acciones masivas: deshacer un corte, anular una corrida
        // o borrar contratos importados se autoriza aqui, ademas del
        // middleware que ya reserva la pantalla al superadministrador.
        \App\Models\MassAction::class => \App\Policies\MassActionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
