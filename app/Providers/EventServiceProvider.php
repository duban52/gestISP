<?php

namespace App\Providers;

use App\Billing\Events\ElectronicDocumentAccepted;
use App\Billing\Events\InvoiceIssued;
use App\Listeners\DeliverElectronicInvoice;
use App\Listeners\GenerateElectronicDocument;
use App\Listeners\NotifyClientInvoiceIssued;
use App\Listeners\PersistDarkModePreference;
use App\Listeners\RecordFailedLogin;
use JeroenNoten\LaravelAdminLte\Events\DarkModeWasToggled;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // Trazabilidad: cada intento de inicio de sesión fallido
        Failed::class => [
            RecordFailedLogin::class,
        ],
        // Notificación al cliente cuando se emite su factura
        InvoiceIssued::class => [
            // Primero el documento electronico: si la factura va a la
            // DIAN, su XML y su CUFE tienen que existir antes de que
            // nadie le mande nada al cliente.
            GenerateElectronicDocument::class,
            NotifyClientInvoiceIssued::class,
        ],
        // Entrega de la factura electronica al adquiriente, cuando la
        // DIAN la valida. Es otro momento distinto del de arriba:
        // InvoiceIssued dispara al NUMERAR, antes de transmitir, y
        // entonces todavia no hay acuse que entregar.
        ElectronicDocumentAccepted::class => [
            DeliverElectronicInvoice::class,
        ],
        // Tema claro/oscuro: se guarda en el usuario para que no se
        // pierda al cerrar la sesión
        DarkModeWasToggled::class => [
            PersistDarkModePreference::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
