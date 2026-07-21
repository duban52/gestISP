<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificaciones en pantalla del usuario autenticado.
 *
 * Alimenta el contador rojo de "Mis Órdenes" y el aviso emergente
 * del navegador. Trabaja sobre la tabla notifications (canal
 * database de Laravel): cada aviso no leído suma al contador y se
 * marca leído cuando el usuario abre su bandeja de órdenes.
 */
class NotificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Estado actual para el sondeo del navegador.
     *
     * Devuelve cuántas notificaciones no leídas hay y las últimas,
     * para que el JS actualice el contador y muestre el aviso del
     * navegador ante las nuevas.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $noLeidas = $user->unreadNotifications()
            ->latest()
            ->take(10)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'titulo' => $n->data['titulo'] ?? 'Notificación',
                'detalle' => $n->data['detalle'] ?? '',
                'url' => $n->data['url'] ?? null,
                'fecha' => $n->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $noLeidas,
        ]);
    }

    /**
     * Marca todas las notificaciones del usuario como leídas.
     *
     * La usa el botón de la campanita ("marcar todas como leídas").
     * También se marcan solas al abrir "Mis Órdenes".
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }
}
