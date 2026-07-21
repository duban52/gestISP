<?php

namespace App\Listeners;

use App\Services\SessionTracker;
use Illuminate\Auth\Events\Failed;

/**
 * Anota en la trazabilidad cada intento de inicio de sesión fallido.
 *
 * Laravel dispara el evento Failed cuando las credenciales no
 * coinciden. Se toma el correo del intento (esté o no asociado a un
 * usuario real) y se registra junto con la IP.
 */
class RecordFailedLogin
{
    public function __construct(private readonly SessionTracker $tracker)
    {
    }

    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        $this->tracker->recordFailure($email, request());
    }
}
