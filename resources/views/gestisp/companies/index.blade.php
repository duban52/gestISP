@extends('adminlte::page')
@section('title', 'Empresas')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-building mr-2"></i>Empresas</h1>
        <div class="acciones-movil">
            <a href="{{ route('companies.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nueva empresa
            </a>
        </div>
    </div>
@endsection

@section('content')
    @includeWhen(session('success'), 'gestisp.companies._alerta', ['tipo' => 'success', 'texto' => session('success')])

    <div class="callout callout-info">
        La empresa es el <strong>contribuyente</strong>: un NIT. De ella cuelgan las
        sucursales, y de las sucursales todo lo demás. Aquí vive lo fiscal; en la
        sucursal, lo operativo.
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover tabla-movil">
                    <thead class="thead-light">
                    <tr>
                        <th>Empresa</th>
                        <th>Identificación</th>
                        <th>Modalidad</th>
                        <th class="text-center">Sucursales</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($empresas as $empresa)
                        <tr>
                            <td class="celda-principal" data-label="">
                                <a href="{{ route('companies.show', $empresa) }}" class="font-weight-bold">
                                    {{ $empresa->nombreVisible() }}
                                </a>
                                @if($empresa->trade_name)
                                    <small class="d-block text-muted">{{ $empresa->legal_name }}</small>
                                @endif
                            </td>
                            <td data-label="Identificación"><code>{{ $empresa->identificacion() }}</code></td>
                            <td data-label="Modalidad">
                                @if($empresa->esConsolidada())
                                    <span class="badge badge-info">Panel consolidado</span>
                                @else
                                    <span class="badge badge-secondary">Sucursales independientes</span>
                                @endif
                            </td>
                            <td class="text-center" data-label="Sucursales">
                                {{-- Una empresa sin sucursales no puede operar: nadie
                                     puede entrar a ella. Se avisa aquí. --}}
                                @if($empresa->branches_count === 0)
                                    <span class="badge badge-warning">Sin sucursales</span>
                                @else
                                    {{ $empresa->branches_count }}
                                @endif
                            </td>
                            <td class="text-center" data-label="Estado">
                                <span class="badge badge-{{ $empresa->active ? 'success' : 'secondary' }}">
                                    {{ $empresa->active ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                            <td class="text-center celda-acciones" data-label="">
                                <a href="{{ route('companies.show', $empresa) }}" class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-eye"></i><span class="d-md-none ml-1">Ver</span>
                                </a>
                                <a href="{{ route('companies.edit', $empresa) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-edit"></i><span class="d-md-none ml-1">Editar</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">
                            No hay ninguna empresa registrada.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
