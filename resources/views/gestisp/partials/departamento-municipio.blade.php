{{--
    Departamento y municipio, como selects con buscador.

    Son 32 departamentos y 1.104 municipios: escribirlos a mano deja
    «Medellin», «medellín» y «Medellín» conviviendo en la base, y
    cualquier informe por ciudad los cuenta como tres. Aquí se eligen
    de una lista, y el municipio depende del departamento.

    El catálogo sale de ColombiaLocations, que ya lo trae comprimido
    dentro del código — sin consultas ni servicios externos.

    Parámetros:
      $departamento  valor actual (null al crear)
      $municipio     valor actual (null al crear)
      $requerido     si los campos son obligatorios (por defecto, sí)

    La página que lo incluya tiene que activar Select2:
        @section('plugins.Select2', true)
--}}
@php
    $ubicaciones = \App\Support\ColombiaLocations::departmentsWithMunicipalities();
    $requerido = $requerido ?? true;
    $departamentoActual = old('department', $departamento ?? null);
    $municipioActual = old('municipality', $municipio ?? null);
@endphp

<div class="form-group col-md-6">
    <label for="department">Departamento @if($requerido)<span class="text-danger">*</span>@endif</label>
    <select class="form-control @error('department') is-invalid @enderror"
            id="department" name="department" @if($requerido) required @endif>
        <option value="">Seleccione un departamento</option>
        @foreach($ubicaciones as $departamentoNombre => $municipios)
            <option value="{{ $departamentoNombre }}" @selected($departamentoActual === $departamentoNombre)>
                {{ $departamentoNombre }}
            </option>
        @endforeach
    </select>
    @error('department')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="form-group col-md-6">
    <label for="municipality">Ciudad / Municipio @if($requerido)<span class="text-danger">*</span>@endif</label>
    <select class="form-control @error('municipality') is-invalid @enderror"
            id="municipality" name="municipality"
            @if($requerido) required @endif
            @if(!$departamentoActual) disabled @endif>
        <option value="">Primero seleccione un departamento</option>
        @if($departamentoActual)
            @foreach($ubicaciones[$departamentoActual] ?? [] as $municipioNombre)
                <option value="{{ $municipioNombre }}" @selected($municipioActual === $municipioNombre)>
                    {{ $municipioNombre }}
                </option>
            @endforeach
        @endif
    </select>
    @error('municipality')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const municipiosPorDepartamento = @json($ubicaciones, JSON_UNESCAPED_UNICODE);
            const $departamento = $('#department');
            const $municipio = $('#municipality');

            // Select2 añade el buscador, que es lo que hace usable una
            // lista de 1.104 municipios.
            $departamento.select2({
                width: '100%',
                placeholder: 'Busque o seleccione un departamento',
                allowClear: true,
            });

            $municipio.select2({
                width: '100%',
                placeholder: 'Busque o seleccione una ciudad o municipio',
                allowClear: true,
            });

            function cargarMunicipios(seleccionado = '') {
                const departamento = $departamento.val();
                const municipios = municipiosPorDepartamento[departamento] || [];

                $municipio.empty();

                if (!departamento) {
                    $municipio
                        .prop('disabled', true)
                        .append(new Option('Primero seleccione un departamento', ''))
                        .trigger('change');

                    return;
                }

                $municipio
                    .prop('disabled', false)
                    .append(new Option('Busque o seleccione una ciudad o municipio', ''));

                municipios.forEach(function (municipio) {
                    $municipio.append(new Option(municipio, municipio, false, municipio === seleccionado));
                });

                $municipio.trigger('change');
            }

            // Al cambiar de departamento el municipio anterior deja de
            // tener sentido: se limpia en vez de quedarse mintiendo.
            $departamento.on('change', function () {
                cargarMunicipios();
            });
        });
    </script>
@endpush
