@extends('adminlte::page')

@section('title', 'Revisión de la importación')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-search mr-2"></i>Revisión previa</h1>
        <a href="{{ route('clients.import.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
@stop

@section('content')
    <div class="alert alert-info shadow-sm">
        <i class="fas fa-info-circle mr-1"></i>
        Archivo <strong>{{ $nombreArchivo }}</strong> analizado.
        <strong>Todavía no se ha guardado nada.</strong>
    </div>

    {{-- ---------- Resumen ---------- --}}
    <div class="row">
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon bg-primary"><i class="fas fa-list"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Filas</span>
                    <span class="info-box-number">{{ $resumen['total'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon bg-success"><i class="fas fa-user-plus"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Clientes nuevos</span>
                    <span class="info-box-number">{{ $resumen['nuevos'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon bg-info"><i class="fas fa-user-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Ya existentes</span>
                    <span class="info-box-number">{{ $resumen['existentes'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Con saldo</span>
                    <span class="info-box-number">{{ $resumen['con_saldo'] }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon bg-danger"><i class="fas fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Saldo total</span>
                    <span class="info-box-number" style="font-size:1rem;">${{ number_format($resumen['saldo_total'], 0) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-4">
            <div class="info-box">
                <span class="info-box-icon {{ $resumen['errores'] ? 'bg-danger' : 'bg-secondary' }}">
                    <i class="fas fa-exclamation-triangle"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">Con problemas</span>
                    <span class="info-box-number">{{ $resumen['errores'] }}</span>
                </div>
            </div>
        </div>
    </div>

    @if($resumen['errores'] > 0)
        <div class="alert alert-warning shadow-sm">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            Hay <strong>{{ $resumen['errores'] }}</strong> fila(s) con problemas. Si continúa,
            <strong>esas filas se omiten</strong> y el resto sí se importa. También puede corregir
            el archivo y volver a subirlo.
        </div>
    @endif

    {{-- ---------- Muestra ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-table mr-1"></i>
                Primeras {{ count($filas) }} filas de {{ $resumen['total'] }}
            </h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th style="width:60px;">Línea</th>
                        <th>Cliente</th>
                        <th>Documento</th>
                        <th>N.º contrato</th>
                        <th>Plan</th>
                        <th class="text-right">Saldo</th>
                        <th>Situación</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($filas as $fila)
                        <tr @class(['table-danger' => !empty($fila['errores'])])>
                            <td>{{ $fila['linea'] }}</td>
                            <td>{{ trim(($fila['cliente']['name'] ?? '') . ' ' . ($fila['cliente']['last_name'] ?? '')) ?: '—' }}</td>
                            <td>{{ $fila['cliente']['identity_number'] ?? '—' }}</td>
                            <td>
                                @if(!empty($fila['contrato']['contract_number']))
                                    <strong>{{ $fila['contrato']['contract_number'] }}</strong>
                                    <small class="d-block text-muted">del sistema anterior</small>
                                @else
                                    <span class="text-muted">se asigna automático</span>
                                @endif
                            </td>
                            <td>{{ $fila['plan'] ?? '—' }}</td>
                            <td class="text-right">
                                @if($fila['saldo'] > 0)
                                    <strong>${{ number_format($fila['saldo'], 2) }}</strong>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($fila['errores']))
                                    @foreach($fila['errores'] as $error)
                                        <span class="badge badge-danger d-block mb-1">{{ $error }}</span>
                                    @endforeach
                                @elseif($fila['cliente_existente'])
                                    <span class="badge badge-info">Cliente ya existe: se le agrega el contrato</span>
                                @else
                                    <span class="badge badge-success">Cliente y contrato nuevos</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ---------- Confirmación ---------- --}}
    <div class="card card-outline card-success shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-check-circle mr-1"></i> Confirmar importación</h3>
        </div>
        <form action="{{ route('clients.import.store') }}" method="POST"
              onsubmit="return confirm('¿Importar definitivamente? Esta acción crea los clientes, los contratos y las facturas de saldo migrado.');">
            @csrf
            <input type="hidden" name="ruta" value="{{ $ruta }}">
            <div class="card-body">
                @if($resumen['con_saldo'] > 0)
                    <div class="form-group">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="crear_saldos"
                                   name="crear_saldos" value="1" checked>
                            <label class="custom-control-label" for="crear_saldos">
                                Registrar los saldos pendientes como facturas de «Saldo migrado»
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            Recomendado. Se crearán {{ $resumen['con_saldo'] }} factura(s) por un total de
                            <strong>${{ number_format($resumen['saldo_total'], 2) }}</strong>, cada una con su
                            comentario en el contrato. Si lo desactiva, los contratos entran sin deuda y
                            <strong>ese dinero no quedará registrado</strong>.
                        </small>
                    </div>
                @endif

                <p class="mb-0">
                    Se importarán <strong>{{ $resumen['total'] - $resumen['errores'] }}</strong> fila(s)
                    @if($resumen['errores'] > 0)
                        y se omitirán <strong>{{ $resumen['errores'] }}</strong> con problemas
                    @endif
                    en la sucursal activa.
                </p>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a href="{{ route('clients.import.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times mr-1"></i> Cancelar
                </a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-file-import mr-1"></i> Importar definitivamente
                </button>
            </div>
        </form>
    </div>
@stop
