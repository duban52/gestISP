{{-- ============================================================
     Formulario de caja NAP / CTO (crear y editar)

     El mapa no es decoración: sin coordenadas la caja no aparece en
     el mapa general ni se puede encontrar por cercanía a una
     dirección, que es lo que responde "¿este prospecto tiene
     cobertura?".

     Se puede marcar el punto haciendo clic, buscar la dirección, o
     usar la ubicación del propio dispositivo — útil cuando el técnico
     está parado junto a la caja con el celular.
     ============================================================ --}}
@php
    $esEdicion = isset($nap);
    $redActual = old('optical_network_id', $nap->optical_network_id ?? $redSeleccionada ?? null);
@endphp

<div class="card card-outline card-primary shadow-sm">
    <div class="card-body">

        {{-- ---------- Dónde cuelga ---------- --}}
        <h6 class="text-uppercase text-muted">De dónde cuelga</h6>

        <div class="row">
            <div class="col-md-4 form-group">
                <label for="optical_network_id">Red <span class="text-danger">*</span></label>
                <select name="optical_network_id" id="optical_network_id" class="form-control" required>
                    <option value="">Seleccione…</option>
                    @foreach($redes as $red)
                        <option value="{{ $red->id }}" @selected((string) $redActual === (string) $red->id)>
                            {{ $red->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4 form-group">
                <label for="pon_port_id">Puerto PON <span class="text-danger">*</span></label>
                <select name="pon_port_id" id="pon_port_id" class="form-control" required>
                    <option value="">Elija primero la red…</option>
                </select>
                <small class="form-text text-muted">
                    El troncal de la OLT del que sale esta caja.
                </small>
            </div>

            <div class="col-md-4 form-group">
                <label for="network_zone_id">Zona</label>
                <select name="network_zone_id" id="network_zone_id" class="form-control">
                    <option value="">Sin zona</option>
                </select>
                <small class="form-text text-muted">Opcional.</small>
            </div>
        </div>

        {{-- ---------- Identificación y capacidad ---------- --}}
        <h6 class="text-uppercase text-muted mt-3">La caja</h6>

        <div class="row">
            @if($esEdicion)
                <div class="col-md-3 form-group">
                    <label>Código</label>
                    <input type="text" class="form-control" value="{{ $nap->code }}" readonly>
                    <small class="form-text text-muted">Se asignó al crearla.</small>
                </div>
            @endif

            <div class="col-md-{{ $esEdicion ? 4 : 5 }} form-group">
                <label for="name">Nombre o referencia</label>
                <input type="text" name="name" id="name" class="form-control"
                       value="{{ old('name', $nap->name ?? '') }}"
                       placeholder="Ej.: Poste esquina parque">
            </div>

            <div class="col-md-3 form-group">
                <label for="capacity">Puertos <span class="text-danger">*</span></label>
                <select name="capacity" id="capacity" class="form-control" required>
                    @foreach(\App\Models\NapBox::CAPACIDADES as $cap)
                        <option value="{{ $cap }}"
                            @selected((string) old('capacity', $nap->capacity ?? 8) === (string) $cap)>
                            {{ $cap }} puertos{{ in_array($cap, [8, 16]) ? ' (habitual)' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2 form-group">
                <label for="splitter_ratio">Splitter</label>
                <input type="text" name="splitter_ratio" id="splitter_ratio" class="form-control"
                       value="{{ old('splitter_ratio', $nap->splitter_ratio ?? '') }}" placeholder="1:8">
            </div>
        </div>

        @if($esEdicion)
            <div class="alert alert-light border py-2">
                <i class="fas fa-info-circle text-muted"></i>
                Al aumentar los puertos se crean las posiciones nuevas. Al reducirlos,
                <strong>no se pueden quitar puertos con cliente conectado</strong>: primero hay que
                trasladar esos servicios.
            </div>
        @endif

        {{-- ---------- Ubicación ---------- --}}
        <h6 class="text-uppercase text-muted mt-3">Dónde está</h6>

        <div class="row">
            <div class="col-md-8 form-group">
                <label for="address">Dirección <span class="text-danger">*</span></label>
                <input type="text" name="address" id="address" class="form-control"
                       value="{{ old('address', $nap->address ?? '') }}"
                       placeholder="Calle 19 # 23-46" required>
            </div>
            <div class="col-md-4 form-group">
                <label for="reference">Punto de referencia</label>
                <input type="text" name="reference" id="reference" class="form-control"
                       value="{{ old('reference', $nap->reference ?? '') }}"
                       placeholder="Frente a la tienda">
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 form-group">
                <label for="latitude">Latitud <span class="text-danger">*</span></label>
                {{-- De solo lectura: se rellena marcando en el mapa. Se
                     evita así el error clásico de teclear las
                     coordenadas al revés y mandar la caja a otro país. --}}
                <input type="text" name="latitude" id="latitude" class="form-control"
                       value="{{ old('latitude', $nap->latitude ?? '') }}" readonly required>
            </div>
            <div class="col-md-4 form-group">
                <label for="longitude">Longitud <span class="text-danger">*</span></label>
                <input type="text" name="longitude" id="longitude" class="form-control"
                       value="{{ old('longitude', $nap->longitude ?? '') }}" readonly required>
            </div>
            <div class="col-md-4 form-group d-flex align-items-end">
                <button type="button" class="btn btn-outline-primary btn-block" id="btnMiUbicacion"
                        title="Usar la ubicación de este dispositivo">
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
            <div id="mapaCaja" style="height: 380px; border-radius: .25rem; z-index: 0;"></div>
            <small class="form-text text-muted">
                Haga clic en el mapa para fijar la caja, o arrastre el marcador para ajustarlo.
            </small>
        </div>

        {{-- ---------- Estado ---------- --}}
        <div class="row">
            <div class="col-md-4 form-group">
                <label for="status">Estado</label>
                <select name="status" id="status" class="form-control" required>
                    @foreach(\App\Models\NapBox::estados() as $valor => $etiqueta)
                        <option value="{{ $valor }}" @selected(old('status', $nap->status ?? 'operativa') === $valor)>
                            {{ $etiqueta }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8 form-group">
                <label for="notes">Observaciones</label>
                <input type="text" name="notes" id="notes" class="form-control"
                       value="{{ old('notes', $nap->notes ?? '') }}"
                       placeholder="Altura del poste, llave necesaria, novedades…">
            </div>
        </div>
    </div>

    <div class="card-footer text-right">
        <a href="{{ $esEdicion ? route('naps.show', $nap) : route('naps.index') }}" class="btn btn-secondary">
            Cancelar
        </a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> {{ $esEdicion ? 'Guardar cambios' : 'Crear caja' }}
        </button>
    </div>
</div>

@section('css')
    {{-- Leaflet: mapas sobre OpenStreetMap. Sin llave de API ni
         facturación, que es lo que lo hace apto para un módulo
         interno que se usa a diario. --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
@endsection

@section('js')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        /* ============================================================
           Puerto PON y zona dependen de la red

           El catálogo completo viaja en el HTML: son pocas decenas de
           registros y así cambiar de red no cuesta una petición.
           ============================================================ */
        {{-- El array se arma en un bloque de PHP aparte y NO dentro de
             la directiva json: Blade corta el argumento de una
             directiva en el primer paréntesis que cree de cierre y no
             cuenta los corchetes, así que una expresión con arrays
             anidados en varias líneas compila partida y revienta con un
             ParseError que señala una línea inocente.

             (Y por lo mismo, aquí no se nombran directivas con arroba:
             Blade las compila antes de quitar los comentarios, así que
             una arroba dentro de un comentario sí se ejecuta.) --}}
        @php
            $catalogo = $redes->mapWithKeys(fn ($r) => [$r->id => [
                'puertos' => $r->ponPorts->map(fn ($p) => [
                    'id' => $p->id,
                    'etiqueta' => $p->etiqueta,
                    'olt' => $p->olt?->name,
                ])->values(),
                'zonas' => $r->zones->map(fn ($z) => ['id' => $z->id, 'nombre' => $z->name])->values(),
            ]]);
        @endphp

        const CATALOGO = {!! $catalogo->toJson() !!};

        const PUERTO_ACTUAL = @json(old('pon_port_id', $nap->pon_port_id ?? null));
        const ZONA_ACTUAL = @json(old('network_zone_id', $nap->network_zone_id ?? null));

        function llenarDependientes() {
            const redId = $('#optical_network_id').val();
            const datos = CATALOGO[redId];

            const $puertos = $('#pon_port_id').empty();
            const $zonas = $('#network_zone_id').empty().append(new Option('Sin zona', ''));

            if (!datos) {
                $puertos.append(new Option('Elija primero la red…', ''));
                return;
            }

            if (datos.puertos.length === 0) {
                $puertos.append(new Option('Esta red no tiene puertos PON registrados', ''));
            } else {
                $puertos.append(new Option('Seleccione…', ''));

                datos.puertos.forEach(function (p) {
                    const texto = p.etiqueta + (p.olt ? ' — ' + p.olt : '');
                    const opcion = new Option(texto, p.id, false, String(PUERTO_ACTUAL) === String(p.id));
                    $puertos.append(opcion);
                });
            }

            datos.zonas.forEach(function (z) {
                $zonas.append(new Option(z.nombre, z.id, false, String(ZONA_ACTUAL) === String(z.id)));
            });
        }

        $('#optical_network_id').on('change', llenarDependientes);
        llenarDependientes();

        /* ============================================================
           Mapa
           ============================================================ */
        // Centro por defecto: Colombia. Si la caja ya tiene punto, se
        // abre encima de ella.
        const latInicial = parseFloat($('#latitude').val()) || 6.2442;
        const lngInicial = parseFloat($('#longitude').val()) || -75.5812;
        const tienePunto = $('#latitude').val() !== '';

        const mapa = L.map('mapaCaja').setView([latInicial, lngInicial], tienePunto ? 17 : 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; colaboradores de OpenStreetMap',
        }).addTo(mapa);

        let marcador = null;

        function fijarPunto(lat, lng, acercar) {
            $('#latitude').val(lat.toFixed(7));
            $('#longitude').val(lng.toFixed(7));

            if (marcador) {
                marcador.setLatLng([lat, lng]);
            } else {
                marcador = L.marker([lat, lng], { draggable: true }).addTo(mapa);

                // Arrastrar afina la posición sin tener que volver a
                // acertar el clic.
                marcador.on('dragend', function (e) {
                    const p = e.target.getLatLng();
                    fijarPunto(p.lat, p.lng, false);
                });
            }

            if (acercar) {
                mapa.setView([lat, lng], Math.max(mapa.getZoom(), 17));
            }
        }

        if (tienePunto) {
            fijarPunto(latInicial, lngInicial, false);
        }

        mapa.on('click', (e) => fijarPunto(e.latlng.lat, e.latlng.lng, false));

        // Ubicación del propio dispositivo: el técnico parado junto a
        // la caja con el celular es el caso más común.
        $('#btnMiUbicacion').on('click', function () {
            if (!navigator.geolocation) {
                alert('Este navegador no permite obtener la ubicación.');
                return;
            }

            const $boton = $(this).prop('disabled', true)
                .html('<span class="spinner-border spinner-border-sm"></span> Ubicando…');

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    fijarPunto(pos.coords.latitude, pos.coords.longitude, true);
                    $boton.prop('disabled', false).html('<i class="fas fa-crosshairs"></i> Estoy aquí');
                },
                function () {
                    alert('No se pudo obtener la ubicación. Revise los permisos del navegador.');
                    $boton.prop('disabled', false).html('<i class="fas fa-crosshairs"></i> Estoy aquí');
                },
                { enableHighAccuracy: true, timeout: 10000 },
            );
        });

        // Búsqueda por dirección contra Nominatim, el buscador de
        // OpenStreetMap. Es gratuito pero pide no abusar, así que solo
        // se consulta al pulsar el botón, nunca al teclear.
        function buscarDireccion() {
            const texto = $('#buscarDireccion').val().trim();

            if (texto === '') {
                return;
            }

            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(texto))
                .then(r => r.json())
                .then(function (resultados) {
                    if (!resultados.length) {
                        alert('No se encontró esa dirección. Marque el punto a mano en el mapa.');
                        return;
                    }

                    fijarPunto(parseFloat(resultados[0].lat), parseFloat(resultados[0].lon), true);
                })
                .catch(() => alert('No se pudo consultar el buscador de direcciones.'));
        }

        $('#btnBuscarDireccion').on('click', buscarDireccion);

        $('#buscarDireccion').on('keydown', function (e) {
            if (e.key === 'Enter') {
                // Enter aquí no debe enviar el formulario entero
                e.preventDefault();
                buscarDireccion();
            }
        });

        // El mapa se dibuja mal si su contenedor cambia de tamaño
        // después de crearlo (colapsos, pestañas): se le recuerda.
        setTimeout(() => mapa.invalidateSize(), 200);
    </script>
@endsection
