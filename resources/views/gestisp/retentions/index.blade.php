@extends('adminlte::page')

@section('title', 'Retenciones')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-percent mr-2"></i>Retenciones practicadas</h1>
@stop

@section('content')

    {{-- ============================================================
         Explicación en pantalla: la mayoría de los cajeros no tiene
         por qué saber qué es una retención, y quien consulta este
         reporte necesita entender qué está viendo.
         ============================================================ --}}
    <div class="callout callout-info">
        <p class="mb-1">
            Cuando el cliente es <strong>agente de retención</strong>, la ley lo obliga a
            descontar un porcentaje del pago y consignarlo directamente a la DIAN o al
            municipio <strong>a nombre nuestro</strong>. No es un descuento: la factura queda
            pagada completa, pero parte del dinero no entró a la caja.
        </p>
        <p class="mb-0">
            Ese dinero es un <strong>anticipo de nuestros impuestos</strong> y se descuenta al
            declarar. Este listado —con su base, su tarifa y el número de certificado— es el
            soporte para hacerlo.
        </p>
    </div>

    {{-- ---------- Totales por tipo ---------- --}}
    <div class="row">
        <div class="col-md-3 col-6">
            <div class="small-box bg-gradient-primary">
                <div class="inner">
                    <h3 style="font-size: 1.7rem;">${{ number_format($total, 0, ',', '.') }}</h3>
                    <p class="mb-0">Total retenido en el período</p>
                </div>
                <div class="icon"><i class="fas fa-percent"></i></div>
            </div>
        </div>

        @foreach($totalesPorTipo as $tipo => $datos)
            <div class="col-md-3 col-6">
                <div class="info-box shadow-sm">
                    <span class="info-box-icon bg-light"><i class="fas fa-file-invoice-dollar"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text" style="font-size: .8rem;">{{ $datos['label'] }}</span>
                        <span class="info-box-number">${{ number_format($datos['total'], 0, ',', '.') }}</span>
                        <span class="text-muted" style="font-size: .75rem;">{{ $datos['count'] }} registro(s)</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ---------- Filtros ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <form method="GET" action="{{ route('retentions.index') }}">
                <div class="row align-items-end">
                    <div class="col-md-2">
                        <label class="mb-1">Desde</label>
                        <input type="date" name="from" class="form-control" value="{{ $desde }}">
                    </div>
                    <div class="col-md-2">
                        <label class="mb-1">Hasta</label>
                        <input type="date" name="to" class="form-control" value="{{ $hasta }}">
                    </div>
                    <div class="col-md-3">
                        <label class="mb-1">Tipo</label>
                        <select name="type" class="form-control">
                            <option value="">Todos</option>
                            @foreach($tipos as $valor => $etiqueta)
                                <option value="{{ $valor }}" @selected(request('type') === $valor)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="mb-1">Cliente, contrato o certificado</label>
                        <input type="text" name="search" class="form-control"
                               placeholder="Cédula, nombre, ENG000123, n.º certificado"
                               value="{{ request('search') }}">
                    </div>
                    <div class="col-md-2 mt-2 mt-md-0">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="card-body">
            <div class="mb-3">
                {{-- Las descargas conservan los filtros: lo que se
                     exporta es exactamente lo que se está viendo. --}}
                <a href="{{ route('retentions.export', request()->query()) }}" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Excel
                </a>
                <a href="{{ route('retentions.pdf', request()->query()) }}" class="btn btn-danger btn-sm">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
            </div>

            <div class="table-responsive">
                <table id="retentionsTable" class="table table-hover table-sm w-100">
                    <thead class="thead-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Tipo</th>
                        <th>Concepto</th>
                        <th>N.º contrato</th>
                        <th>Cliente</th>
                        <th>Factura</th>
                        <th class="text-right">Base</th>
                        <th class="text-right">Tarifa</th>
                        <th class="text-right">Retenido</th>
                        <th>Certificado</th>
                        <th class="text-center">Recibo</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($retenciones as $retencion)
                        <tr>
                            <td data-order="{{ $retencion->created_at?->timestamp }}">
                                {{ $retencion->created_at?->format('d/m/Y') }}
                            </td>
                            <td><span class="badge badge-info">{{ $retencion->tipo_corto }}</span></td>
                            <td><small>{{ $retencion->concept_label ?? $retencion->concept_code }}</small></td>
                            <td>{{ $retencion->contract?->numero_visible ?? '—' }}</td>
                            <td>
                                {{ trim(($retencion->contract?->client?->name ?? '') . ' ' . ($retencion->contract?->client?->last_name ?? '')) ?: '—' }}
                                <small class="d-block text-muted">{{ $retencion->contract?->client?->identity_number }}</small>
                            </td>
                            <td>{{ $retencion->invoice?->displayNumber() ?? '—' }}</td>
                            <td class="text-right">${{ number_format((float) $retencion->base, 0, ',', '.') }}</td>
                            <td class="text-right">{{ rtrim(rtrim(number_format((float) $retencion->rate, 3, ',', '.'), '0'), ',') }}%</td>
                            <td class="text-right">
                                <strong>${{ number_format((float) $retencion->amount, 0, ',', '.') }}</strong>
                            </td>
                            <td>
                                @if($retencion->certificate_number)
                                    {{ $retencion->certificate_number }}
                                @else
                                    {{-- Sin certificado no se puede descontar el
                                         impuesto: hay que reclamárselo al cliente. --}}
                                    <span class="badge badge-warning" title="Sin certificado no se puede descontar el impuesto">
                                        Pendiente
                                    </span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($retencion->payment_id)
                                    <a href="{{ route('payments.receipt', $retencion->payment_id) }}"
                                       target="_blank" class="btn btn-sm btn-outline-secondary" title="Ver recibo">
                                        <i class="fas fa-receipt"></i>
                                    </a>
                                @else
                                    —
                                @endif
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
            $('#retentionsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No se practicaron retenciones en este período.'
                },
                pageLength: 25,
                order: [[0, 'desc']],
                columnDefs: [
                    { orderable: false, targets: 10 },
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });
    </script>
@stop
