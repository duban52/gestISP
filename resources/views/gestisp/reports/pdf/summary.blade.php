@extends('gestisp.pdf.layout')

@section('meta')
    <tr>
        <td><span class="meta-label">Período</span><br>{{ $period->etiquetaRango() }}</td>
        <td><span class="meta-label">Agrupación</span><br>{{ $period->granularity->label() }}</td>
        <td><span class="meta-label">Alcance</span><br>{{ $ambito }}</td>
    </tr>
@endsection

@section('content')

    <div class="section-title">Base de clientes</div>
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Contratos vigentes</span>
                <span class="summary-value">{{ number_format($crecimiento['vigentes']) }}</span>
            </td>
            <td>
                <span class="summary-label">Altas del período</span>
                <span class="summary-value positive">{{ number_format($crecimiento['altas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Bajas del período</span>
                <span class="summary-value negative">{{ number_format($crecimiento['bajas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Crecimiento neto</span>
                <span class="summary-value">{{ ($crecimiento['neto'] >= 0 ? '+' : '') . number_format($crecimiento['neto']) }}</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="summary-label">Tasa de cancelación</span>
                <span class="summary-value">{{ number_format($crecimiento['churn'], 2) }}%</span>
            </td>
            <td colspan="3">
                <span class="summary-label">Ingreso recurrente mensual (MRR)</span>
                <span class="summary-value">${{ number_format($crecimiento['mrr'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Distribución por estado</div>
    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $estados->map(fn ($e) => [
            'etiqueta' => $e['etiqueta'],
            'valor' => $e['total'],
            'color' => $e['color'],
        ])->all(),
    ])

    <div class="section-title">Facturación y recaudo</div>
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Facturado</span>
                <span class="summary-value">${{ number_format($facturacion['facturado'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="summary-label">Recaudado</span>
                <span class="summary-value positive">${{ number_format($facturacion['recaudado'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="summary-label">Tasa de recaudo</span>
                <span class="summary-value">{{ number_format($facturacion['tasa_recaudo'], 1) }}%</span>
            </td>
            <td>
                <span class="summary-label">Cartera vencida</span>
                <span class="summary-value negative">${{ number_format($facturacion['cartera_vencida'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Operación técnica</div>
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">Órdenes creadas</span>
                <span class="summary-value">{{ number_format($tecnicas['creadas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Órdenes cerradas</span>
                <span class="summary-value positive">{{ number_format($tecnicas['cerradas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Abiertas hoy</span>
                <span class="summary-value">{{ number_format($tecnicas['abiertas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Resolución promedio</span>
                <span class="summary-value">
                    {{ $tecnicas['horas_promedio'] !== null ? number_format($tecnicas['horas_promedio'], 1) . ' h' : '—' }}
                </span>
            </td>
        </tr>
    </table>

    <div class="note">
        <strong>Cómo leer estas cifras.</strong>
        El alta de un contrato se toma de su fecha de activación y, cuando no está registrada, de la
        fecha de creación. La baja se aproxima con la última modificación del contrato retirado, porque
        el sistema no guarda una fecha de retiro propia. La facturación se cuenta por fecha de emisión y
        el recaudo por fecha de pago, de modo que un período puede recaudar más de lo que facturó si en
        él se cobraron facturas anteriores.
    </div>
@endsection
