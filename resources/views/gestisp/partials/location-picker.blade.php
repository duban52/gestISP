{{-- ============================================================
     Selector de ubicación sobre el mapa

     Bloque reutilizable: el formulario que lo incluye recibe dos
     campos ocultos (latitud y longitud) que se rellenan solos cuando
     el usuario marca el punto.

     Parámetros
     ----------
     $mapId          Identificador único en la página (obligatorio si
                     hay más de un mapa).
     $latitude       Valor actual de la latitud (o null).
     $longitude      Valor actual de la longitud (o null).
     $latitudeName   Nombre del campo de latitud  (por defecto latitude).
     $longitudeName  Nombre del campo de longitud (por defecto longitude).
     $sourceName     Nombre del campo que anota CÓMO se obtuvo el punto.
                     false para no enviarlo.
     $height         Alto del mapa.
     $references     Puntos de referencia a dibujar (cajas NAP), cada uno
                     ['lat' => , 'lng' => , 'title' => ].
     $allowClear     Muestra el botón de quitar la ubicación.
     $help           Texto de ayuda bajo el mapa.

     La inicialización la hace partials/leaflet-script, que la pantalla
     debe incluir en @section('js'); aquí solo se deja la configuración.
     ============================================================ --}}
@php
    $mapId = $mapId ?? 'mapaUbicacion';
    $latitudeName = $latitudeName ?? 'latitude';
    $longitudeName = $longitudeName ?? 'longitude';
    $sourceName = $sourceName ?? 'location_source';
    $height = $height ?? '360px';
    $references = $references ?? [];
    $allowClear = $allowClear ?? false;
    $help = $help ?? 'Haga clic sobre el mapa para fijar la vivienda, o arrastre el marcador para ajustarlo.';

    $hasPoint = $latitude !== null && $latitude !== '' && $longitude !== null && $longitude !== '';

    $pickerConfig = [
        'mode' => 'picker',
        'icon' => 'fa-home',
        'color' => 'primary',
        'point' => $hasPoint
            ? ['lat' => (float) $latitude, 'lng' => (float) $longitude]
            : null,
        'inputs' => [
            'latitude' => $mapId . 'Latitud',
            'longitude' => $mapId . 'Longitud',
            'source' => $sourceName ? $mapId . 'Origen' : null,
        ],
        'controls' => [
            'locate' => $mapId . 'BtnAqui',
            'searchInput' => $mapId . 'Buscar',
            'searchButton' => $mapId . 'BtnBuscar',
            'clear' => $allowClear ? $mapId . 'BtnQuitar' : null,
        ],
        'references' => $references,
    ];
@endphp

<input type="hidden" name="{{ $latitudeName }}" id="{{ $mapId }}Latitud" value="{{ $hasPoint ? $latitude : '' }}">
<input type="hidden" name="{{ $longitudeName }}" id="{{ $mapId }}Longitud" value="{{ $hasPoint ? $longitude : '' }}">
@if($sourceName)
    {{-- Arranca en "mapa" y el propio botón de GPS lo cambia a
         "dispositivo": así la ficha puede decir de dónde salió el
         punto sin preguntárselo a nadie. --}}
    <input type="hidden" name="{{ $sourceName }}" id="{{ $mapId }}Origen" value="mapa">
@endif

<div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
    <div class="btn-group btn-group-sm mb-1" role="group">
        <button type="button" class="btn btn-outline-primary" id="{{ $mapId }}BtnAqui">
            <i class="fas fa-crosshairs"></i> Estoy aquí
        </button>
        @if($allowClear)
            <button type="button" class="btn btn-outline-secondary" id="{{ $mapId }}BtnQuitar">
                <i class="fas fa-eraser"></i> Quitar
            </button>
        @endif
    </div>

    <div class="form-inline mb-1">
        <input type="text" id="{{ $mapId }}Buscar" class="form-control form-control-sm mr-1"
               placeholder="Buscar una dirección…" style="width: 240px;">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="{{ $mapId }}BtnBuscar">
            <i class="fas fa-search"></i>
        </button>
    </div>
</div>

<div id="{{ $mapId }}" class="gestisp-map" style="height: {{ $height }};"></div>

<small class="form-text text-muted">{{ $help }}</small>

<script type="application/json" data-gestisp-map="{{ $mapId }}">@json($pickerConfig, JSON_HEX_TAG | JSON_HEX_AMP)</script>
