<?php

namespace App\Providers;

use App\Notifications\WhatsApp\LogGateway;
use App\Notifications\WhatsApp\MetaCloudGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Services\OltSshService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OltSshService::class, function ($app) {
            return new OltSshService();
        });

        // Proveedor de WhatsApp según la configuración: el conector
        // es intercambiable (log/simulado por defecto, meta en
        // producción) sin tocar el resto del código.
        $this->app->bind(WhatsAppGateway::class, function () {
            return match (config('notifications.whatsapp.driver', 'log')) {
                'meta' => new MetaCloudGateway(),
                default => new LogGateway(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
