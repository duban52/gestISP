<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Auth;
use JeroenNoten\LaravelAdminLte\Events\DarkModeWasToggled;

/**
 * Guarda en el usuario el tema que acaba de elegir.
 *
 * AdminLTE solo recuerda la preferencia mientras dure la sesión; al
 * cerrarla (o al expirar por inactividad) el panel volvía al tema
 * claro. Escuchando su evento, la decisión queda en la base de datos
 * y acompaña al usuario en cualquier equipo.
 */
class PersistDarkModePreference
{
    public function handle(DarkModeWasToggled $event): void
    {
        if (!Auth::check()) {
            return;
        }

        // El controlador del paquete ya dejó la preferencia nueva en
        // la sesión: se lee de ahí y se copia al usuario.
        Auth::user()->forceFill([
            'dark_mode' => $event->darkMode->isEnabled(),
        ])->save();
    }
}
