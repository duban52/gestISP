{{--
    Barra de filtros común a todas las pantallas del módulo.

    Los filtros viajan por la URL (GET) a propósito: así un informe
    concreto se puede guardar en favoritos o enviar por correo, y el
    botón de PDF descarga exactamente lo que se está viendo.

    Parámetros: $period, $granularidades, $rutaPdf
--}}
<div class="card card-outline card-primary">
    <div class="card-header py-2">
        <h3 class="card-title">
            <i class="fas fa-filter mr-1"></i> Parámetros del informe
        </h3>
        <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                <i class="fas fa-minus"></i>
            </button>
        </div>
    </div>

    <div class="card-body">
        <form method="GET" class="form-row align-items-end">

            <div class="form-group col-6 col-md-2 mb-2">
                <label class="mb-1 small text-muted">Desde</label>
                <input type="date" name="desde" class="form-control form-control-sm"
                       value="{{ $period->from->format('Y-m-d') }}">
            </div>

            <div class="form-group col-6 col-md-2 mb-2">
                <label class="mb-1 small text-muted">Hasta</label>
                <input type="date" name="hasta" class="form-control form-control-sm"
                       value="{{ $period->to->format('Y-m-d') }}">
            </div>

            <div class="form-group col-6 col-md-2 mb-2">
                <label class="mb-1 small text-muted">Agrupar por</label>
                <select name="granularidad" class="form-control form-control-sm">
                    @foreach ($granularidades as $g)
                        <option value="{{ $g->value }}" @selected($period->granularity === $g)>
                            {{ $g->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="form-group col-12 col-md mb-2 text-md-right">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-sync-alt mr-1"></i> Aplicar
                </button>
                @isset($rutaPdf)
                    <a href="{{ $rutaPdf }}?{{ http_build_query(request()->query()) }}"
                       class="btn btn-sm btn-danger">
                        <i class="fas fa-file-pdf mr-1"></i> PDF
                    </a>
                @endisset
            </div>

            {{-- Atajos de rango: cubren lo que se consulta a diario
                 sin obligar a escribir dos fechas cada vez --}}
            <div class="col-12 mt-1">
                <span class="small text-muted mr-2">Rangos rápidos:</span>
                @php
                    $atajos = [
                        'Este mes' => [now()->startOfMonth(), now(), 'day'],
                        'Mes anterior' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth(), 'day'],
                        'Últimos 3 meses' => [now()->subMonths(2)->startOfMonth(), now(), 'week'],
                        'Últimos 12 meses' => [now()->subMonths(11)->startOfMonth(), now(), 'month'],
                        'Este año' => [now()->startOfYear(), now(), 'month'],
                        'Histórico' => [now()->subYears(5)->startOfYear(), now(), 'year'],
                    ];
                @endphp
                @foreach ($atajos as $etiqueta => [$d, $h, $gran])
                    <a class="btn btn-xs btn-outline-secondary mb-1"
                       href="{{ request()->fullUrlWithQuery([
                            'desde' => $d->format('Y-m-d'),
                            'hasta' => $h->format('Y-m-d'),
                            'granularidad' => $gran,
                       ]) }}">{{ $etiqueta }}</a>
                @endforeach
            </div>
        </form>
    </div>
</div>

{{-- El rango es tan amplio que la granularidad elegida produciría
     cientos de puntos: se avisa y se ofrece la alternativa. --}}
@if ($period->demasiadoLargo())
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle mr-1"></i>
        El rango seleccionado es muy amplio para una vista
        <strong>{{ strtolower($period->granularity->label()) }}</strong>: la gráfica quedará
        difícil de leer.
        <a href="{{ request()->fullUrlWithQuery(['granularidad' => $period->granularidadSugerida()->value]) }}"
           class="alert-link">
            Ver en {{ strtolower($period->granularidadSugerida()->label()) }}
        </a>.
    </div>
@endif

{{-- Estados de contrato que no encajan en ningún grupo conocido.
     Se avisa porque sus contratos no se están contando en ninguna
     categoría y el total quedaría corto sin explicación. --}}
@if (!empty($estadosSinClasificar))
    <div class="alert alert-danger">
        <i class="fas fa-database mr-1"></i>
        <strong>Calidad de datos:</strong> hay contratos con estados que el informe no reconoce
        ({{ implode(', ', $estadosSinClasificar) }}). Aparecen agrupados como
        <em>Sin clasificar</em> y conviene normalizarlos para que las cifras cuadren.
    </div>
@endif
