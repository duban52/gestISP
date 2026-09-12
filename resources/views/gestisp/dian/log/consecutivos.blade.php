@extends('adminlte::page')

@section('title', 'Consecutivos autorizados')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">
            <i class="fas fa-list-ol mr-2"></i>Consecutivos autorizados
        </h1>
        <a href="{{ route('dian.log.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left mr-1"></i>Volver al log
        </a>
    </div>
@stop

@section('content')

    {{-- ============================================================
         PARA QUÉ SIRVE ESTA PANTALLA.

         La DIAN autoriza un rango de numeración y espera poder pedir
         cuenta de cada número: qué documento salió con él, o por qué no
         salió ninguno. Hasta ahora eso solo se podía averiguar
         consultando la base a mano.

         La pregunta va al revés que en el log: allí se parte de los
         documentos que existen; aquí se parte de los NÚMEROS, y lo
         interesante son justo los que no tienen documento detrás.
         ============================================================ --}}
    <div class="callout callout-info">
        Cada consecutivo del rango autorizado debería tener detrás una factura aceptada.
        Los que no la tienen —porque se rechazó, se anuló, o no quedó documento— son los
        que habría que justificar si la DIAN los pregunta.
    </div>

    @forelse($rangos as $informe)
        <div class="card card-outline {{ $informe['problemas']->isEmpty() ? 'card-success' : 'card-warning' }} shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h3 class="card-title mb-0">
                    <i class="fas fa-hashtag mr-1"></i>
                    Prefijo <strong>{{ $informe['prefijo'] ?: '(sin prefijo)' }}</strong>
                    @if($informe['resolucion'])
                        <small class="text-muted ml-2">Resolución {{ $informe['resolucion'] }}</small>
                    @endif
                </h3>

                @if($informe['problemas']->isEmpty())
                    <span class="badge badge-success">Sin huecos que justificar</span>
                @else
                    <span class="badge badge-warning">
                        {{ $informe['problemas']->count() }}
                        {{ $informe['problemas']->count() === 1 ? 'consecutivo' : 'consecutivos' }} por justificar
                    </span>
                @endif
            </div>

            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Autorizado</span>
                        <strong>{{ number_format($informe['autorizado_desde']) }}</strong>
                        &ndash;
                        <strong>{{ number_format($informe['autorizado_hasta']) }}</strong>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Consecutivo actual</span>
                        <strong>{{ number_format($informe['consecutivo_actual']) }}</strong>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Usados</span>
                        <strong>{{ number_format($informe['usados']) }}</strong>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted d-block small">Restantes</span>
                        <strong class="{{ $informe['por_agotarse'] ? 'text-danger' : '' }}">
                            {{ number_format($informe['restantes']) }}
                        </strong>
                        @if($informe['por_agotarse'])
                            <i class="fas fa-exclamation-triangle text-danger ml-1"
                               title="El rango está por agotarse. Un rango agotado detiene la emisión."></i>
                        @endif
                    </div>
                </div>

                @if($informe['vigencia'])
                    <p class="text-muted small mt-2 mb-0">
                        Vigente del {{ optional($informe['vigencia']->valid_from)->format('d/m/Y') }}
                        al {{ optional($informe['vigencia']->valid_until)->format('d/m/Y') }}.
                    </p>
                @endif

                @if($informe['truncado'])
                    {{-- Decirlo, en vez de presentar un informe recortado
                         como si fuera completo. --}}
                    <div class="alert alert-warning py-2 px-3 small mt-2 mb-0">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Son demasiados consecutivos para revisarlos todos aquí. Se muestran los
                        últimos, desde el {{ number_format($informe['examinados_desde']) }}.
                    </div>
                @endif
            </div>

            @if($informe['usados'] === 0)
                <div class="card-body pt-0">
                    <p class="text-muted mb-0">Este rango todavía no ha emitido ninguna factura.</p>
                </div>
            @else
                {{-- El resumen primero: es lo que se mira de un vistazo. --}}
                <div class="card-body pt-0">
                    @foreach($informe['conteos'] as $estado => $cuantos)
                        @continue($cuantos === 0)
                        <span class="badge badge-{{ match($estado) {
                            \App\Billing\Reports\ConsecutiveAudit::OK => 'success',
                            \App\Billing\Reports\ConsecutiveAudit::PENDIENTE => 'secondary',
                            \App\Billing\Reports\ConsecutiveAudit::RECHAZADO => 'danger',
                            \App\Billing\Reports\ConsecutiveAudit::ANULADO => 'dark',
                            default => 'warning',
                        } }} mr-1">
                            {{ $etiquetas[$estado] }}: {{ $cuantos }}
                        </span>
                    @endforeach
                </div>

                {{-- ====================================================
                     Solo los que hay que justificar.

                     El detalle completo puede ser de miles de números y
                     no aporta: lo que importa son las excepciones. Los
                     aceptados ya están en el log.
                     ==================================================== --}}
                @if($informe['problemas']->isNotEmpty())
                    <div class="card-body table-responsive p-0">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>Consecutivo</th>
                                <th>Qué pasó</th>
                                <th>Cliente</th>
                                <th>Emitida</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($informe['problemas'] as $fila)
                                <tr>
                                    <td><strong>{{ $fila['completo'] }}</strong></td>
                                    <td>
                                        <span class="badge badge-{{ match($fila['estado']) {
                                            \App\Billing\Reports\ConsecutiveAudit::RECHAZADO => 'danger',
                                            \App\Billing\Reports\ConsecutiveAudit::ANULADO => 'dark',
                                            default => 'warning',
                                        } }}">
                                            {{ $etiquetas[$fila['estado']] }}
                                        </span>

                                        @if($fila['estado'] === \App\Billing\Reports\ConsecutiveAudit::SIN_DOCUMENTO)
                                            <small class="d-block text-muted">
                                                El número se reservó y no quedó factura. Revise si una
                                                emisión se revirtió después de avanzar el contador.
                                            </small>
                                        @elseif($fila['estado'] === \App\Billing\Reports\ConsecutiveAudit::ANULADO && $fila['factura']?->void_reason)
                                            <small class="d-block text-muted">{{ $fila['factura']->void_reason }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $fila['factura']?->contract?->client?->fullName() ?? '—' }}</td>
                                    <td>{{ optional($fila['factura']?->issue_date)->format('d/m/Y') ?? '—' }}</td>
                                    <td class="text-right">
                                        @if($fila['factura'])
                                            <a href="{{ route('invoices.show', $fila['factura']) }}"
                                               class="btn btn-xs btn-outline-primary">
                                                Ver factura
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center text-muted py-4">
                No hay rangos de numeración autorizados en esta sucursal.
                Regístrelos con la resolución de la DIAN antes de emitir.
            </div>
        </div>
    @endforelse
@stop
