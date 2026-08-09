{{-- ============================================================
     Hojas de estilo de los mapas

     Se incluye desde @section('css') de cualquier pantalla que use
     mapas. Está en un parcial y no copiado en cada vista para que
     subir de versión Leaflet sea UN cambio y no una cacería.

     Leaflet sobre OpenStreetMap: sin llave de API ni facturación, que
     es lo que lo hace apto para un panel interno que se usa a diario.
     ============================================================ --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

<style>
    /* z-index 0: sin esto el mapa se dibuja POR ENCIMA de los modales
       y de la barra lateral de AdminLTE. */
    .gestisp-map {
        z-index: 0;
        border-radius: .25rem;
    }

    /* Marcadores propios: un círculo de color con un icono dentro.
       Se distinguen de un vistazo sin tener que abrir nada, que es lo
       que hace falta cuando en el mismo mapa conviven la vivienda del
       cliente, el punto donde se cerró la orden y las cajas NAP. */
    .gestisp-map-pin {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 1px 4px rgba(0, 0, 0, .45);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .8rem;
    }

    .gestisp-map-pin-primary { background: #007bff; }
    .gestisp-map-pin-success { background: #28a745; }
    .gestisp-map-pin-danger  { background: #dc3545; }
    .gestisp-map-pin-warning { background: #ffc107; color: #212529; }
    .gestisp-map-pin-info    { background: #17a2b8; }
    .gestisp-map-pin-dark    { background: #343a40; }

    /* En modo oscuro las teselas de OpenStreetMap deslumbran: se
       apagan un poco para que la pantalla no cambie de brillo al
       desplazarse hasta el mapa. Ver el módulo de modo oscuro. */
    .dark-mode .gestisp-map .leaflet-tile-pane {
        filter: brightness(.78) contrast(1.05);
    }

    .dark-mode .gestisp-map .leaflet-popup-content-wrapper,
    .dark-mode .gestisp-map .leaflet-popup-tip {
        background: #454d55;
        color: #fff;
    }
</style>
