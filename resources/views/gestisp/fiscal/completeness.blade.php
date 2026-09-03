@extends('adminlte::page')
@section('title', 'Completitud fiscal')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-clipboard-check mr-2"></i>Completitud fiscal</h1>
        <div class="acciones-movil">
            @if($todos)
                <a href="{{ route('fiscal.completeness') }}" class="btn btn-outline-secondary">
                    Ver solo los que van a facturar electrónicamente
                </a>
            @else
                <a href="{{ route('fiscal.completeness', ['todos' => 1]) }}" class="btn btn-outline-secondary">
                    Ver todos los clientes
                </a>
            @endif
        </div>
    </div>
@endsection

@section('content')
    <div class="callout callout-info">
        Qué falta para poder emitir <strong>factura electrónica</strong>. Los datos fiscales se
        pueden dejar vacíos al dar de alta —así no estorban a quien solo viene a instalar
        internet—, y esta pantalla dice cuánto queda por completar
        <strong>antes</strong> de que haga falta de verdad.
        @unless($todos)
            <br>
            <small>
                Se están mirando solo los clientes con algún contrato en un grupo de facturación
                electrónica. Los demás no necesitan estos datos.
            </small>
        @endunless
    </div>

    {{-- La empresa va primero y aparte: sin sus datos no se emite NADA,
         por muchos clientes completos que haya. --}}
    <div class="card card-outline {{ $resumen['bloqueante'] ? 'card-danger' : 'card-success' }}">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-building mr-1"></i>
                La empresa que emite: {{ $empresa['nombre'] ?? '—' }}
            </h3>
        </div>
        <div class="card-body">
            @if($empresa['faltan'] === [])
                <p class="mb-0 text-success">
                    <i class="fas fa-check-circle mr-1"></i>
                    Sus datos fiscales están completos.
                </p>
            @else
                <p class="mb-2">
                    <strong>Sin esto no se puede emitir ninguna factura electrónica</strong>,
                    por muchos clientes completos que haya:
                </p>
                <ul class="mb-2">
                    @foreach($empresa['faltan'] as $falta)
                        <li>{{ $falta }}</li>
                    @endforeach
                </ul>
                @can('companies.edit')
                    <a href="{{ route('companies.index') }}" class="btn btn-sm btn-danger">
                        <i class="fas fa-edit mr-1"></i> Completar los datos de la empresa
                    </a>
                @endcan
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="small-box {{ $resumen['clientes']['incompletos'] > 0 ? 'bg-warning' : 'bg-success' }}">
                <div class="inner">
                    <h3>{{ $resumen['clientes']['incompletos'] }}
                        <small>de {{ $resumen['clientes']['total'] }}</small></h3>
                    <p>Clientes con datos fiscales incompletos</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="small-box {{ $resumen['servicios']['incompletos'] > 0 ? 'bg-warning' : 'bg-success' }}">
                <div class="inner">
                    <h3>{{ $resumen['servicios']['incompletos'] }}
                        <small>de {{ $resumen['servicios']['total'] }}</small></h3>
                    <p>Servicios sin código de producto</p>
                </div>
                <div class="icon"><i class="fas fa-tags"></i></div>
            </div>
        </div>
    </div>

    @if($servicios->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags mr-1"></i> Servicios por completar</h3>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Servicio</th>
                        <th>Qué le falta</th>
                        <th class="text-center">Ir</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($servicios as $servicio)
                        <tr>
                            <td class="celda-principal" data-label="">{{ $servicio['nombre'] }}</td>
                            <td data-label="Falta">
                                @foreach($servicio['faltan'] as $falta)
                                    <span class="badge badge-warning mr-1">{{ $falta }}</span>
                                @endforeach
                            </td>
                            <td class="text-center celda-acciones" data-label="">
                                @can('services.edit')
                                    <a href="{{ route('services.edit', $servicio['id']) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-users mr-1"></i> Clientes por completar</h3>
        </div>
        <div class="card-body p-0">
            @if($clientes->isEmpty())
                <p class="p-4 mb-0 text-success">
                    <i class="fas fa-check-circle mr-1"></i>
                    No hay clientes con datos fiscales incompletos.
                </p>
            @else
                {{-- Ordenados por lo que MÁS les falta: se empieza por los
                     peores, que es donde está el trabajo. --}}
                <table class="table table-sm table-hover mb-0 tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Cliente</th>
                        <th>Documento</th>
                        <th>Qué le falta</th>
                        <th class="text-center">Ir</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($clientes as $cliente)
                        <tr>
                            <td class="celda-principal" data-label="">{{ $cliente['nombre'] }}</td>
                            <td data-label="Documento"><code>{{ $cliente['documento'] }}</code></td>
                            <td data-label="Falta">
                                @foreach($cliente['faltan'] as $falta)
                                    <span class="badge badge-warning mr-1">{{ $falta }}</span>
                                @endforeach
                            </td>
                            <td class="text-center celda-acciones" data-label="">
                                @can('clients.edit')
                                    <a href="{{ route('clients.edit', $cliente['id']) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
