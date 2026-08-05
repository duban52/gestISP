{{-- ============================================================
     Formulario de cable

     La capacidad solo se puede fijar al CREARLO: cambiarla después
     obligaría a borrar o crear hilos, y los que ya estuvieran
     fusionados se llevarían por delante la documentación de por dónde
     va cada cliente. Un cable de otra capacidad es otro cable.
     ============================================================ --}}
@csrf

@php
    $editando = isset($cable);

    // Los extremos se mandan como "mufla:12" en vez del nombre de la
    // clase de PHP: meter nombres de clase en un formulario invitaría a
    // que alguien mande cualquier otra cosa.
    $valorExtremo = function ($tipo, $id) {
        return match ($tipo) {
            \App\Models\Olt::class => 'olt:' . $id,
            \App\Models\SpliceClosure::class => 'mufla:' . $id,
            \App\Models\NapBox::class => 'caja:' . $id,
            default => '',
        };
    };
@endphp

<div class="card-body">
    <div class="row">
        <div class="col-md-4 form-group">
            <label for="optical_network_id">Red <span class="text-danger">*</span></label>
            <select name="optical_network_id" id="optical_network_id" class="form-control" required>
                <option value="">Elija la red…</option>
                @foreach($redes as $red)
                    <option value="{{ $red->id }}"
                        @selected((string) old('optical_network_id', $cable->optical_network_id ?? $redPreseleccionada ?? '') === (string) $red->id)>
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
        </div>
        <div class="col-md-4 form-group">
            <label for="type">Tipo <span class="text-danger">*</span></label>
            <select name="type" id="type" class="form-control" required>
                @foreach(\App\Models\FiberCable::TIPOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected(old('type', $cable->type ?? 'distribucion') === $clave)>
                        {{ $texto }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 form-group">
            <label for="code">Código <span class="text-danger">*</span></label>
            <input type="text" name="code" id="code" class="form-control" maxlength="30"
                   value="{{ old('code', $cable->code ?? '') }}" placeholder="TRC-001" required>
        </div>
        <div class="col-md-8 form-group">
            <label for="name">Nombre</label>
            <input type="text" name="name" id="name" class="form-control"
                   value="{{ old('name', $cable->name ?? '') }}" placeholder="Troncal salida OLT hacia el centro">
        </div>
    </div>

    <h6 class="text-uppercase text-muted mt-3">Capacidad</h6>

    @if($editando)
        <div class="alert alert-light border py-2">
            <i class="fas fa-lock text-muted"></i>
            <strong>{{ $cable->capacidad_legible }}</strong>.
            La capacidad no se puede cambiar: los hilos ya están creados y algunos pueden estar
            fusionados. Un cable de otra capacidad es otro cable.
        </div>
        <input type="hidden" name="fiber_count" value="{{ $cable->fiber_count }}">
        <input type="hidden" name="buffer_count" value="{{ $cable->buffer_count }}">
        <input type="hidden" name="fibers_per_buffer" value="{{ $cable->fibers_per_buffer }}">
    @else
        <div class="row">
            <div class="col-md-4 form-group">
                <label for="capacidadSugerida">Capacidad habitual</label>
                <select id="capacidadSugerida" class="form-control">
                    <option value="">Elija una…</option>
                    @foreach($capacidades as $c)
                        <option value="{{ $c['hilos'] }}|{{ $c['buffers'] }}|{{ $c['por_buffer'] }}">
                            {{ $c['hilos'] }} hilos ({{ $c['buffers'] }} × {{ $c['por_buffer'] }})
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">Rellena los tres campos de al lado.</small>
            </div>
            <div class="col-md-3 form-group">
                <label for="fiber_count">Hilos en total <span class="text-danger">*</span></label>
                <input type="number" name="fiber_count" id="fiber_count" class="form-control" min="1" max="576"
                       value="{{ old('fiber_count', 12) }}" required>
            </div>
            <div class="col-md-2 form-group">
                <label for="buffer_count">Buffers <span class="text-danger">*</span></label>
                <input type="number" name="buffer_count" id="buffer_count" class="form-control" min="1" max="48"
                       value="{{ old('buffer_count', 1) }}" required>
            </div>
            <div class="col-md-3 form-group">
                <label for="fibers_per_buffer">Hilos por buffer <span class="text-danger">*</span></label>
                <input type="number" name="fibers_per_buffer" id="fibers_per_buffer" class="form-control" min="1" max="24"
                       value="{{ old('fibers_per_buffer', 12) }}" required>
            </div>
        </div>

        <div class="alert alert-light border py-2 small" id="avisoReparto">
            <i class="fas fa-info-circle text-muted"></i>
            <span id="textoReparto"></span>
        </div>
    @endif

    <h6 class="text-uppercase text-muted mt-3">Recorrido</h6>

    <div class="row">
        <div class="col-md-6 form-group">
            <label for="from">Desde</label>
            <select name="from" id="from" class="form-control">
                <option value="">Sin definir</option>
                <optgroup label="OLTs">
                    @foreach($extremos['olts'] as $e)
                        <option value="olt:{{ $e['id'] }}"
                            @selected(old('from', $editando ? $valorExtremo($cable->from_type, $cable->from_id) : '') === 'olt:' . $e['id'])>
                            {{ $e['texto'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Muflas">
                    @foreach($extremos['muflas'] as $e)
                        <option value="mufla:{{ $e['id'] }}"
                            @selected(old('from', $editando ? $valorExtremo($cable->from_type, $cable->from_id) : '') === 'mufla:' . $e['id'])>
                            {{ $e['texto'] }}
                        </option>
                    @endforeach
                </optgroup>
            </select>
            <small class="form-text text-muted">
                Un cable que sale de una OLT es por donde entra la señal a la red: sin ese
                extremo, el análisis de impacto no tiene desde dónde empezar.
            </small>
        </div>
        <div class="col-md-6 form-group">
            <label for="to">Hasta</label>
            <select name="to" id="to" class="form-control">
                <option value="">Sin definir</option>
                <optgroup label="Muflas">
                    @foreach($extremos['muflas'] as $e)
                        <option value="mufla:{{ $e['id'] }}"
                            @selected(old('to', $editando ? $valorExtremo($cable->to_type, $cable->to_id) : '') === 'mufla:' . $e['id'])>
                            {{ $e['texto'] }}
                        </option>
                    @endforeach
                </optgroup>
                <optgroup label="Cajas NAP">
                    @foreach($extremos['cajas'] as $e)
                        <option value="caja:{{ $e['id'] }}"
                            @selected(old('to', $editando ? $valorExtremo($cable->to_type, $cable->to_id) : '') === 'caja:' . $e['id'])>
                            {{ $e['texto'] }}
                        </option>
                    @endforeach
                </optgroup>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 form-group">
            <label for="length_m">Longitud (metros)</label>
            <input type="number" name="length_m" id="length_m" class="form-control" min="0" max="200000"
                   value="{{ old('length_m', $cable->length_m ?? '') }}">
        </div>
        <div class="col-md-3 form-group">
            <label for="installation">Instalación</label>
            <select name="installation" id="installation" class="form-control">
                <option value="">Sin especificar</option>
                @foreach(\App\Models\FiberCable::INSTALACIONES as $clave => $texto)
                    <option value="{{ $clave }}" @selected(old('installation', $cable->installation ?? '') === $clave)>
                        {{ $texto }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 form-group">
            <label for="owner">Propietario</label>
            <input type="text" name="owner" id="owner" class="form-control"
                   value="{{ old('owner', $cable->owner ?? '') }}" placeholder="Propio / arrendado a…">
        </div>
        <div class="col-md-3 form-group">
            <label for="status">Estado <span class="text-danger">*</span></label>
            <select name="status" id="status" class="form-control" required>
                @foreach(['operativo' => 'Operativo', 'averiado' => 'Averiado', 'retirado' => 'Retirado'] as $clave => $texto)
                    <option value="{{ $clave }}" @selected(old('status', $cable->status ?? 'operativo') === $clave)>
                        {{ $texto }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="form-group">
        <label for="notes">Observaciones</label>
        <input type="text" name="notes" id="notes" class="form-control" maxlength="1000"
               value="{{ old('notes', $cable->notes ?? '') }}">
    </div>
</div>

<div class="card-footer">
    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Guardar
    </button>
    <a href="{{ route('cables.index') }}" class="btn btn-secondary">Cancelar</a>
</div>

@section('js')
    <script>
        @php
            $zonasPorRed = $redes->mapWithKeys(fn ($r) => [
                $r->id => $r->zones->map(fn ($z) => ['id' => $z->id, 'nombre' => $z->name])->values(),
            ]);
        @endphp

        const ZONAS = {!! $zonasPorRed->toJson() !!};
        const ZONA_ACTUAL = {!! json_encode(old('network_zone_id', $cable->network_zone_id ?? null)) !!};

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

            /* El reparto tiene que cuadrar: buffers × hilos por buffer
               debe dar el total. Se avisa aquí para no llegar al
               servidor con un cable que no existe. */
            function revisarReparto() {
                const total = parseInt($('#fiber_count').val(), 10) || 0;
                const buffers = parseInt($('#buffer_count').val(), 10) || 0;
                const porBuffer = parseInt($('#fibers_per_buffer').val(), 10) || 0;
                const producto = buffers * porBuffer;

                const $aviso = $('#avisoReparto');
                const $texto = $('#textoReparto');

                if ($aviso.length === 0) {
                    return;
                }

                if (producto === total && total > 0) {
                    $aviso.removeClass('alert-danger').addClass('alert-light');
                    $texto.html('Se generarán <strong>' + total + '</strong> hilos: ' +
                        buffers + ' buffer(s) de ' + porBuffer + ', con sus colores según la norma.');
                } else {
                    $aviso.removeClass('alert-light').addClass('alert-danger');
                    $texto.html('El reparto no cuadra: ' + buffers + ' × ' + porBuffer +
                        ' son <strong>' + producto + '</strong>, no ' + total + '.');
                }
            }

            $('#fiber_count, #buffer_count, #fibers_per_buffer').on('input', revisarReparto);

            $('#capacidadSugerida').on('change', function () {
                if (!this.value) {
                    return;
                }

                const [hilos, buffers, porBuffer] = this.value.split('|');

                $('#fiber_count').val(hilos);
                $('#buffer_count').val(buffers);
                $('#fibers_per_buffer').val(porBuffer);

                revisarReparto();
            });

            revisarReparto();
        });
    </script>
@endsection
