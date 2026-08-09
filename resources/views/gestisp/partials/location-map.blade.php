{{-- ============================================================
     Mapa de solo lectura

     Dibuja uno o varios puntos ya conocidos. Con dos puntos y
     $line = true traza además la línea entre ellos, que es como se
     compara de un vistazo la vivienda del cliente con el sitio donde
     el técnico cerró la orden.

     Parámetros
     ----------
     $mapId    Identificador único en la página.
     $markers  Lista de puntos:
               ['lat', 'lng', 'title', 'color', 'icon', 'accuracy', 'popup']
               · color: sufijo de Bootstrap (primary, danger, …)
               · icon: clase de Font Awesome (fa-home, fa-user-hard-hat…)
               · accuracy: radio en metros del margen de error del GPS
     $line     Une los dos primeros puntos con una línea.
     $height   Alto del mapa.

     La inicialización la hace partials/leaflet-script, que la pantalla
     debe incluir en @section('js').
     ============================================================ --}}
@php
    $mapId = $mapId ?? 'mapaUbicacionVista';
    $height = $height ?? '320px';

    $viewerConfig = [
        'mode' => 'view',
        'line' => (bool) ($line ?? false),
        'markers' => collect($markers ?? [])->map(fn ($marker) => [
            'lat' => (float) $marker['lat'],
            'lng' => (float) $marker['lng'],
            'title' => $marker['title'] ?? null,
            'color' => $marker['color'] ?? 'primary',
            'icon' => $marker['icon'] ?? 'fa-map-marker-alt',
            'accuracy' => $marker['accuracy'] ?? null,
            'popup' => $marker['popup'] ?? null,
        ])->values(),
    ];
@endphp

<div id="{{ $mapId }}" class="gestisp-map" style="height: {{ $height }};"></div>

<script type="application/json" data-gestisp-map="{{ $mapId }}">@json($viewerConfig, JSON_HEX_TAG | JSON_HEX_AMP)</script>
