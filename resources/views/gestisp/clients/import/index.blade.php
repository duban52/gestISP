@extends('adminlte::page')

@section('title', 'Importar clientes y contratos')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-file-import mr-2"></i>Importar clientes y contratos</h1>
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

    @if($errors->any())
        <div class="alert alert-danger shadow-sm">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    {{-- Resultado de la última importación --}}
    @if(session('resultadoImportacion'))
        @php
            $r = session('resultadoImportacion');
        @endphp
        <div class="card card-outline card-success shadow-sm">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clipboard-check mr-1"></i> Resultado de la importación</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-sm-3">
                        <div class="info-box mb-2">
                            <span class="info-box-icon bg-success"><i class="fas fa-file-signature"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Contratos creados</span>
                                <span class="info-box-number">{{ $r['creados'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="info-box mb-2">
                            <span class="info-box-icon bg-info"><i class="fas fa-user-plus"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Clientes nuevos</span>
                                <span class="info-box-number">{{ $r['clientes_nuevos'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="info-box mb-2">
                            <span class="info-box-icon bg-warning"><i class="fas fa-hand-holding-usd"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Con saldo migrado</span>
                                <span class="info-box-number">{{ $r['con_saldo'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="info-box mb-2">
                            <span class="info-box-icon bg-danger"><i class="fas fa-dollar-sign"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Saldo total migrado</span>
                                <span class="info-box-number">${{ number_format($r['saldo_total'], 2) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                @if(!empty($r['errores']))
                    <h5 class="mt-2"><i class="fas fa-exclamation-circle text-danger mr-1"></i>
                        Filas que no se importaron ({{ count($r['errores']) }})</h5>
                    <div class="table-responsive" style="max-height: 320px; overflow:auto;">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="thead-light">
                            <tr><th style="width:90px;">Línea</th><th>Motivo</th></tr>
                            </thead>
                            <tbody>
                            @foreach($r['errores'] as $error)
                                <tr>
                                    <td>{{ $error['linea'] }}</td>
                                    <td>{{ implode(' · ', $error['motivos']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row">
        {{-- ---------- Carga del archivo ---------- --}}
        <div class="col-lg-5">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-upload mr-1"></i> Subir archivo</h3>
                </div>
                <form action="{{ route('clients.import.preview') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="card-body">
                        <div class="form-group">
                            <label for="archivo">Archivo de clientes y contratos</label>
                            <div class="custom-file">
                                <input type="file" name="archivo" id="archivo"
                                       class="custom-file-input @error('archivo') is-invalid @enderror"
                                       accept=".csv,.txt,.xlsx,.xls" required>
                                <label class="custom-file-label" for="archivo" id="archivo-label">
                                    Elegir archivo...
                                </label>
                            </div>
                            <small class="form-text text-muted">
                                Formatos aceptados: CSV, XLSX o XLS. Máximo 10 MB.
                            </small>
                        </div>

                        <div class="callout callout-info py-2">
                            <p class="mb-0">
                                Nada se guarda todavía: al continuar verá <strong>qué se va a crear</strong>
                                y qué filas tienen problemas, y decidirá si confirma.
                            </p>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <a href="{{ route('clients.import.template') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-download mr-1"></i> Descargar plantilla
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-eye mr-1"></i> Revisar archivo
                        </button>
                    </div>
                </form>
            </div>

            {{-- Qué pasa con los saldos --}}
            <div class="card card-outline card-warning shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-hand-holding-usd mr-1"></i> Cómo se tratan los saldos</h3>
                </div>
                <div class="card-body">
                    <p>Si el archivo trae la columna de <strong>saldo pendiente</strong>, por cada contrato con saldo el sistema:</p>
                    <ol class="pl-3 mb-2">
                        <li>Crea una factura de tipo <strong>«Saldo migrado»</strong> por ese valor, marcada como vencida.</li>
                        <li>Deja un <strong>comentario en el contrato</strong> explicando de dónde viene ese saldo.</li>
                    </ol>
                    <p class="mb-0 text-muted">
                        Así la deuda entra al circuito normal: aparece en el estado de cuenta, suma en lo que
                        el cliente debe y se cobra con el mismo flujo de pagos de siempre. No se mezcla con
                        las mensualidades: la facturación del mes se sigue generando aparte, con normalidad.
                    </p>
                </div>
            </div>
        </div>

        {{-- ---------- Columnas admitidas ---------- --}}
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-table mr-1"></i> Columnas que reconoce el sistema</h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 620px; overflow:auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Dato</th>
                                <th>Títulos que acepta en el archivo</th>
                                <th style="width: 105px;">Va a</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($campos as $campo)
                                <tr>
                                    <td>
                                        {{ $campo['campo'] }}
                                        @if($campo['obligatorio'])
                                            <span class="badge badge-danger ml-1">Obligatorio</span>
                                        @endif
                                    </td>
                                    <td><small class="text-muted">{{ $campo['titulos'] }}</small></td>
                                    <td>
                                        <span class="badge badge-light border">{{ ucfirst($campo['destino']) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-muted">
                    <small>
                        Los títulos no distinguen mayúsculas, tildes ni espacios: «Número de Documento»
                        y «numero_de_documento» se reconocen igual. Las columnas que el sistema no
                        reconozca simplemente se ignoran.
                    </small>
                </div>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        document.getElementById('archivo').addEventListener('change', function () {
            document.getElementById('archivo-label').textContent =
                this.files.length ? this.files[0].name : 'Elegir archivo...';
        });
    </script>
@stop
