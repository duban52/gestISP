{{-- ============================================================
     Mapa de la red

     Todas las cajas sobre OpenStreetMap, coloreadas por ocupación:
     de un vistazo se ve dónde queda capacidad y dónde ya no.

     Los marcadores llegan por AJAX y no incrustados en el HTML: con
     varios cientos de cajas eso haría pesada la primera carga, y el
     mapa debe aparecer de inmediato.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Mapa de la red')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-map-marked-alt mr-2"></i>Mapa de la red</h1>
        <a href="{{ route('naps.index') }}" class="btn btn-secondary">
            <i class="fas fa-list"></i> Ver como listado
        </a>
    </div>
@endsection

@section('content')

    @if($sinUbicar > 0)
        <div class="alert alert-warning py-2">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>{{ $sinUbicar }}</strong> caja(s) no tienen coordenadas y no aparecen aquí.
            <a href="{{ route('naps.index') }}">Búsquelas en el listado</a> para ubicarlas.
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-header">
            <div class="row align-items-end">
                <div class="col-md-4 form-group mb-0">
                    <label class="mb-1">Red</label>
                    <select id="filtroRed" class="form-control">
                        <option value="">Todas las redes</option>
                        @foreach($redes as $red)
                            <option value="{{ $red->id }}">{{ $red->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 form-group mb-0">
                    <label class="mb-1">Mostrar</label>
                    <select id="filtroCupo" class="form-control">
                        <option value="">Todas</option>
                        <option value="cupo">Solo con cupo</option>
                        <option value="llenas">Solo llenas</option>
                    </select>
                </div>
                <div class="col-md-4 text-md-right">
                    <span class="badge badge-success">&nbsp;</span> <small>Hasta 50%</small>
                    <span class="badge badge-info ml-1">&nbsp;</span> <small>50-80%</small>
                    <span class="badge badge-warning ml-1">&nbsp;</span> <small>80-90%</small>
                    <span class="badge badge-danger ml-1">&nbsp;</span> <small>Más de 90%</small>

                    {{-- Las muflas se pueden apagar: en una zona densa
                         se solapan con las cajas y a veces se quiere ver
                         solo una de las dos capas. --}}
                    <div class="custom-control custom-switch d-inline-block ml-3">
                        <input type="checkbox" class="custom-control-input" id="verMuflas" checked>
                        <label class="custom-control-label small" for="verMuflas">
                            <i class="fas fa-box-open"></i> Muflas
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-body p-0 position-relative">
            <div id="mapaRed" style="height: 70vh; z-index: 0;"></div>

            <div id="cargandoMapa"
                 class="position-absolute w-100 text-center"
                 style="top: 50%; z-index: 500; pointer-events: none;">
                <span class="badge badge-dark p-2">
                    <span class="spinner-border spinner-border-sm"></span> Cargando cajas…
                </span>
            </div>
        </div>

        <div class="card-footer py-2">
            <span id="contadorCajas" class="text-muted"></span>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <style>
        /* Marcador propio: un círculo con el porcentaje dentro. Dice
           más que un pin genérico, que obligaría a abrir cada caja. */
        .marcador-nap {
            width: 34px; height: 34px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,.4);
            color: #fff;
            font-size: .7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nap-success { background: #28a745; }
        .nap-info    { background: #17a2b8; }
        .nap-warning { background: #ffc107; color: #212529; }
        .nap-danger  { background: #dc3545; }

        /* La mufla se dibuja CUADRADA y en gris oscuro para que no se
           confunda con una caja a simple vista: son elementos distintos
           y en una zona densa aparecen mezclados. */
        .marcador-mufla {
            width: 26px; height: 26px;
            border: 2px solid #fff;
            border-radius: 4px;
            background: #343a40;
            color: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,.4);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
        }
    </style>
@endsection

@section('js')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>

    <script>
        $(function () {
            const mapa = L.map('mapaRed').setView([6.2442, -75.5812], 12);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; colaboradores de OpenStreetMap',
            }).addTo(mapa);

            // Agrupador: sin él, cien cajas en una manzana se pisan y
            // no se puede hacer clic en ninguna.
            const grupo = L.markerClusterGroup({ maxClusterRadius: 45 });
            mapa.addLayer(grupo);

            let cajas = [];

            function colorDe(porcentaje) {
                if (porcentaje >= 90) return 'danger';
                if (porcentaje >= 80) return 'warning';
                if (porcentaje >= 50) return 'info';
                return 'success';
            }

            function pintar() {
                const cupo = $('#filtroCupo').val();

                const visibles = cajas.filter(function (c) {
                    if (cupo === 'cupo') return c.disponibles > 0;
                    if (cupo === 'llenas') return c.disponibles === 0;
                    return true;
                });

                grupo.clearLayers();

                visibles.forEach(function (caja) {
                    const color = colorDe(caja.porcentaje);

                    const icono = L.divIcon({
                        className: '',
                        html: '<div class="marcador-nap nap-' + color + '">' + Math.round(caja.porcentaje) + '%</div>',
                        iconSize: [34, 34],
                        iconAnchor: [17, 17],
                    });

                    const marcador = L.marker([caja.lat, caja.lng], { icon: icono });

                    // Al pasar el puntero: lo que hace falta saber sin
                    // tener que abrir la caja.
                    marcador.bindTooltip(
                        '<strong>' + escapar(caja.codigo) + '</strong>' +
                        (caja.nombre ? '<br>' + escapar(caja.nombre) : '') +
                        '<br>Ocupación: <strong>' + caja.porcentaje + '%</strong>' +
                        ' (' + caja.ocupados + '/' + caja.capacidad + ')' +
                        '<br>Libres: <strong>' + caja.disponibles + '</strong>' +
                        (caja.zona ? '<br>Zona: ' + escapar(caja.zona) : ''),
                        { direction: 'top', offset: [0, -12] },
                    );

                    marcador.bindPopup(
                        '<div style="min-width:190px">' +
                        '<h6 class="mb-1">' + escapar(caja.codigo) + '</h6>' +
                        (caja.direccion ? '<div class="text-muted small">' + escapar(caja.direccion) + '</div>' : '') +
                        '<hr class="my-2">' +
                        '<div><strong>' + caja.disponibles + '</strong> puertos libres de ' + caja.capacidad + '</div>' +
                        (caja.puerto_pon ? '<div class="small text-muted">PON ' + escapar(caja.puerto_pon) +
                            (caja.olt ? ' · ' + escapar(caja.olt) : '') + '</div>' : '') +
                        '<a href="' + caja.url + '" class="btn btn-sm btn-primary btn-block mt-2">Ver la caja</a>' +
                        '</div>'
                    );

                    grupo.addLayer(marcador);
                });

                $('#contadorCajas').text(
                    visibles.length + ' caja(s) en el mapa · ' +
                    visibles.reduce((t, c) => t + c.disponibles, 0) + ' puertos libres'
                );

                // Encuadre automático: no obliga a buscar la red a mano
                if (visibles.length > 0) {
                    mapa.fitBounds(grupo.getBounds(), { padding: [40, 40], maxZoom: 16 });
                }
            }

            function cargar() {
                $('#cargandoMapa').show();

                const redId = $('#filtroRed').val();
                const url = "{{ route('naps.map_data') }}" + (redId ? '?network_id=' + redId : '');

                fetch(url)
                    .then(r => r.json())
                    .then(function (datos) {
                        cajas = datos;
                        pintar();
                    })
                    .catch(() => $('#contadorCajas').text('No se pudieron cargar las cajas.'))
                    .finally(() => $('#cargandoMapa').hide());
            }

            /* ============================================================
               Muflas

               Van en su propia capa y NO en el agrupador de cajas: si
               se mezclaran, un grupo diría "12" sin distinguir cuántas
               son cajas y cuántas muflas, que es justo lo que hay que
               diferenciar al planear una intervención.
               ============================================================ */
            const capaMuflas = L.layerGroup().addTo(mapa);

            function cargarMuflas() {
                const redId = $('#filtroRed').val();
                const url = "{{ route('closures.map_data') }}" + (redId ? '?network_id=' + redId : '');

                capaMuflas.clearLayers();

                fetch(url)
                    .then(r => r.ok ? r.json() : Promise.reject(r.status))
                    .then(function (muflas) {
                        muflas.forEach(function (m) {
                            const icono = L.divIcon({
                                className: '',
                                html: '<div class="marcador-mufla"><i class="fas fa-box-open"></i></div>',
                                iconSize: [26, 26],
                                iconAnchor: [13, 13],
                            });

                            const marcador = L.marker([m.lat, m.lng], { icon: icono });

                            marcador.bindTooltip(
                                '<strong>' + escapar(m.codigo) + '</strong>' +
                                (m.nombre ? '<br>' + escapar(m.nombre) : '') +
                                '<br>' + escapar(m.tipo) +
                                '<br>Fusiones: <strong>' + m.fusiones + '</strong> de ' + m.capacidad,
                                { direction: 'top', offset: [0, -10] }
                            );

                            marcador.bindPopup(
                                '<div style="min-width:190px">' +
                                '<h6 class="mb-1">Mufla ' + escapar(m.codigo) + '</h6>' +
                                (m.direccion ? '<div class="text-muted small">' + escapar(m.direccion) + '</div>' : '') +
                                '<hr class="my-2">' +
                                '<div>' + m.fusiones + ' de ' + m.capacidad + ' fusiones (' + m.porcentaje + '%)</div>' +
                                '<a href="' + m.url + '" class="btn btn-sm btn-dark btn-block mt-2">Ver la mufla</a>' +
                                '</div>'
                            );

                            capaMuflas.addLayer(marcador);
                        });
                    })
                    .catch(function () {
                        // Sin muflas el mapa sigue siendo útil: no se
                        // interrumpe nada, solo no se pinta esta capa.
                    });
            }

            $('#verMuflas').on('change', function () {
                if (this.checked) {
                    mapa.addLayer(capaMuflas);
                } else {
                    mapa.removeLayer(capaMuflas);
                }
            });

            $('#filtroRed').on('change', function () {
                cargar();
                cargarMuflas();
            });

            $('#filtroCupo').on('change', pintar);

            cargarMuflas();

            function escapar(v) {
                return $('<div>').text(v == null ? '' : v).html();
            }

            cargar();
        });
    </script>
@endsection
