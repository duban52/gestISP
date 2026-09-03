@extends('adminlte::page')

@section('title', 'Notas crédito y débito')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-file-invoice mr-2"></i>Notas crédito y débito</h1>
@stop

@section('content')
    @if(session('success'))
        <div class="alert alert-success alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-check-circle mr-1"></i> {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    <div class="callout callout-info">
        <p class="mb-0">
            Las notas corrigen una factura <strong>ya emitida sin modificarla</strong>:
            la <strong>nota crédito</strong> disminuye lo que el cliente debe (devoluciones,
            descuentos, anulaciones) y la <strong>nota débito</strong> lo aumenta
            (intereses, gastos por cobrar). Se emiten desde la factura que se quiere corregir.
        </p>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list mr-1"></i> {{ $notas->count() }} nota(s)</h3>
        </div>

        {{-- El grupo del contrato que corrige la nota. No es columna en
             ningun otro sitio, asi que sin este filtro no hay forma de
             acotar por el. La sucursal no se filtra aqui: ya es columna
             y esta tabla busca del lado del cliente. --}}
        @if(\App\Models\AffinityGroup::exists())
            <div class="card-body border-bottom pb-0">
                <form method="GET" action="{{ route('notes.index') }}">
                    <div class="row align-items-end">
                        @if(request()->filled('tipo'))
                            <input type="hidden" name="tipo" value="{{ request('tipo') }}">
                        @endif

                        <x-filtro-grupo-afinidad :filtros="$filtros" clase="col-md-4" />

                        <div class="col-md-4 form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter mr-1"></i> Filtrar
                            </button>
                            @if(!empty(array_filter($filtros ?? [])))
                                <a href="{{ route('notes.index') }}" class="btn btn-outline-secondary">
                                    Limpiar
                                </a>
                            @endif
                        </div>
                    </div>
                </form>
            </div>
        @endif
        <div class="card-body">
            <div class="table-responsive">
                <table id="notesTable" class="table table-hover table-sm w-100">
                    <thead class="thead-light">
                    <tr>
                        <th>Sucursal</th>
                        <th>Grupo</th>
                        <th>Número</th>
                        <th>Tipo</th>
                        <th>Fecha</th>
                        <th>Factura</th>
                        <th>N.º contrato</th>
                        <th>Cliente</th>
                        <th>Concepto</th>
                        <th class="text-right">Valor</th>
                        <th>Estado</th>
                        <th>Emitida por</th>
                        <th class="text-center">Ver</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($notas as $nota)
                        <tr @class(['text-muted' => !$nota->vigente])>
                            {{-- Solo se ve en panel consolidado; DataTables la
                                 oculta en los demas modos (ver el columnDef de
                                 mas abajo). Se pinta siempre para que los
                                 indices de columna no cambien segun el modo. --}}
                            <td>{{ $nota->branch?->name ?: '—' }}</td>
                            {{-- El grupo del contrato que corrige la nota. --}}
                            <td>{{ $nota->contract?->affinityGroup?->etiqueta() ?: '—' }}</td>
                            <td><strong>{{ $nota->full_number }}</strong></td>
                            <td>
                                <span class="badge badge-{{ $nota->tipo()->disminuye() ? 'success' : 'warning' }}">
                                    {{ $nota->etiqueta_tipo }}
                                </span>
                            </td>
                            <td data-order="{{ $nota->issue_date?->timestamp }}">
                                {{ $nota->issue_date?->format('d/m/Y') }}
                            </td>
                            <td>{{ $nota->invoice?->displayNumber() ?? '—' }}</td>
                            <td>{{ $nota->contract?->contract_number ?? '—' }}</td>
                            <td>
                                {{ trim(($nota->contract?->client?->name ?? '') . ' ' . ($nota->contract?->client?->last_name ?? '')) ?: '—' }}
                            </td>
                            <td><small>{{ $nota->concept_label }}</small></td>
                            <td class="text-right">
                                <strong class="{{ $nota->tipo()->disminuye() ? 'text-success' : 'text-warning' }}">
                                    {{ $nota->tipo()->disminuye() ? '−' : '+' }}${{ number_format((float) $nota->total, 0, ',', '.') }}
                                </strong>
                            </td>
                            <td>
                                @if($nota->vigente)
                                    <span class="badge badge-primary">Emitida</span>
                                @else
                                    <span class="badge badge-secondary" title="{{ $nota->void_reason }}">Anulada</span>
                                @endif
                            </td>
                            <td><small>{{ $nota->user?->name }} {{ $nota->user?->last_name }}</small></td>
                            <td class="text-center">
                                <a href="{{ route('notes.show', $nota) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@stop

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
    <script>
        $(function () {
            $('#notesTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Todavía no se ha emitido ninguna nota.'
                },
                pageLength: 25,
                order: [[4, 'desc']],
                columnDefs: [
                    // La sucursal se pinta siempre para que los indices de
                    // columna no cambien con el modo de trabajo; DataTables
                    // la esconde cuando no hay varias sedes que distinguir.
                    { visible: {{ $mostrarSucursal ? 'true' : 'false' }}, targets: 0 },
                    { orderable: false, targets: 12 },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@stop
