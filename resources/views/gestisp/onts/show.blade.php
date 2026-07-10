@extends('adminlte::page')
@section('title', 'Detalle ONT')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2>Detalle de ONT — {{ $ont->sn }}</h2>
        <a href="{{ route('onts.authorized') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if($error)
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> {{ $error }}
        </div>
    @endif

    <div class="row">
        {{-- Columna izquierda: datos generales --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-info-circle"></i> Información General
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Serial</th>
                            <td>{{ $ont->sn }}</td>
                        </tr>
                        <tr>
                            <th>OLT</th>
                            <td>{{ $ont->olt->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Ubicación (F/S/P)</th>
                            <td>0/{{ $ont->slot }}/{{ $ont->port }}</td>
                        </tr>
                        <tr>
                            <th>ONT ID</th>
                            <td>{{ $ont->onu_id }}</td>
                        </tr>
                        <tr>
                            <th>Service Port</th>
                            <td>{{ $ont->service_port }}</td>
                        </tr>
                        <tr>
                            <th>VLAN</th>
                            <td>{{ $ont->vlan }}</td>
                        </tr>
                        <tr>
                            <th>Descripción</th>
                            <td>{{ $ont->description }}</td>
                        </tr>
                        <tr>
                            <th>Estado en sistema</th>
                            <td>
                                @if($ont->status)
                                    <span class="badge badge-success">Activa</span>
                                @else
                                    <span class="badge badge-danger">Offline</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            {{-- Cliente / Contrato --}}
            <div class="card">
                <div class="card-header bg-info text-white">
                    <i class="fas fa-user"></i> Cliente y Contrato
                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <tr>
                            <th style="width:40%">Cliente</th>
                            <td>
                                {{ $ont->contract->client->name ?? 'N/A' }}
                                {{ $ont->contract->client->last_name ?? '' }}
                            </td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $ont->contract->client->identity_number ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td>{{ $ont->contract->client->number_phone ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Contrato #</th>
                            <td>{{ $ont->contract_id ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Dirección</th>
                            <td>{{ $ont->contract->address ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Barrio</th>
                            <td>{{ $ont->contract->neighborhood ?? 'N/A' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Columna derecha: datos en tiempo real --}}
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <i class="fas fa-broadcast-tower"></i> Estado en Tiempo Real
                </div>
                <div class="card-body p-0">
                    @if($realtime)
                        <table class="table table-striped mb-0">
                            <tr>
                                <th style="width:40%">Estado operativo</th>
                                <td>
                                    @if(strtolower($realtime['run_state'] ?? '') === 'online')
                                        <span class="badge badge-success">Online</span>
                                    @else
                                        <span class="badge badge-danger">{{ $realtime['run_state'] ?? 'Desconocido' }}</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Potencia Rx (ONT)</th>
                                <td>
                                    @if($realtime['rx_power'] !== null)
                                        <span class="{{ $realtime['rx_power'] < -25 ? 'text-danger font-weight-bold' : 'text-success' }}">
                                            {{ $realtime['rx_power'] }} dBm
                                        </span>
                                        @if($realtime['rx_power'] < -25)
                                            <i class="fas fa-exclamation-triangle text-danger" title="Potencia baja"></i>
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Potencia Tx (ONT)</th>
                                <td>{{ $realtime['tx_power'] !== null ? $realtime['tx_power'] . ' dBm' : '—' }}</td>
                            </tr>
                            <tr>
                                <th>Potencia Rx en OLT</th>
                                <td>{{ $realtime['olt_rx_power'] !== null ? $realtime['olt_rx_power'] . ' dBm' : '—' }}</td>
                            </tr>
                            <tr>
                                <th>Temperatura</th>
                                <td>
                                    @if($realtime['temperature'] !== null)
                                        <span class="{{ $realtime['temperature'] > 50 ? 'text-danger' : '' }}">
                                            {{ $realtime['temperature'] }} °C
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Voltaje</th>
                                <td>{{ $realtime['voltage'] !== null ? $realtime['voltage'] . ' V' : '—' }}</td>
                            </tr>
                            <tr>
                                <th>Corriente</th>
                                <td>{{ $realtime['current'] !== null ? $realtime['current'] . ' mA' : '—' }}</td>
                            </tr>
                            <tr>
                                <th>Distancia</th>
                                <td>{{ $realtime['distance'] !== null ? $realtime['distance'] . ' m' : '—' }}</td>
                            </tr>
                        </table>
                    @else
                        <div class="p-3 text-muted">
                            No hay información en tiempo real disponible.
                        </div>
                    @endif
                </div>
            </div>

            {{-- CATV (solo si la ONT tiene el módulo) --}}
            @if($realtime && $realtime['has_catv'])
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <i class="fas fa-tv"></i> CATV (Televisión)
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <tr>
                                <th style="width:40%">Estado del puerto</th>
                                <td>
                                    @if($realtime['catv_state'] === 'on')
                                        <span class="badge badge-success">Habilitado</span>
                                    @elseif($realtime['catv_state'] === 'off')
                                        <span class="badge badge-danger">Deshabilitado</span>
                                    @else
                                        <span class="badge badge-secondary">Desconocido</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Potencia Rx CATV</th>
                                <td>
                                    @if($realtime['catv_rx_power'] !== null && $realtime['catv_rx_power'] > -35)
                                        <span class="{{ $realtime['catv_rx_power'] < -8 ? 'text-danger' : 'text-success' }}">
                                            {{ $realtime['catv_rx_power'] }} dBm
                                        </span>
                                    @else
                                        <span class="text-muted">Sin señal ({{ $realtime['catv_rx_power'] }} dBm)</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Acciones</th>
                                <td>
                                    @if($realtime['catv_state'] === 'on')
                                        <form method="POST" action="{{ route('onts.catv.disable', $ont) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-toggle-off"></i> Deshabilitar CATV
                                            </button>
                                        </form>
                                    @elseif($realtime['catv_state'] === 'off')
                                        <form method="POST" action="{{ route('onts.catv.enable', $ont) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-toggle-on"></i> Habilitar CATV
                                            </button>
                                        </form>
                                    @else
                                        {{-- Estado desconocido → mostrar ambos --}}
                                        <form method="POST" action="{{ route('onts.catv.enable', $ont) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-toggle-on"></i> Habilitar
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('onts.catv.disable', $ont) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-toggle-off"></i> Deshabilitar
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Historial de conexión --}}
            @if($realtime)
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <i class="fas fa-history"></i> Historial de Conexión
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <tr>
                                <th style="width:40%">Última conexión</th>
                                <td>{{ $realtime['last_up_time'] ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Última desconexión</th>
                                <td>{{ $realtime['last_down_time'] ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th>Causa última caída</th>
                                <td>
                                    @if(($realtime['last_down_cause'] ?? null) === 'dying-gasp')
                                        <span class="badge badge-warning">Corte de energía (dying-gasp)</span>
                                    @elseif(($realtime['last_down_cause'] ?? null) === 'LOSi/LOBi')
                                        <span class="badge badge-danger">Pérdida de señal óptica (LOSi)</span>
                                    @else
                                        {{ $realtime['last_down_cause'] ?? '—' }}
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Tiempo en línea</th>
                                <td>{{ $realtime['online_duration'] ?? '—' }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
