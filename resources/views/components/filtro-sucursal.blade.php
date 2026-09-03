{{--
    Filtro por sucursal para los buscadores de los listados.

    CUÁNDO APARECE
    --------------
    Solo en panel consolidado y con más de una sucursal alcanzable —el
    mismo criterio que la columna «Sucursal», y por el mismo motivo: en
    modo independiente solo hay una y filtrar por ella no quita nada.

    Lo decide CurrentContext::mostrarSucursal(), que llega a todas las
    vistas por un view composer.

    POR QUÉ NO HACE FALTA COMPROBAR NADA MÁS
    ----------------------------------------
    Se ofrecen únicamente las sucursales del alcance del usuario, pero
    la seguridad no depende de eso: el listado aplica SIEMPRE su filtro
    de alcance además de este. Pedir por URL una sede ajena da cero
    resultados —las dos condiciones se cruzan— en vez de abrirla, y
    tampoco da 403, que confirmaría que existe.

    USO
    ---
        <x-filtro-sucursal />
        <x-filtro-sucursal clase="col-md-2" :filtros="$filtros" />
--}}
@props([
    'campo' => 'branch_id',
    'clase' => 'col-md-3',
    'etiqueta' => 'Sucursal',
    // Los filtros ya aplicados, para dejar marcadas las elegidas.
    // Se admite tanto el array de filtros del controlador como nada:
    // si no llega, se leen de la petición.
    'filtros' => null,
])

@php
    $contextoFiltro = app(\App\Tenancy\CurrentContext::class);
    $elegidas = (array) ($filtros[$campo] ?? request()->input($campo, []));
    $elegidas = array_map('strval', is_array($elegidas) ? $elegidas : [$elegidas]);
@endphp

@if($contextoFiltro->mostrarSucursal())
    <div class="{{ $clase }} form-group">
        <label>{{ $etiqueta }}</label>
        <select name="{{ $campo }}[]" class="form-control select-multiple" multiple>
            @foreach($contextoFiltro->sucursalesElegibles() as $sucursalDelFiltro)
                <option value="{{ $sucursalDelFiltro->id }}"
                        @selected(in_array((string) $sucursalDelFiltro->id, $elegidas, true))>
                    {{ $sucursalDelFiltro->name }}
                </option>
            @endforeach
        </select>
    </div>
@endif
