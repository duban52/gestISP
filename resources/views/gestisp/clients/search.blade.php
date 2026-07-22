@extends('adminlte::page')

@section('title', 'Búsqueda de clientes')

@section('content_header')
    <div class="card p-3 mb-0">
        <h2 class="mb-0"><i class="fas fa-user-friends mr-2 text-info"></i>BÚSQUEDA DE CLIENTES</h2>
    </div>
@endsection

@section('content')

    {{-- ================= CUADRO DE BÚSQUEDA ================= --}}
    <div class="card card-info card-outline">
        <div class="card-body">
            <form method="GET" action="{{ route('clients.searchView') }}">
                <label class="text-muted small mb-1">
                    Busque por documento, nombre, apellido, teléfono, correo o tipo de cliente
                </label>
                <div class="input-group input-group-lg">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-info"></i></span>
                    </div>
                    <input type="text" name="q" class="form-control" autofocus
                           value="{{ $q }}"
                           placeholder="Ej.: 1094... , Juan Pérez, 311 555 4433, correo@...">
                    <div class="input-group-append">
                        <button class="btn btn-info px-4" type="submit">Buscar</button>
                        @if($q !== '')
                            <a href="{{ route('clients.searchView') }}" class="btn btn-outline-secondary" title="Limpiar">
                                <i class="fas fa-times"></i>
                            </a>
                        @endif
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ================= RESULTADOS ================= --}}
    @isset($clients)
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">
                    <i class="fas fa-list mr-1"></i> Resultados
                    <span class="badge badge-info ml-1">{{ $clients->total() }}</span>
                </h3>
                <span class="text-muted small">Búsqueda: "{{ $q }}"</span>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0 align-middle">
                        <thead class="thead-light">
                        <tr>
                            <th>Documento</th>
                            <th>Cliente</th>
                            <th>Tipo</th>
                            <th>Contacto</th>
                            <th class="text-center">Contratos</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($clients as $client)
                            <tr>
                                <td class="text-nowrap"><strong>{{ $client->identity_number }}</strong></td>
                                <td>
                                    {{ $client->name }} {{ $client->last_name }}
                                    @if($client->user)
                                        <br><span class="text-muted small">Registró: {{ $client->user->name }}</span>
                                    @endif
                                </td>
                                <td><span class="badge badge-secondary">{{ $client->type_client ?: '—' }}</span></td>
                                <td class="small">
                                    @if($client->number_phone)
                                        <div><i class="fas fa-phone-alt text-muted mr-1"></i>{{ $client->number_phone }}</div>
                                    @endif
                                    @if($client->email)
                                        <div><i class="fas fa-envelope text-muted mr-1"></i>{{ $client->email }}</div>
                                    @endif
                                    @unless($client->number_phone || $client->email)
                                        <span class="text-muted">Sin contacto</span>
                                    @endunless
                                </td>
                                <td class="text-center">
                                    <span class="badge {{ $client->contracts_count > 0 ? 'badge-success' : 'badge-light border' }}">
                                        {{ $client->contracts_count }}
                                    </span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <a href="{{ route('clients.edit', $client) }}"
                                       class="btn btn-sm btn-outline-primary" title="Editar cliente">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="{{ route('contracts.create', $client) }}"
                                       class="btn btn-sm btn-success" title="Asignar contrato">
                                        <i class="fas fa-file-signature"></i> Contrato
                                    </a>
                                    <button type="button" class="btn btn-sm btn-outline-info btn-ver-contratos"
                                            data-toggle="collapse" data-target="#contratos-{{ $client->id }}"
                                            title="Ver contratos" {{ $client->contracts_count === 0 ? 'disabled' : '' }}>
                                        <i class="fas fa-file-contract"></i>
                                    </button>
                                </td>
                            </tr>

                            {{-- Fila desplegable con los contratos del cliente --}}
                            @if($client->contracts_count > 0)
                                <tr class="collapse bg-light" id="contratos-{{ $client->id }}">
                                    <td colspan="6" class="p-0">
                                        <table class="table table-sm mb-0">
                                            <thead>
                                            <tr class="text-muted small">
                                                <th class="pl-4">Contrato</th>
                                                <th>Barrio</th>
                                                <th>Dirección</th>
                                                <th>Estado</th>
                                                <th class="text-right pr-4">Ver</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($client->contracts as $contract)
                                                <tr>
                                                    <td class="pl-4">#{{ $contract->id }}</td>
                                                    <td>{{ $contract->neighborhood ?: '—' }}</td>
                                                    <td>{{ $contract->address ?: '—' }}</td>
                                                    <td><span class="badge badge-secondary">{{ $contract->status }}</span></td>
                                                    <td class="text-right pr-4">
                                                        <a href="{{ route('contracts.show', $contract) }}"
                                                           class="btn btn-xs btn-info">Detalles</a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fas fa-user-slash fa-2x d-block mb-2"></i>
                                    No se encontró ningún cliente para "<strong>{{ $q }}</strong>" en esta sucursal.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($clients->hasPages())
                <div class="card-footer py-2">
                    {{ $clients->links() }}
                </div>
            @endif
        </div>
    @else
        {{-- Estado inicial, sin búsqueda todavía --}}
        <div class="text-center text-muted py-5">
            <i class="fas fa-search fa-3x mb-3 d-block"></i>
            Escriba en el buscador para encontrar un cliente y asignarle un contrato.
        </div>
    @endisset
@endsection
