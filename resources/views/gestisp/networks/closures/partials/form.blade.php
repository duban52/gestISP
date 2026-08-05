{{-- ============================================================
     Formulario de mufla

     Comparte estructura y mapa con el de las cajas NAP, y por la misma
     razón: dirección y punto en el mapa son obligatorios porque una
     mufla que no se encuentra en campo no sirve para nada.
     ============================================================ --}}
@csrf

<div class="card-body">
    <div class="row">
        <div class="col-md-4 form-group">
            <label for="optical_network_id">Red <span class="text-danger">*</span></label>
            <select name="optical_network_id" id="optical_network_id" class="form-control" required>
                <option value="">Elija la red…</option>
                @foreach($redes as $red)
                    <option value="{{ $red->id }}"
                        @selected((string) old('optical_network_id', $mufla->optical_network_id ?? $redPreseleccionada ?? '') === (string) $red->id)>
                        {{ $red->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4 form-group">
            <label for="network_zone_id">Zona</label>
            <select name="network_zone_id" id="network_zone_id" class="form-control">
                <option value="">Sin zona</option>
            </select>
            <small class="form-text text-muted">Se cargan al elegir la red.</small>
        </div>
        <div class="col-md-4 form-group">
            <label for="type">Tipo de montaje <span class="text-danger">*</span></label>
            <select name="type" id="type" class="form-control" required>
                @foreach(\App\Models\SpliceClosure::TIPOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected(old('type', $mufla->type ?? 'aerea') === $clave)>
                        {{ $texto }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 form-group">
            <label for="code">Código <span class="text-danger">*</span></label>
            <input type="text" name="code" id="code" class="form-control" maxlength="30"
                   value="{{ old('code', $mufla->code ?? '') }}" placeholder="MUF-001" required>
        </div>
        <div class="col-md-5 form-group">
            <label for="name">Nombre</label>
            <input type="text" name="name" id="name" class="form-control"
                   value="{{ old('name', $mufla->name ?? '') }}" placeholder="Cruce calle 30 con 40">
        </div>
        <div class="col-md-2 form-group">
            <label for="tray_count">Bandejas <span class="text-danger">*</span></label>
            <input type="number" name="tray_count" id="tray_count" class="form-control" min="1" max="48"
                   value="{{ old('tray_count', $mufla->tray_count ?? 4) }}" required>
        </div>
        <div class="col-md-2 form-group">
            <label for="splices_per_tray">Fusiones por bandeja <span class="text-danger">*</span></label>
            <input type="number" name="splices_per_tray" id="splices_per_tray" class="form-control" min="1" max="96"
                   value="{{ old('splices_per_tray', $mufla->splices_per_tray ?? 12) }}" required>
        </div>
    </div>

    <div class="alert alert-light border py-2 small">
        <i class="fas fa-info-circle text-muted"></i>
        Bandejas × fusiones por bandeja es la capacidad total de la mufla.
        Es el tope que el sistema no dejará pasar al registrar empalmes.
    </div>

    <h6 class="text-uppercase text-muted mt-3">Dónde está</h6>

    <div class="row">
        <div class="col-md-8 form-group">
            <label for="address">Dirección <span class="text-danger">*</span></label>
            <input type="text" name="address" id="address" class="form-control"
                   value="{{ old('address', $mufla->address ?? '') }}"
                   placeholder="Calle 30 # 40-50" required>
        </div>
        <div class="col-md-4 form-group">
            <label for="reference">Punto de referencia</label>
            <input type="text" name="reference" id="reference" class="form-control"
                   value="{{ old('reference', $mufla->reference ?? '') }}"
                   placeholder="Poste frente a la panadería">
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 form-group">
            <label for="latitude">Latitud <span class="text-danger">*</span></label>
            {{-- De solo lectura: se rellena marcando en el mapa, para
                 evitar el error clásico de teclear las coordenadas al
                 revés y mandar la mufla a otro país. --}}
            <input type="text" name="latitude" id="latitude" class="form-control"
                   value="{{ old('latitude', $mufla->latitude ?? '') }}" readonly required>
        </div>
        <div class="col-md-4 form-group">
            <label for="longitude">Longitud <span class="text-danger">*</span></label>
            <input type="text" name="longitude" id="longitude" class="form-control"
                   value="{{ old('longitude', $mufla->longitude ?? '') }}" readonly required>
        </div>
        <div class="col-md-4 form-group d-flex align-items-end">
            <button type="button" class="btn btn-outline-primary btn-block" id="btnMiUbicacion">
                <i class="fas fa-crosshairs"></i> Estoy aquí
            </button>
        </div>
    </div>

    <div class="form-group">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="mb-0">Marque el punto en el mapa</label>
            <div class="form-inline">
                <input type="text" id="buscarDireccion" class="form-control form-control-sm mr-1"
                       placeholder="Buscar una dirección…" style="width: 260px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnBuscarDireccion">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>
        <div id="mapaMufla" style="height: 380px; border-radius: .25rem; z-index: 0;"></div>
    </div>

    <div class="row">
        <div class="col-md-4 form-group">
            <label for="status">Estado <span class="text-danger">*</span></label>
            <select name="status" id="status" class="form-control" required>
                @foreach(\App\Models\SpliceClosure::estados() as $clave => $texto)
                    <option value="{{ $clave }}" @selected(old('status', $mufla->status ?? 'operativa') === $clave)>
                        {{ $texto }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-8 form-group">
            <label for="notes">Observaciones</label>
            <input type="text" name="notes" id="notes" class="form-control" maxlength="1000"
                   value="{{ old('notes', $mufla->notes ?? '') }}">
        </div>
    </div>
</div>

<div class="card-footer">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Guardar
    </button>
    <a href="{{ route('closures.index') }}" class="btn btn-secondary">Cancelar</a>
</div>

@section('css')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
@endsection

@section('js')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        {{-- El catálogo de zonas se arma en un bloque de PHP aparte y
             no dentro de la directiva json: Blade corta el argumento de
             una directiva en el primer paréntesis que cree de cierre y
             no cuenta los corchetes, así que una expresión con arrays
             anidados compila partida. --}}
        @php
            $zonasPorRed = $redes->mapWithKeys(fn ($r) => [
                $r->id => $r->zones->map(fn ($z) => ['id' => $z->id, 'nombre' => $z->name])->values(),
            ]);
        @endphp

        const ZONAS = {!! $zonasPorRed->toJson() !!};
        const ZONA_ACTUAL = {!! json_encode(old('network_zone_id', $mufla->network_zone_id ?? null)) !!};

        function llenarZonas() {
            const redId = $('#optical_network_id').val();
            const $zonas = $('#network_zone_id').empty().append(new Option('Sin zona', ''));

            (ZONAS[redId] || []).forEach(function (z) {
                const opcion = new Option(z.nombre, z.id);
                opcion.selected = (String(z.id) === String(ZONA_ACTUAL));
                $zonas.append(opcion);
            });
        }

        $(function () {
            $('#optical_network_id').on('change', llenarZonas);
            llenarZonas();

            const latInicial = parseFloat($('#latitude').val()) || 6.2442;
            const lngInicial = parseFloat($('#longitude').val()) || -75.5812;
            const tienePunto = $('#latitude').val() !== '';

            const mapa = L.map('mapaMufla').setView([latInicial, lngInicial], tienePunto ? 17 : 12);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; colaboradores de OpenStreetMap',
            }).addTo(mapa);

            let marcador = null;

            function fijarPunto(lat, lng, mover) {
                $('#latitude').val(lat.toFixed(7));
                $('#longitude').val(lng.toFixed(7));

                if (marcador) {
                    marcador.setLatLng([lat, lng]);
                } else {
                    marcador = L.marker([lat, lng], { draggable: true }).addTo(mapa);
                    marcador.on('dragend', function (e) {
                        const p = e.target.getLatLng();
                        fijarPunto(p.lat, p.lng, false);
                    });
                }

                if (mover) {
                    mapa.setView([lat, lng], 17);
                }
            }

            if (tienePunto) {
                fijarPunto(latInicial, lngInicial, false);
            }

            mapa.on('click', e => fijarPunto(e.latlng.lat, e.latlng.lng, false));

            $('#btnMiUbicacion').on('click', function () {
                if (!navigator.geolocation) {
                    alert('Este dispositivo no permite obtener la ubicación.');
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    pos => fijarPunto(pos.coords.latitude, pos.coords.longitude, true),
                    () => alert('No se pudo obtener la ubicación.'),
                    { enableHighAccuracy: true }
                );
            });

            $('#btnBuscarDireccion').on('click', function () {
                const texto = $('#buscarDireccion').val().trim();

                if (texto === '') {
                    return;
                }

                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(texto))
                    .then(r => r.json())
                    .then(function (resultados) {
                        if (resultados.length === 0) {
                            alert('No se encontró esa dirección. Marque el punto a mano.');
                            return;
                        }

                        fijarPunto(parseFloat(resultados[0].lat), parseFloat(resultados[0].lon), true);
                    })
                    .catch(() => alert('No se pudo buscar la dirección.'));
            });

            $('#buscarDireccion').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $('#btnBuscarDireccion').click();
                }
            });
        });
    </script>
@endsection
