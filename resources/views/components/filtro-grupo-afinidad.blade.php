{{--
    Filtro por grupo de afinidad.

    Va donde la pregunta tiene sentido: documentos que salen de un
    contrato —facturas, pagos— y por tanto heredan su clasificación.

    Se ofrecen TAMBIÉN los grupos inactivos: hay contratos que siguen
    en un grupo que dejó de ofrecerse, y hay que poder encontrarlos.
    Es un filtro de búsqueda, no un desplegable de alta.

    El global scope de empresa ya acota los grupos a los de la empresa
    del contexto, así que aquí no hay que filtrar por company_id.
--}}
@props([
    'campo' => 'affinity_group_id',
    'clase' => 'col-md-3',
    'etiqueta' => 'Grupo de afinidad',
    'filtros' => null,
])

@php
    $gruposDelFiltro = \App\Models\AffinityGroup::ordenados()->get();
    $elegidos = (array) ($filtros[$campo] ?? request()->input($campo, []));
    $elegidos = array_map('strval', is_array($elegidos) ? $elegidos : [$elegidos]);
@endphp

@if($gruposDelFiltro->isNotEmpty())
    <div class="{{ $clase }} form-group">
        <label>{{ $etiqueta }}</label>
        <select name="{{ $campo }}[]" class="form-control select-multiple" multiple>
            @foreach($gruposDelFiltro as $grupoDelFiltro)
                <option value="{{ $grupoDelFiltro->id }}"
                        @selected(in_array((string) $grupoDelFiltro->id, $elegidos, true))>
                    {{ $grupoDelFiltro->etiqueta() }}@unless($grupoDelFiltro->active) (inactivo)@endunless
                </option>
            @endforeach
        </select>
    </div>
@endif
