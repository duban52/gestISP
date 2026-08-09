@extends('adminlte::page')

@section('title', 'Procesar Orden Técnica')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center mb-0">
        <h2 class="mb-0">VER Y PROCESAR ORDEN {{ $technicalOrder->id }}</h2>
        <a href="{{ route('technicals_orders.my_technical_orders') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">&times;</button>
            @foreach($errors->all() as $error)
                <div><i class="fas fa-exclamation-triangle mr-1"></i> {{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- ============================================================
         Orden devuelta por el supervisor

         Va arriba del todo y no dentro del detalle: es lo primero que
         el técnico tiene que leer, porque cambia lo que va a hacer.
         ============================================================ --}}
    @php
        $motivoDevolucion = $technicalOrder->returnReason();
    @endphp

    @if($motivoDevolucion)
        <div class="alert alert-warning">
            <h5 class="mb-1"><i class="fas fa-undo mr-1"></i> Esta orden se le devolvió para corregir</h5>
            <p class="mb-1"><strong>Motivo:</strong> {{ $motivoDevolucion }}</p>
            <small class="text-muted">
                Revisado por
                {{ trim(($technicalOrder->lastVerification()?->verifiedByUser?->name ?? '')
                    . ' ' . ($technicalOrder->lastVerification()?->verifiedByUser?->last_name ?? '')) ?: 'un supervisor' }}
                el {{ $technicalOrder->lastVerification()?->created_at?->format('Y-m-d H:i') }}.
                @if($technicalOrder->hasClosingLocation())
                    Se conserva la ubicación del primer cierre; no hace falta que vuelva al sitio solo por eso.
                @endif
            </small>
        </div>
    @endif

    <div class="row">
        {{-- ============================================================
             Columna izquierda: información de la orden y el cliente
             ============================================================ --}}
        <div class="card p-3 mt-1 col-md-6">
            <h3>Datos del cliente</h3>
            <p><strong>Número de contrato:</strong> {{ $technicalOrder->contract->numero_visible }}</p>
            <p><strong>Identificación del cliente:</strong> {{ $technicalOrder->contract->client->identity_number }}</p>
            <p><strong>Nombre y apellido:</strong>
                {{ $technicalOrder->contract->client->name }}
                {{ $technicalOrder->contract->client->last_name }}
            </p>
            <p><strong>Teléfonos:</strong>
                {{ $technicalOrder->contract->client->number_phone }}{{ $technicalOrder->contract->client->aditional_phone ? ', ' . $technicalOrder->contract->client->aditional_phone : '' }}
            </p>
            <hr>
            <h3>Residencia</h3>
            <p><strong>Barrio:</strong> {{ $technicalOrder->contract->neighborhood }}</p>
            <p><strong>Dirección:</strong> {{ $technicalOrder->contract->address }}</p>

            {{-- ============================================================
                 Dónde queda el servicio

                 En barrios sin nomenclatura y en zona rural la dirección
                 escrita no lleva a ninguna parte: el punto en el mapa sí.
                 ============================================================ --}}
            @if($technicalOrder->contract->isGeolocated())
                @php
                    // Los parámetros se arman aquí y no dentro de la directiva
                    // include: Blade corta su argumento en el primer paréntesis
                    // que cree de cierre sin contar los corchetes.
                    $parametrosMapaVivienda = [
                        'mapId' => 'mapaViviendaOrden',
                        'height' => '260px',
                        'markers' => [[
                            'lat' => $technicalOrder->contract->latitude,
                            'lng' => $technicalOrder->contract->longitude,
                            'title' => 'Vivienda del cliente',
                            'icon' => 'fa-home',
                            'color' => 'primary',
                        ]],
                    ];
                @endphp

                @include('gestisp.partials.location-map', $parametrosMapaVivienda)

                <a class="btn btn-sm btn-outline-primary mt-2"
                   target="_blank" rel="noopener"
                   href="https://www.google.com/maps/dir/?api=1&destination={{ $technicalOrder->contract->latitude }},{{ $technicalOrder->contract->longitude }}">
                    <i class="fas fa-directions"></i> Cómo llegar
                </a>
            @else
                <div class="alert alert-warning py-2 small mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    Este servicio no está ubicado en el mapa. Si va al sitio, pida que lo
                    georreferencien desde la ficha del contrato.
                </div>
            @endif

            {{-- ============================================================
                 A qué caja conectar

                 Solo aparece en instalaciones y traslados, que son las
                 órdenes que estrenan acometida. Es una SUGERENCIA por
                 distancia en línea recta: entre la casa y la caja puede
                 haber una avenida sin cruce, así que decide el técnico.
                 ============================================================ --}}
            @if($napSuggestions->isNotEmpty())
                <hr>
                <h3>Cajas NAP cercanas</h3>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-1">
                        <thead class="thead-light">
                        <tr>
                            <th>Caja</th>
                            <th>Distancia</th>
                            <th>Libres</th>
                            <th>Puerto sugerido</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($napSuggestions as $sugerencia)
                            <tr @class(['table-success' => $loop->first && $sugerencia->hasRoom()])>
                                <td>
                                    <strong>{{ $sugerencia->napBox->code }}</strong>
                                    @if($sugerencia->napBox->zone)
                                        <br><small class="text-muted">{{ $sugerencia->napBox->zone->name }}</small>
                                    @endif
                                </td>
                                <td>{{ $sugerencia->humanDistance() }}</td>
                                <td>{{ $sugerencia->freePorts }} / {{ $sugerencia->napBox->capacity }}</td>
                                <td>
                                    @if($sugerencia->hasRoom())
                                        <span class="badge badge-success">
                                            Puerto {{ $sugerencia->nextFreePort->number }}
                                        </span>
                                    @else
                                        <span class="badge badge-danger">Sin cupo</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <small class="text-muted">
                    Distancia en línea recta. El puerto queda asignado cuando oficina lo
                    registra en la ficha del contrato, no por aparecer aquí.
                </small>
            @endif

            <hr>
            <h3>Datos de la orden</h3>
            <p><strong>Tipo de orden:</strong> {{ $technicalOrder->type }}</p>
            <p>
                <strong>Detalle de orden:</strong> {{ $technicalOrder->detail }}
                @if($requiresMaterial)
                    <span class="badge badge-warning ml-1">Requiere material</span>
                @endif
            </p>
            <p><strong>Comentario inicial:</strong> {{ $technicalOrder->initial_comment ?? '—' }}</p>
            <p>
                <strong>Creada el:</strong> {{ $technicalOrder->created_at?->format('Y-m-d H:i') ?? 'N/A' }}
                <strong>Por:</strong>
                {{ $technicalOrder->createdBy->name ?? 'Sistema' }}
                {{ $technicalOrder->createdBy->last_name ?? '' }}
            </p>
        </div>

        {{-- ============================================================
             Columna derecha: formulario de procesamiento
             ============================================================ --}}
        <div class="card mt-1 p-3 col-md-6">
            <h3>Procesamiento de orden</h3>
            <form action="{{ route('technicals_orders.process', $technicalOrder->id) }}" method="post"
                  enctype="multipart/form-data" id="process-order-form"
                  data-requires-material="{{ $requiresMaterial ? '1' : '0' }}"
                  data-has-signature="{{ $technicalOrder->client_signature ? '1' : '0' }}">
                @csrf
                <div class="form-group">
                    <label for="observations_technical">Comentario del técnico</label>
                    <textarea class="form-control" name="observations_technical"
                              id="observations_technical" required>{{ old('observations_technical') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="client_observation">Comentario del usuario</label>
                    <textarea class="form-control" name="client_observation"
                              id="client_observation" required>{{ old('client_observation') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="solution">Solución aplicada</label>
                    <textarea class="form-control" name="solution"
                              id="solution" required>{{ old('solution') }}</textarea>
                </div>
                <div class="form-group">
                    <label for="images">Selecciona imágenes (evidencia):</label>
                    <input class="form-control-file" type="file" name="images[]" id="images"
                           multiple accept="image/*">
                </div>

                {{-- Materiales -------------------------------------------------- --}}
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <label class="mb-0 font-weight-bold">Materiales utilizados</label>
                    <button type="button" class="btn btn-primary btn-sm" id="open-modal-btn">
                        <i class="fas fa-plus"></i> Agregar Material
                    </button>
                </div>

                @if($requiresMaterial)
                    <div class="alert alert-info py-2 px-3 small mb-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Esta orden de <strong>instalación</strong> requiere registrar el material y
                        los equipos instalados antes de procesarla.
                    </div>
                @endif

                {{-- Materiales reportados (filas dinámicas de order_process.js) --}}
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle" id="materials-table">
                        <thead class="thead-light">
                        <tr>
                            <th>Material</th>
                            <th class="text-center" style="width: 90px;">Cantidad</th>
                            <th>Unidad</th>
                            <th>Números de Serie</th>
                            <th class="text-center" style="width: 70px;">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        {{-- Fila vacía inicial (la gestiona el JS) --}}
                        <tr id="no-materials-row">
                            <td colspan="5" class="text-center text-muted py-3">
                                <i class="fas fa-box-open mr-1"></i> Aún no se ha agregado material
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                {{-- ============================================================
                     Ubicación del cierre

                     Se toma sola al abrir la pantalla: pedirle al técnico
                     que pulse un botón más garantizaría que la mitad de las
                     órdenes llegaran sin punto.

                     NO bloquea el cierre. Un permiso denegado, un equipo sin
                     GPS o un sótano sin señal no pueden impedir cerrar un
                     trabajo que ya está hecho; en su lugar se guarda el
                     motivo, que es lo que distingue "no se pudo" de "no se
                     quiso" cuando alguien revise.
                     ============================================================ --}}
                <div class="form-group mt-3">
                    @if($technicalOrder->hasClosingLocation())
                        {{-- La orden ya se cerró una vez: se conserva AQUEL punto.
                             Volver a tomarlo ahora diría dónde está el técnico
                             mientras corrige un texto, no dónde hizo el trabajo. --}}
                        <label class="mb-1 font-weight-bold">Ubicación del cierre</label>
                        <div class="alert alert-info py-2 px-3 small mb-0">
                            <i class="fas fa-map-marker-alt mr-1"></i>
                            Ya quedó registrada la ubicación del primer cierre
                            @if($technicalOrder->closing_located_at)
                                ({{ $technicalOrder->closing_located_at->format('Y-m-d H:i') }})
                            @endif
                            y no se vuelve a tomar.
                        </div>
                    @else
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="mb-0 font-weight-bold">Ubicación del cierre</label>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="retry-location-btn">
                                <i class="fas fa-crosshairs mr-1"></i> Tomar ubicación
                            </button>
                        </div>
                        {{-- Estado inicial SIN spinner a propósito: si el JS no
                             llegara a ejecutarse, un spinner eterno haría creer
                             que se está buscando la posición. Así se ve que no
                             ha empezado. --}}
                        <div id="closing-location-status" class="alert alert-secondary py-2 px-3 small mb-0">
                            <i class="fas fa-map-marker-alt mr-1"></i> Sin tomar todavía.
                        </div>

                        <input type="hidden" name="closing_latitude" id="closing-latitude">
                        <input type="hidden" name="closing_longitude" id="closing-longitude">
                        <input type="hidden" name="closing_accuracy_m" id="closing-accuracy">
                        <input type="hidden" name="closing_location_error" id="closing-location-error">
                    @endif
                </div>

                {{-- Firma del cliente -------------------------------------- --}}
                <div class="form-group mt-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="mb-0 font-weight-bold">
                            Firma del cliente
                            @unless($technicalOrder->client_signature)
                                <span class="text-danger">*</span>
                            @endunless
                        </label>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="clear-signature-btn">
                            <i class="fas fa-eraser mr-1"></i> Borrar
                        </button>
                    </div>

                    @if($technicalOrder->client_signature)
                        {{-- Orden devuelta: la firma del cliente ya está tomada
                             de cuando estaba delante. Volver a pedirla dejaría al
                             técnico sin poder corregir desde la oficina, o le
                             empujaría a firmar él, que es lo contrario de lo que
                             la firma sirve. --}}
                        <div class="alert alert-info py-2 px-3 small mb-2">
                            <i class="fas fa-signature mr-1"></i>
                            Ya está guardada la firma del primer cierre. Solo firme de nuevo
                            si volvió al sitio y el cliente puede hacerlo.
                        </div>
                        <img src="{{ asset($technicalOrder->client_signature) }}"
                             alt="Firma registrada"
                             style="max-width: 260px; width: 100%; border: 1px solid #dee2e6; border-radius: 6px; background: #fff;"
                             class="mb-2">
                    @endif

                    <small class="form-text text-muted mb-2">
                        Pida al cliente que firme con el dedo o un lápiz táctil.
                    </small>
                    <div id="signature-wrapper">
                        <canvas id="signature-pad"></canvas>
                    </div>
                    {{-- La imagen (Data URL) la rellena order_process.js al enviar --}}
                    <input type="hidden" name="client_signature" id="client-signature-input">
                </div>

                <button type="submit" class="btn btn-success mt-2">
                    <i class="fas fa-check mr-1"></i> Procesar orden
                </button>
            </form>
        </div>
    </div>

    {{-- ============================================================
         Modal para agregar material

         Cada opción trae la disponibilidad y los seriales incrustados
         (data-*), así el modal no depende de ninguna llamada AJAX.
         ============================================================ --}}
    <div class="modal fade" id="materialModal" tabindex="-1" aria-labelledby="materialModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="materialModalLabel">
                        <i class="fas fa-box mr-1"></i> Agregar Material
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Material</label>
                        <select id="modal-material-select" class="form-control material-select" required>
                            <option value="">Seleccione un material</option>
                            @foreach ($materials as $material)
                                <option value="{{ $material->id }}"
                                        data-is-equipment="{{ $material->is_equipment ? '1' : '0' }}"
                                        data-name="{{ $material->name }}"
                                        data-available="{{ $material->total_quantity }}"
                                        data-serials='@json($material->serial_numbers)'>
                                    {{ $material->name }} (Disp: {{ $material->total_quantity }})
                                </option>
                            @endforeach
                        </select>
                        <small id="available-quantity-text" class="form-text text-muted" style="display: none;">
                            Disponible en tu almacén:
                            <span class="badge badge-info" id="available-quantity">0</span>
                        </small>
                    </div>

                    {{-- Consumibles: cantidad manual --}}
                    <div class="form-group" id="modal-quantity-group">
                        <label>Cantidad</label>
                        <input type="number" id="modal-quantity" class="form-control quantity-input" min="1" value="1">
                    </div>

                    <div class="form-group">
                        <label>Unidad de Medida</label>
                        <select id="modal-unit-of-measurement" class="form-control" required>
                            <option value="">Seleccione...</option>
                            <option value="Unidades">Unidades</option>
                            <option value="Metros">Metros</option>
                            <option value="Litros">Litros</option>
                            <option value="Paquetes">Paquetes</option>
                        </select>
                    </div>

                    {{-- Equipos: selección de seriales (la cantidad la
                         determina cuántos seriales se marquen) --}}
                    <div class="form-group" id="modal-serial-numbers-container" style="display:none;">
                        <label for="serial-number-select">Números de Serie a instalar</label>
                        <select id="serial-number-select" class="form-control" multiple>
                            {{-- Opciones incrustadas desde data-serials --}}
                        </select>
                        <small class="form-text text-muted">
                            La cantidad se calcula según los seriales seleccionados.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i> Cerrar
                    </button>
                    <button type="button" class="btn btn-primary" id="add-material-modal-btn">
                        <i class="fas fa-plus mr-1"></i> Agregar
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    @include('gestisp.partials.leaflet-styles')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        /* El desplegable del modal debe quedar por encima del backdrop */
        .select2-container { z-index: 1060; }
        #materials-table td, #materials-table th { vertical-align: middle; }
        .serial-badge {
            display: inline-block;
            background: #e9f2ff;
            color: #0b57d0;
            border: 1px solid #b6d4fe;
            border-radius: 12px;
            padding: 1px 8px;
            margin: 1px 2px;
            font-size: .78rem;
        }
        /* Diálogos SweetAlert con los botones de Bootstrap del sistema */
        .swal2-popup .swal2-styled.swal2-confirm { font-weight: 500; }
        /* Pad de firma: lienzo táctil */
        #signature-wrapper {
            border: 1px dashed #adb5bd;
            border-radius: 6px;
            background: #fff;
            touch-action: none; /* evita el scroll al firmar en móvil */
        }
        #signature-pad {
            display: block;
            width: 100%;
            height: 180px;
        }
    </style>
@endsection

@section('js')
    @include('gestisp.partials.leaflet-script')
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>

    {{-- CORRECCIÓN: la versión anterior cargaba
         /resources/js/technical_orders/order_process.js — esa ruta
         no existe en el navegador (resources/ no es pública) y falla
         en producción. Con Vite el archivo se compila y versiona. --}}
    @vite('resources/js/technical_orders/order_process.js')

    {{-- ============================================================
         ¿Llegó el script de esta pantalla?

         POR QUÉ HACE FALTA VIGILARLO
         ----------------------------
         `public/build` NO va en git (está en .gitignore): se compila
         al desplegar. Si ese paso se olvida, el servidor sigue
         sirviendo el paquete ANTERIOR. La página se ve perfecta —el
         HTML sí viaja por git— pero el JS que la anima es viejo: no
         toma la ubicación, no agrega material, y no aparece ningún
         error. Es un fallo mudo, y encima solo lo sufre el técnico en
         la calle, que no tiene cómo reportarlo más que diciendo "no me
         funciona".

         Este bloque va SUELTO en la vista, no en el paquete: tiene que
         seguir funcionando justo cuando el paquete es el que falla.
         ============================================================ --}}
    <script>
        setTimeout(function () {
            if (window.gestispOrderScriptLoaded) {
                return;
            }

            var aviso = document.createElement('div');
            aviso.className = 'alert alert-danger';
            aviso.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> ' +
                '<strong>No se cargó el script de esta pantalla.</strong> ' +
                'No se podrá tomar la ubicación ni agregar material. ' +
                'Avise a soporte: hay que recompilar los recursos del sistema en el servidor.';

            var contenedor = document.querySelector('.content-wrapper .content') || document.body;
            contenedor.insertBefore(aviso, contenedor.firstChild);

            // Y se corrige el mensaje de la ubicación, que si no se
            // quedaría diciendo que está buscando algo que nadie busca.
            var estado = document.getElementById('closing-location-status');

            if (estado) {
                estado.className = 'alert alert-danger py-2 px-3 small mb-0';
                estado.innerHTML = '<i class="fas fa-exclamation-triangle mr-1"></i> ' +
                    'No se cargó el script de la pantalla: no se puede tomar la ubicación.';
            }

            console.error('GestISP · no se ejecutó order_process.js (¿recursos sin recompilar en el servidor?)');
        }, 5000);
    </script>
@endsection
