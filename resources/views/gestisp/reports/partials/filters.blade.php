{{--
    Barra de filtros común a todas las pantallas del módulo.

    Los filtros viajan por la URL (GET) a propósito: así un informe
    concreto se puede guardar en favoritos o enviar por correo, y el
    botón de PDF descarga exactamente lo que se está viendo.

    Parámetros: $period, $granularidades, $rutaPdf, $sucursales
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

            {{-- ============================================================
                 QUÉ SUCURSALES ENTRAN EN EL INFORME

                 Solo aparece en panel consolidado con más de una sede:
                 en modo independiente manda la activa y no hay nada
                 que elegir.

                 A LA VISTA Y NO EN UN DESPLEGABLE
                 ---------------------------------
                 Estaban dentro de un dropdown y no se veían: quien
                 abría el informe no sabía qué sedes estaba sumando sin
                 desplegar el menú, y las casillas salían recortadas
                 contra el borde. Ahora son fichas que se marcan y se
                 desmarcan, y el estado se lee de un vistazo.

                 Son varias casillas y no un desplegable múltiple
                 porque la pregunta real no es «cuál» sino «cuáles»:
                 ver una sede, sumar varias, o verlas todas menos una.
                 Un desplegable múltiple obliga a saber que hay que
                 dejar pulsada la tecla de control; una ficha se
                 entiende sola y funciona igual con el dedo.

                 Van dentro del mismo formulario GET que el resto, así
                 que viajan por la URL como los demás filtros — y el
                 botón de PDF, que arrastra request()->query(), se las
                 lleva sin tener que hacer nada.

                 Ninguna marcada = todas. Lo resuelve el controlador.
                 ============================================================ --}}
            @if(isset($sucursales) && $sucursales->isNotEmpty())
                @php
                    $marcadas = collect(request()->query('sucursales', []))
                        ->map(fn ($id) => (int) $id);
                    // Sin nada en la URL están todas dentro, y las
                    // fichas tienen que reflejarlo.
                    $todasDentro = $marcadas->isEmpty();
                @endphp

                <div class="form-group col-12 mb-2" id="filtroSucursales">
                    <label class="mb-1 small text-muted d-block">
                        Sucursales
                        <span class="ml-1 text-muted">·</span>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline"
                                data-sucursales="todas">todas</button>
                        <span class="text-muted">/</span>
                        <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-muted"
                                data-sucursales="ninguna">ninguna</button>
                    </label>

                    <div class="d-flex flex-wrap">
                        @foreach ($sucursales as $sucursal)
                            {{-- Bloque completo y no @php(...) en linea: la forma
                                 corta se empareja con el siguiente @endphp y se
                                 traga el resto de la plantilla. Ya ha pasado
                                 tres veces en este proyecto. --}}
                            @php
                                $dentro = $todasDentro || $marcadas->contains($sucursal->id);
                            @endphp
                            <label class="btn btn-sm mr-2 mb-1 ficha-sucursal
                                          {{ $dentro ? 'btn-primary' : 'btn-outline-secondary' }}">
                                {{-- La casilla va oculta pero SIGUE en el formulario: es
                                     ella la que viaja en la URL. La ficha es su etiqueta,
                                     así que hacer clic en la ficha la marca. --}}
                                <input type="checkbox" class="d-none casilla-sucursal"
                                       name="sucursales[]" value="{{ $sucursal->id }}"
                                       @checked($dentro)>
                                <i class="fas fa-{{ $dentro ? 'check-square' : 'square' }} mr-1"></i>
                                {{ $sucursal->name }}
                            </label>
                        @endforeach
                    </div>

                    <small class="form-text text-muted">
                        <span id="resumenSucursales"></span>
                        Sin ninguna marcada se informa de todas.
                    </small>
                </div>
            @endif

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

{{-- El JS solo hace falta si el selector se ha pintado. Antes se
     emitia siempre, aunque no hubiera nada que gobernar. --}}
@if(isset($sucursales) && $sucursales->isNotEmpty())
@once
    @push('js')
        <script>
            (function () {
                const filtro = document.getElementById('filtroSucursales');

                // Solo existe en panel consolidado con varias sedes.
                if (!filtro) {
                    return;
                }

                const casillas = Array.from(filtro.querySelectorAll('.casilla-sucursal'));
                const resumen = document.getElementById('resumenSucursales');
                const total = casillas.length;

                function pintar(casilla) {
                    const ficha = casilla.closest('.ficha-sucursal');
                    const icono = ficha.querySelector('i');

                    ficha.classList.toggle('btn-primary', casilla.checked);
                    ficha.classList.toggle('btn-outline-secondary', !casilla.checked);
                    icono.classList.toggle('fa-check-square', casilla.checked);
                    icono.classList.toggle('fa-square', !casilla.checked);
                }

                function refrescar() {
                    casillas.forEach(pintar);

                    const marcadas = casillas.filter((c) => c.checked).length;

                    // Se dice en palabras lo que va a salir, porque
                    // "ninguna marcada" y "todas marcadas" dan el mismo
                    // informe y eso no es evidente mirando las fichas.
                    if (marcadas === 0 || marcadas === total) {
                        resumen.textContent = 'Se informará de las ' + total + ' sucursales. ';
                    } else if (marcadas === 1) {
                        resumen.textContent = 'Se informará de 1 sucursal. ';
                    } else {
                        resumen.textContent = 'Se informará de ' + marcadas + ' de ' + total + ' sucursales. ';
                    }
                }

                casillas.forEach((c) => c.addEventListener('change', refrescar));

                filtro.querySelectorAll('[data-sucursales]').forEach((boton) => {
                    boton.addEventListener('click', function () {
                        const marcar = this.dataset.sucursales === 'todas';

                        casillas.forEach((c) => { c.checked = marcar; });
                        refrescar();
                    });
                });

                refrescar();
            })();
        </script>
    @endpush
@endonce
@endif
