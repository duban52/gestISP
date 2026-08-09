<?php

namespace App\Filters;

use App\Notifications\TechnicalOrderAssignedTechnician;
use App\Notifications\TechnicalOrderRejectedTechnician;
use Illuminate\Support\Facades\Auth;
use JeroenNoten\LaravelAdminLte\Menu\Filters\FilterInterface;

/**
 * Pone el número rojo de órdenes no vistas en el ítem "Mis Órdenes"
 * del menú.
 *
 * Cuenta las notificaciones de asignación no leídas del técnico
 * actual y las muestra como una etiqueta roja junto al ítem. Es el
 * valor con el que se pinta la página; el JS de sondeo lo mantiene
 * al día sin recargar.
 */
class UnreadOrdersBadgeFilter implements FilterInterface
{
    private ?int $conteo = null;

    public function transform($item)
    {
        // Solo el ítem "Mis Órdenes"
        if (($item['route'] ?? null) !== 'technicals_orders.my_technical_orders') {
            return $item;
        }

        $pendientes = $this->conteo();

        if ($pendientes > 0) {
            $item['label'] = $pendientes;
            $item['label_color'] = 'danger';
        }

        return $item;
    }

    /**
     * Órdenes por atender sin ver, cacheado por petición.
     *
     * Cuenta las asignaciones Y las devoluciones del supervisor: las
     * dos ponen trabajo en la bandeja del técnico, y una orden
     * devuelta que no sumara al contador pasaría inadvertida, que es
     * justo lo que se quiere evitar.
     */
    private function conteo(): int
    {
        if ($this->conteo !== null) {
            return $this->conteo;
        }

        $user = Auth::user();

        $this->conteo = $user
            ? (int) $user->unreadNotifications()
                ->whereIn('type', [
                    TechnicalOrderAssignedTechnician::class,
                    TechnicalOrderRejectedTechnician::class,
                ])
                ->count()
            : 0;

        return $this->conteo;
    }
}
