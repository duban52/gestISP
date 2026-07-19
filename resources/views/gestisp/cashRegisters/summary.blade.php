@extends('adminlte::page')

@section('title', 'Resumen de Cajas')

@section('content_header')
    <div class="card p-3">
        <h2>RESUMEN DE CAJAS POR PERÍODO</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Cuadre entre puntos de cobro: todas las cajas de la
         sucursal abiertas en el rango, con sus totales, el
         desglose de ingresos por método de pago y los totales
         generales del período.
         ============================================================ --}}

    {{-- Filtro de período --}}
    <div class="card p-3">
        <form method="GET" action="{{ route('cash_register.summary') }}" class="form-inline">
            <label for="start_date" class="mr-2">Desde</label>
            <input type="date" name="start_date" id="start_date" class="form-control mr-3" value="{{ $from }}">
            <label for="end_date" class="mr-2">Hasta</label>
            <input type="date" name="end_date" id="end_date" class="form-control mr-3" value="{{ $to }}">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Consultar
            </button>
        </form>
    </div>

    {{-- Tarjetas de totales del período --}}
    <div class="row">
        <div class="col-md-3">
            <div class="small-box bg-success">
                <div class="inner">
                    <h4>${{ number_format($totals['income'], 2) }}</h4>
                    <p>Total recaudado (ingresos)</p>
                </div>
                <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-danger">
                <div class="inner">
                    <h4>${{ number_format($totals['expenses'], 2) }}</h4>
                    <p>Total egresos</p>
                </div>
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box bg-info">
                <div class="inner">
                    <h4>${{ number_format($totals['expected'], 2) }}</h4>
                    <p>Esperado en cajas</p>
                </div>
                <div class="icon"><i class="fas fa-cash-register"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="small-box {{ $totals['open_count'] > 0 ? 'bg-warning' : 'bg-secondary' }}">
                <div class="inner">
                    <h4>{{ $totals['open_count'] }}</h4>
                    <p>Cajas aún abiertas en el período</p>
                </div>
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>
    </div>

    {{-- Ingresos por método de pago --}}
    @if($methodTotals->isNotEmpty())
        <div class="card">
            <div class="card-header py-2"><strong>Ingresos del período por método de pago</strong></div>
            <div class="card-body py-2">
                @foreach($methodTotals as $method => $total)
                    <span class="badge badge-info mr-2" style="font-size: 1rem;">
                        {{ ucfirst($method) }}: ${{ number_format($total, 2) }}
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Detalle por caja --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-bordered">
                    <thead>
                    <tr>
                        <th>Caja</th>
                        <th>Responsable</th>
                        <th>Apertura</th>
                        <th>Cierre</th>
                        <th>Base inicial</th>
                        <th>Ingresos</th>
                        <th>Egresos</th>
                        <th>Esperado</th>
                        <th>Contado al cierre</th>
                        <th>Diferencia</th>
                        <th>Métodos</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($registers as $register)
                        <tr>
                            <td>#{{ $register->id }}
                                @if($register->status === 'open')
                                    <span class="badge badge-warning">Abierta</span>
                                @else
                                    <span class="badge badge-success">Cerrada</span>
                                @endif
                            </td>
                            <td>{{ $register->user->name ?? '—' }} {{ $register->user->last_name ?? '' }}</td>
                            <td>{{ $register->opened_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ $register->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>${{ number_format($register->initial_amount, 2) }}</td>
                            <td>${{ number_format($register->total_income ?? 0, 2) }}</td>
                            <td>${{ number_format($register->total_expenses ?? 0, 2) }}</td>
                            <td>${{ number_format($register->expected_amount ?? 0, 2) }}</td>
                            <td>{{ $register->final_amount !== null ? '$' . number_format($register->final_amount, 2) : '—' }}</td>

                            {{-- Diferencia con color: rojo faltante, verde exacto/sobrante --}}
                            <td>
                                @if($register->final_amount !== null)
                                    <span class="badge {{ $register->difference < 0 ? 'badge-danger' : 'badge-success' }}">
                                        ${{ number_format($register->difference, 2) }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>

                            {{-- Desglose de ingresos por método de esta caja --}}
                            <td>
                                @foreach($methodBreakdown->get($register->id, collect()) as $row)
                                    <small class="d-block">{{ ucfirst($row->payment_method) }}: ${{ number_format($row->total, 2) }}</small>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted">
                                No hubo cajas abiertas en el período seleccionado.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                    @if($registers->isNotEmpty())
                        <tfoot>
                        <tr class="font-weight-bold bg-light">
                            <td colspan="4">TOTALES DEL PERÍODO</td>
                            <td>${{ number_format($totals['initial'], 2) }}</td>
                            <td>${{ number_format($totals['income'], 2) }}</td>
                            <td>${{ number_format($totals['expenses'], 2) }}</td>
                            <td>${{ number_format($totals['expected'], 2) }}</td>
                            <td>${{ number_format($totals['final'], 2) }}</td>
                            <td>${{ number_format($totals['difference'], 2) }}</td>
                            <td></td>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            @if($totals['open_count'] > 0)
                <div class="alert alert-warning mb-0 mt-2">
                    <i class="fas fa-exclamation-triangle"></i>
                    Hay {{ $totals['open_count'] }} caja(s) sin cerrar en este período:
                    el cuadre final solo es definitivo cuando todas las cajas estén cerradas.
                </div>
            @endif
        </div>
    </div>
@endsection
