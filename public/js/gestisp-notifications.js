/**
 * Notificaciones en pantalla de GestISP.
 *
 * Sondea el servidor cada cierto tiempo y:
 *   1. Mantiene al día el número rojo del ítem "Mis Órdenes" del menú,
 *      sin recargar la página.
 *   2. Muestra un aviso del navegador cuando llega una orden nueva.
 *
 * No usa WebSockets: solo consulta un endpoint (sondeo). Suficiente
 * para avisos de órdenes y sin infraestructura extra.
 */
(function () {
    'use strict';

    // Solo corre dentro del panel autenticado (hay barra lateral).
    if (!document.querySelector('.main-sidebar')) {
        return;
    }

    var INTERVALO_MS = 30000; // cada 30 segundos
    var URL_POLL = '/notifications/poll';
    var CLAVE_VISTAS = 'gestisp_avisos_vistos';

    /**
     * Ids de avisos que ya se mostraron como notificación del
     * navegador, para no repetirlos en cada sondeo.
     */
    function vistos() {
        try {
            return JSON.parse(localStorage.getItem(CLAVE_VISTAS)) || [];
        } catch (e) {
            return [];
        }
    }

    function marcarVisto(id, lista) {
        lista.push(id);
        // Se conservan los últimos 50 para no crecer sin límite
        localStorage.setItem(CLAVE_VISTAS, JSON.stringify(lista.slice(-50)));
    }

    /**
     * Actualiza (o crea/elimina) el número rojo junto a "Mis Órdenes".
     */
    function pintarContador(cantidad) {
        var enlace = document.querySelector('a.nav-link[href$="my_technical_orders"]');
        if (!enlace) {
            return;
        }

        var parrafo = enlace.querySelector('p') || enlace;
        var badge = parrafo.querySelector('.nav-orders-badge');

        if (cantidad > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'badge badge-danger right nav-orders-badge';
                parrafo.appendChild(badge);
            }
            badge.textContent = cantidad;
        } else if (badge) {
            badge.remove();
        }
    }

    /**
     * Muestra el aviso del navegador para los avisos nuevos.
     */
    function avisarNavegador(items) {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        var ya = vistos();

        items.forEach(function (item) {
            if (ya.indexOf(item.id) !== -1) {
                return;
            }

            var aviso = new Notification(item.titulo || 'GestISP', {
                body: item.detalle || '',
                tag: item.id,
            });

            if (item.url) {
                aviso.onclick = function () {
                    window.focus();
                    window.location.href = item.url;
                };
            }

            marcarVisto(item.id, ya);
        });
    }

    function sondear() {
        fetch(URL_POLL, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) {
                return r.ok ? r.json() : null;
            })
            .then(function (data) {
                if (!data) {
                    return;
                }
                pintarContador(data.unread || 0);
                avisarNavegador(data.items || []);
            })
            .catch(function () {
                // Un fallo de red puntual no debe hacer ruido; se
                // reintenta en el siguiente ciclo.
            });
    }

    // Pedir permiso para los avisos del navegador (una sola vez).
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    sondear();
    setInterval(sondear, INTERVALO_MS);
})();
