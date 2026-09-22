{{-- ============================================================
     Cortes masivos por mora

     Se corta en el sistema (el contrato queda «Suspendido», con una
     orden administrativa en su historial) y en la red (cuenta PPPoE y
     ONT deshabilitadas). Solo a quien de verdad debe: la revisión lo
     comprueba antes de confirmar. Ver App\Services\ContractMassCutoff.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cortes masivos')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-cut mr-2"></i>Cortes masivos por mora</h1>
@endsection

@section('content')
    @include('gestisp.partials.resultado-accion')

    <div class="callout callout-warning">
        <p class="mb-1">
            Cortar es <strong>suspender el contrato en el sistema</strong> y <strong>deshabilitar su cuenta PPPoE y su
            ONT</strong>. Cada contrato cortado queda con una orden administrativa en su historial.
        </p>
        <p class="mb-0">
            Solo se corta a quien debe
            @if($umbral)
                <strong>{{ $umbral }} o más facturas vencidas</strong> (la regla de suspensión de la sucursal).
            @else
                las facturas vencidas que exige la regla de suspensión de su sucursal.
            @endif
            Lo demás se descarta y se dice por qué. Máximo {{ number_format($maximo, 0, ',', '.') }} contratos por tanda.
        </p>
    </div>

    <div class="card card-outline card-primary">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list-ol mr-1"></i> Números de contrato a cortar</h3>
        </div>
        <form method="POST" action="{{ route('technicals_orders.cutoffs.preview') }}" enctype="multipart/form-data"
              data-procesando="Revisando la lista...">
            @csrf
            <div class="card-body">
                @if($hayQueElegirSucursal)
                    <div class="form-group">
                        <label for="branch_id">Sucursal <span class="text-danger">*</span></label>
                        <select name="branch_id" id="branch_id" class="form-control" required>
                            <option value="">Seleccione…</option>
                            @foreach($sucursales as $sucursal)
                                <option value="{{ $sucursal->id }}" @selected((string) old('branch_id') === (string) $sucursal->id)>
                                    {{ $sucursal->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <ul class="nav nav-tabs mb-3" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#tabPegar" role="tab">
                            <i class="fas fa-keyboard mr-1"></i> Escribir o pegar
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-toggle="tab" href="#tabArchivo" role="tab">
                            <i class="fas fa-file-upload mr-1"></i> Subir archivo
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabPegar" role="tabpanel">
                        <label for="lista">Un número de contrato por línea</label>
                        <textarea name="lista" id="lista" class="form-control text-monospace" rows="10"
                                  placeholder="ENG000123&#10;ENG000456&#10;...">{{ old('lista') }}</textarea>
                        <small class="form-text text-muted">
                            También separados por comas o punto y coma. Los repetidos se descartan solos.
                        </small>
                    </div>
                    <div class="tab-pane fade" id="tabArchivo" role="tabpanel">
                        <label for="archivo">Archivo con la lista</label>
                        <input type="file" name="archivo" id="archivo" class="form-control-file" accept=".txt,.csv,.xlsx,.xls">
                        <small class="form-text text-muted">
                            <code>.txt</code>, <code>.csv</code>, <code>.xlsx</code> o <code>.xls</code>, hasta 5 MB. Si la
                            primera fila tiene un encabezado «Contrato» se usa esa columna; si no, la primera columna.
                            Si sube un archivo, manda el archivo aunque también haya escrito una lista.
                        </small>
                    </div>
                </div>
            </div>
            <div class="card-footer text-right">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search mr-1"></i> Revisar la lista
                </button>
            </div>
        </form>
    </div>

    {{-- El historial es el reporte: cada tanda queda guardada con el
         resultado de cada contrato, y se descarga en Excel o PDF. --}}
    <div class="card" id="historial">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history mr-1"></i> Historial de cortes</h3>
        </div>
        <div class="card-body border-bottom">
            <form method="GET" action="{{ route('technicals_orders.cutoffs') }}#historial" class="form-inline">
                <label for="desde" class="mr-2">Desde</label>
                <input type="date" name="desde" id="desde" class="form-control form-control-sm mr-3" value="{{ $desde }}">
                <label for="hasta" class="mr-2">Hasta</label>
                <input type="date" name="hasta" id="hasta" class="form-control form-control-sm mr-3" value="{{ $hasta }}">
                <button type="submit" class="btn btn-sm btn-primary mr-2"><i class="fas fa-filter"></i> Filtrar</button>
                @if($desde || $hasta)
                    <a href="{{ route('technicals_orders.cutoffs') }}#historial" class="btn btn-sm btn-link mr-2">Quitar filtro</a>
                @endif
                <a href="{{ route('technicals_orders.cutoffs.export', array_filter(['desde' => $desde, 'hasta' => $hasta])) }}"
                   class="btn btn-sm btn-outline-success ml-auto">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </a>
            </form>
            <small class="text-muted">El Excel trae cada contrato de cada tanda del periodo, también los que no se cortaron y por qué.</small>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>Quién</th>
                        <th>Origen</th>
                        <th>Motivo</th>
                        <th class="text-center">En la lista</th>
                        <th class="text-center">Cortados</th>
                        <th class="text-center">En cola</th>
                        <th class="text-center">Con problemas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tandas as $tanda)
                        <tr>
                            <td>{{ $tanda->id }}</td>
                            <td>{{ $tanda->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $tanda->user?->name ?? '—' }}</td>
                            <td>{{ $tanda->source }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($tanda->reason, 60) }}</td>
                            <td class="text-center">{{ $tanda->items_count }}</td>
                            <td class="text-center text-success">{{ $tanda->cortados_count }}</td>
                            <td class="text-center text-info">{{ $tanda->pendientes_count }}</td>
                            <td class="text-center {{ $tanda->con_problemas_count ? 'text-danger font-weight-bold' : '' }}">
                                {{ $tanda->con_problemas_count }}
                            </td>
                            <td class="text-right text-nowrap">
                                <a href="{{ route('technicals_orders.cutoffs.show', $tanda) }}" class="btn btn-xs btn-outline-primary">Ver</a>
                                <a href="{{ route('technicals_orders.cutoffs.excel', $tanda) }}" class="btn btn-xs btn-outline-success" title="Excel">
                                    <i class="fas fa-file-excel"></i>
                                </a>
                                <a href="{{ route('technicals_orders.cutoffs.pdf', $tanda) }}" class="btn btn-xs btn-outline-danger" title="PDF" target="_blank" rel="noopener">
                                    <i class="fas fa-file-pdf"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-3">
                            {{ $desde || $hasta ? 'No hay cortes en ese periodo.' : 'Todavía no se ha hecho ningún corte masivo.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($tandas->hasPages())
            <div class="card-footer">{{ $tandas->fragment('historial')->links() }}</div>
        @endif
    </div>
@endsection
