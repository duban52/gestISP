@extends('adminlte::page')
@section('title', $empresa->nombreVisible())

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-building mr-2"></i>{{ $empresa->nombreVisible() }}
            <small class="text-muted">{{ $empresa->identificacion() }}</small>
        </h1>
        <div class="acciones-movil">
            <a href="{{ route('companies.edit', $empresa) }}" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar
            </a>
            <a href="{{ route('companies.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')
    @includeWhen(session('success'), 'gestisp.companies._alerta', ['tipo' => 'success', 'texto' => session('success')])

    <div class="row">
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-file-invoice mr-1"></i> Datos fiscales</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <tr><th style="width:45%">Razón social</th><td>{{ $empresa->legal_name }}</td></tr>
                            <tr><th>Nombre comercial</th><td>{{ $empresa->trade_name ?: '—' }}</td></tr>
                            <tr><th>Identificación</th><td><code>{{ $empresa->identificacion() }}</code></td></tr>
                            <tr><th>Dirección</th><td>{{ $empresa->address ?: '—' }}</td></tr>
                            <tr><th>Correo</th><td>{{ $empresa->email ?: '—' }}</td></tr>
                            <tr><th>Teléfono</th><td>{{ $empresa->phone ?: '—' }}</td></tr>
                            <tr>
                                <th>Modalidad</th>
                                <td>
                                    @if($empresa->esConsolidada())
                                        <span class="badge badge-info">Panel consolidado</span>
                                    @else
                                        <span class="badge badge-secondary">Sucursales independientes</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Facturación electrónica</th>
                                <td>
                                    {{-- Todavía no se activa desde aquí: hace falta el
                                         certificado, la resolución y la habilitación ante
                                         la DIAN. Se muestra el estado, no se toca. --}}
                                    <span class="badge badge-{{ $empresa->electronic_invoicing_enabled ? 'success' : 'secondary' }}">
                                        {{ $empresa->electronic_invoicing_enabled ? 'Activada' : 'No activada' }}
                                    </span>
                                    @unless($empresa->electronic_invoicing_enabled)
                                        <small class="d-block text-muted mt-1">
                                            Requiere certificado digital, resolución y habilitación DIAN.
                                        </small>
                                    @endunless
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-store mr-1"></i> Sucursales
                        <span class="badge badge-secondary ml-1">{{ $total }}</span>
                    </h3>
                    <a href="{{ route('branches.create', ['company' => $empresa->id]) }}"
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Añadir sucursal
                    </a>
                </div>
                <div class="card-body">
                    @if($sucursales->isEmpty())
                        {{-- Sin sucursales nadie puede entrar a esta empresa: el acceso
                             se concede POR SUCURSAL (user_branch), no por empresa. --}}
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            Esta empresa no tiene ninguna sucursal, así que <strong>nadie puede
                            entrar a ella</strong>: el acceso se concede por sucursal.
                        </div>
                    @else
                        @php
                            $sinAcceso = $sucursales->where('users_count', 0);
                        @endphp

                        @if($sinAcceso->isNotEmpty())
                            <div class="alert alert-warning py-2">
                                <i class="fas fa-user-slash"></i>
                                <strong>{{ $sinAcceso->count() }}</strong> sucursal(es) sin
                                ningún usuario asignado: nadie puede entrar a ellas ni
                                editarlas. Asigne usuarios desde
                                <a href="{{ route('users.index') }}">Usuarios</a>.
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>Sucursal</th>
                                    <th>Municipio</th>
                                    <th>Prefijo</th>
                                    <th class="text-center">Con acceso</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($sucursales as $sucursal)
                                    <tr>
                                        <td><strong>{{ $sucursal->name }}</strong></td>
                                        <td>{{ $sucursal->municipality ?: '—' }}</td>
                                        <td><code>{{ $sucursal->contract_prefix ?: '—' }}</code></td>
                                        <td class="text-center">
                                            {{-- El acceso se concede POR SUCURSAL. Una
                                                 sucursal sin usuarios es invisible: no
                                                 sale en los listados, nadie puede
                                                 entrar a ella y no se puede ni
                                                 editar. --}}
                                            @if($sucursal->users_count === 0)
                                                <span class="badge badge-warning" title="Nadie puede entrar a esta sucursal">
                                                    Nadie
                                                </span>
                                            @else
                                                {{ $sucursal->users_count }}
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <a href="{{ route('branches.edit', $sucursal) }}"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($empresa->esConsolidada())
                            <p class="text-muted small mt-2 mb-0">
                                <i class="fas fa-layer-group"></i>
                                En panel consolidado estas sucursales se trabajan desde una sola
                                pantalla, pero cada una conserva sus prefijos y consecutivos.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
