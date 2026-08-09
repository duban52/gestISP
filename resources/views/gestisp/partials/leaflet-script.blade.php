{{-- ============================================================
     Motor de los mapas

     Se incluye UNA vez desde @section('js') de la pantalla. Recorre
     todos los bloques de configuración que hayan dejado los parciales
     de mapa (partials/location-picker y partials/location-map) y los
     arranca.

     POR QUÉ ASÍ Y NO CON UN <script> POR MAPA
     -----------------------------------------
     En AdminLTE el contenido de la página se pinta ANTES de que se
     carguen las librerías de @section('js'): un mapa que se
     inicializara junto a su propio <div> reventaría con "L is not
     defined". Cada parcial deja entonces solo su configuración en un
     bloque JSON inerte, y este archivo —que ya tiene Leaflet cargado—
     los recoge todos. De paso, varios mapas en la misma pantalla
     (ficha del contrato, modales de verificación) no cuestan una copia
     del código por cada uno.
     ============================================================ --}}
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    (function () {
        'use strict';

        // Centro por defecto cuando no hay ningún punto que mostrar.
        const DEFAULT_CENTER = [6.2442, -75.5812];
        const DEFAULT_ZOOM = 12;

        function tileLayer() {
            return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; colaboradores de OpenStreetMap',
            });
        }

        function pin(icon, color) {
            return L.divIcon({
                className: '',
                html: '<div class="gestisp-map-pin gestisp-map-pin-' + (color || 'primary') + '">' +
                    '<i class="fas ' + (icon || 'fa-map-marker-alt') + '"></i></div>',
                iconSize: [32, 32],
                iconAnchor: [16, 16],
            });
        }

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : value;
            return div.innerHTML;
        }

        /* ============================================================
           Mapa de solo lectura

           Dibuja uno o varios puntos y, si se pide, la línea que los
           une. La línea es lo que convierte dos marcadores sueltos en
           una comparación legible: "aquí está la casa, aquí se cerró
           la orden, y esto es lo que hay entre las dos".
           ============================================================ */
        function buildViewer(container, config) {
            const map = L.map(container, { scrollWheelZoom: false });
            tileLayer().addTo(map);

            const markers = [];

            (config.markers || []).forEach(function (item) {
                const marker = L.marker([item.lat, item.lng], { icon: pin(item.icon, item.color) }).addTo(map);

                if (item.title) {
                    marker.bindTooltip(escapeHtml(item.title), { direction: 'top', offset: [0, -14] });
                }

                if (item.popup) {
                    marker.bindPopup(item.popup);
                }

                // Círculo del margen de error del GPS: sin él, un punto
                // dibujado con precisión de metro sugiere una exactitud
                // que el dispositivo nunca tuvo.
                if (item.accuracy) {
                    L.circle([item.lat, item.lng], {
                        radius: item.accuracy,
                        color: '#6c757d',
                        weight: 1,
                        fillOpacity: .08,
                    }).addTo(map);
                }

                markers.push(marker);
            });

            if (config.line && markers.length >= 2) {
                L.polyline(
                    [markers[0].getLatLng(), markers[1].getLatLng()],
                    { color: '#dc3545', weight: 2, dashArray: '6 6' },
                ).addTo(map);
            }

            if (markers.length === 0) {
                map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
            } else if (markers.length === 1) {
                map.setView(markers[0].getLatLng(), config.zoom || 17);
            } else {
                map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [40, 40], maxZoom: 18 });
            }

            return map;
        }

        /* ============================================================
           Mapa para elegir un punto

           Tres formas de fijarlo, porque las tres se usan de verdad:
           el clic (oficina, sobre el mapa), el GPS del aparato (el
           técnico parado en la puerta) y la búsqueda por dirección
           (cuando solo se tiene la dirección escrita).
           ============================================================ */
        function buildPicker(container, config) {
            const latitudeInput = document.getElementById(config.inputs.latitude);
            const longitudeInput = document.getElementById(config.inputs.longitude);

            const hasPoint = Boolean(config.point);
            const center = hasPoint ? [config.point.lat, config.point.lng] : DEFAULT_CENTER;

            const map = L.map(container).setView(center, hasPoint ? 17 : DEFAULT_ZOOM);
            tileLayer().addTo(map);

            let marker = null;

            function setPoint(lat, lng, zoomIn) {
                latitudeInput.value = lat.toFixed(7);
                longitudeInput.value = lng.toFixed(7);

                if (marker) {
                    marker.setLatLng([lat, lng]);
                } else {
                    marker = L.marker([lat, lng], { draggable: true, icon: pin(config.icon, config.color) }).addTo(map);

                    // Arrastrar afina la posición sin tener que volver
                    // a acertar el clic.
                    marker.on('dragend', function (event) {
                        const position = event.target.getLatLng();
                        setPoint(position.lat, position.lng, false);
                    });
                }

                if (zoomIn) {
                    map.setView([lat, lng], Math.max(map.getZoom(), 17));
                }
            }

            if (hasPoint) {
                setPoint(config.point.lat, config.point.lng, false);
            }

            map.on('click', function (event) {
                setPoint(event.latlng.lat, event.latlng.lng, false);
            });

            // Cajas NAP ya documentadas alrededor: se pintan como
            // referencia para no marcar la casa en la otra manzana.
            (config.references || []).forEach(function (item) {
                L.marker([item.lat, item.lng], { icon: pin(item.icon || 'fa-box', item.color || 'dark') })
                    .addTo(map)
                    .bindTooltip(escapeHtml(item.title), { direction: 'top', offset: [0, -14] });
            });

            // ---- Ubicación del propio dispositivo ----
            const locateButton = document.getElementById(config.controls.locate);

            if (locateButton) {
                locateButton.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        alert('Este navegador no permite obtener la ubicación.');
                        return;
                    }

                    const original = locateButton.innerHTML;
                    locateButton.disabled = true;
                    locateButton.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Ubicando…';

                    navigator.geolocation.getCurrentPosition(
                        function (position) {
                            setPoint(position.coords.latitude, position.coords.longitude, true);
                            const sourceInput = document.getElementById(config.inputs.source);

                            // Queda anotado que el punto lo dio el GPS y
                            // no el dedo de alguien sobre el mapa: no
                            // merecen la misma confianza.
                            if (sourceInput) {
                                sourceInput.value = 'dispositivo';
                            }

                            locateButton.disabled = false;
                            locateButton.innerHTML = original;
                        },
                        function () {
                            alert('No se pudo obtener la ubicación. Revise los permisos del navegador.');
                            locateButton.disabled = false;
                            locateButton.innerHTML = original;
                        },
                        { enableHighAccuracy: true, timeout: 10000 },
                    );
                });
            }

            // ---- Búsqueda por dirección ----
            // Nominatim es el buscador de OpenStreetMap: gratuito, pero
            // pide no abusar. Por eso solo se consulta al pulsar el
            // botón, nunca mientras se teclea.
            const searchInput = document.getElementById(config.controls.searchInput);
            const searchButton = document.getElementById(config.controls.searchButton);

            function search() {
                const text = (searchInput.value || '').trim();

                if (text === '') {
                    return;
                }

                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(text))
                    .then(function (response) { return response.json(); })
                    .then(function (results) {
                        if (!results.length) {
                            alert('No se encontró esa dirección. Marque el punto a mano sobre el mapa.');
                            return;
                        }

                        setPoint(parseFloat(results[0].lat), parseFloat(results[0].lon), true);
                    })
                    .catch(function () {
                        alert('No se pudo consultar el buscador de direcciones.');
                    });
            }

            if (searchButton && searchInput) {
                searchButton.addEventListener('click', search);

                searchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        // Enter aquí no debe enviar el formulario entero
                        event.preventDefault();
                        search();
                    }
                });
            }

            // ---- Quitar el punto ----
            const clearButton = document.getElementById(config.controls.clear);

            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    latitudeInput.value = '';
                    longitudeInput.value = '';

                    if (marker) {
                        map.removeLayer(marker);
                        marker = null;
                    }
                });
            }

            return map;
        }

        /* ============================================================
           Arranque perezoso

           Los mapas NO se crean todos al cargar la página: en el listado
           de órdenes hay un modal por fila, y arrancar cincuenta mapas
           que nadie va a mirar cuesta memoria y una tanda de peticiones
           de teselas por cada uno.

           Además, un mapa creado dentro de un contenedor oculto se
           dibuja partido —su contenedor mide 0 px— y hay que avisarle
           con invalidateSize() cuando aparece. Las dos cosas se
           resuelven igual: se crea cuando se ve.
           ============================================================ */
        const maps = {};

        function ensure(id) {
            if (maps[id]) {
                maps[id].invalidateSize();
                return;
            }

            const container = document.getElementById(id);
            const node = document.querySelector('script[data-gestisp-map="' + id + '"]');

            if (!container || !node) {
                return;
            }

            let config;

            try {
                config = JSON.parse(node.textContent);
            } catch (error) {
                return;
            }

            maps[id] = config.mode === 'picker'
                ? buildPicker(container, config)
                : buildViewer(container, config);

            setTimeout(function () { maps[id].invalidateSize(); }, 200);
        }

        function ensureVisibleWithin(root) {
            $(root).find('.gestisp-map').addBack('.gestisp-map').each(function () {
                if (this.id) {
                    ensure(this.id);
                }
            });
        }

        $(function () {
            // Los que ya se ven al cargar (fichas, formularios)
            document.querySelectorAll('.gestisp-map').forEach(function (container) {
                if (container.offsetParent !== null) {
                    ensure(container.id);
                }
            });

            // Y los que aparecen después. AdminLTE 3 usa Bootstrap 4,
            // pero algunas pantallas cargan además Bootstrap 5: los dos
            // emiten shown.bs.modal y los dos lo propagan, así que un
            // único listener delegado sirve para ambos.
            $(document).on('shown.bs.modal', function (event) {
                ensureVisibleWithin(event.target);
            });

            $(document).on('shown.bs.tab shown.bs.collapse', function (event) {
                // En las pestañas el evento lo emite el enlace, no el
                // panel: se busca en toda la página, que es barato
                // porque ensure() ignora lo que sigue oculto.
                document.querySelectorAll('.gestisp-map').forEach(function (container) {
                    if (container.offsetParent !== null) {
                        ensure(container.id);
                    }
                });
            });
        });
    })();
</script>
