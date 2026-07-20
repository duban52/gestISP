@extends('gestisp.pdf.layout')

@section('meta')
    <tr>
        <td><span class="meta-label">Estado</span><br>A la fecha de generación</td>
        <td><span class="meta-label">Altas del período</span><br>{{ $period->etiquetaRango() }}</td>
        <td><span class="meta-label">Sucursal</span><br>{{ $ambito }}</td>
    </tr>
@endsection

@section('content')

    <div class="section-title">Equipos en servicio</div>
    <table class="summary">
        <tr>
            <td>
                <span class="summary-label">ONTs registradas</span>
                <span class="summary-value">{{ number_format($resumen['onts']) }}</span>
            </td>
            <td>
                <span class="summary-label">Cuentas PPPoE activas</span>
                <span class="summary-value positive">{{ number_format($resumen['pppoe_activas']) }}</span>
            </td>
            <td>
                <span class="summary-label">PPPoE deshabilitadas</span>
                <span class="summary-value">{{ number_format($resumen['pppoe_deshabilitadas']) }}</span>
            </td>
            <td>
                <span class="summary-label">Nodos</span>
                <span class="summary-value">{{ $resumen['olts'] }} OLT / {{ $resumen['routers'] }} router</span>
            </td>
        </tr>
        <tr>
            <td>
                <span class="summary-label">ONTs sin contrato</span>
                <span class="summary-value {{ $resumen['onts_sin_contrato'] > 0 ? 'negative' : '' }}">
                    {{ number_format($resumen['onts_sin_contrato']) }}
                </span>
            </td>
            <td>
                <span class="summary-label">PPPoE sin contrato</span>
                <span class="summary-value {{ $resumen['pppoe_sin_contrato'] > 0 ? 'negative' : '' }}">
                    {{ number_format($resumen['pppoe_sin_contrato']) }}
                </span>
            </td>
            <td>
                <span class="summary-label">ONTs deshabilitadas</span>
                <span class="summary-value">{{ number_format($resumen['onts_deshabilitadas']) }}</span>
            </td>
            <td>
                <span class="summary-label">ONTs con CATV</span>
                <span class="summary-value">{{ number_format($resumen['onts_con_catv']) }}</span>
            </td>
        </tr>
    </table>

    <div class="section-title">Cobertura de los contratos vigentes</div>
    <table class="data">
        <colgroup>
            <col style="width: 40%;"><col style="width: 30%;"><col style="width: 30%;">
        </colgroup>
        <thead>
        <tr>
            <th>Concepto</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Sobre el total</th>
        </tr>
        </thead>
        <tbody>
        @php
            $vigentes = max(1, $cobertura['vigentes']);
            $filas = [
                ['Contratos vigentes', $cobertura['vigentes'], false],
                ['Con ONT asignada', $cobertura['con_ont'], false],
                ['Sin ONT', $cobertura['sin_ont'], $cobertura['sin_ont'] > 0],
                ['Con cuenta PPPoE', $cobertura['con_pppoe'], false],
                ['Sin cuenta PPPoE', $cobertura['sin_pppoe'], $cobertura['sin_pppoe'] > 0],
                ['Sin ningún equipo registrado', $cobertura['sin_nada'], $cobertura['sin_nada'] > 0],
            ];
        @endphp
        @foreach ($filas as [$etiqueta, $valor, $alerta])
            <tr>
                <td class="{{ $alerta ? 'strong' : '' }}">{{ $etiqueta }}</td>
                <td class="text-right strong {{ $alerta ? 'negative' : '' }}">{{ number_format($valor) }}</td>
                <td class="text-right">{{ number_format($valor / $vigentes * 100, 1) }}%</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="section-title">ONTs por OLT</div>
    <table class="data">
        <colgroup>
            <col style="width: 26%;"><col style="width: 18%;"><col style="width: 12%;">
            <col style="width: 12%;"><col style="width: 16%;"><col style="width: 16%;">
        </colgroup>
        <thead>
        <tr>
            <th>OLT</th>
            <th>Dirección IP</th>
            <th class="text-right">ONTs</th>
            <th class="text-right">Puertos</th>
            <th class="text-right">Sin contrato</th>
            <th class="text-right">RX medio</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($olts as $o)
            <tr>
                <td>{{ $o['olt'] }}</td>
                <td>{{ $o['ip'] }}</td>
                <td class="text-right strong">{{ number_format($o['total']) }}</td>
                <td class="text-right">{{ number_format($o['puertos']) }}</td>
                <td class="text-right {{ $o['sin_contrato'] > 0 ? 'negative' : 'muted' }}">
                    {{ number_format($o['sin_contrato']) }}
                </td>
                <td class="text-right">{{ $o['rx_promedio'] !== null ? $o['rx_promedio'] . ' dBm' : '—' }}</td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="6">No hay ONTs registradas.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Cuentas PPPoE por router</div>
    <table class="data">
        <colgroup>
            <col style="width: 28%;"><col style="width: 20%;"><col style="width: 13%;">
            <col style="width: 13%;"><col style="width: 13%;"><col style="width: 13%;">
        </colgroup>
        <thead>
        <tr>
            <th>Router</th>
            <th>Dirección IP</th>
            <th class="text-right">Cuentas</th>
            <th class="text-right">Activas</th>
            <th class="text-right">Deshab.</th>
            <th class="text-right">Sin contrato</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($routers as $r)
            <tr>
                <td>{{ $r['router'] }}</td>
                <td>{{ $r['ip'] }}</td>
                <td class="text-right strong">{{ number_format($r['total']) }}</td>
                <td class="text-right positive">{{ number_format($r['activas']) }}</td>
                <td class="text-right">{{ number_format($r['deshabilitadas']) }}</td>
                <td class="text-right {{ $r['sin_contrato'] > 0 ? 'negative' : 'muted' }}">
                    {{ number_format($r['sin_contrato']) }}
                </td>
            </tr>
        @empty
            <tr class="empty-row"><td colspan="6">No hay cuentas PPPoE registradas.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Calidad óptica de las ONTs</div>
    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $optica->map(fn ($o) => [
            'etiqueta' => $o['etiqueta'],
            'valor' => $o['total'],
            'color' => $o['color'],
        ])->all(),
    ])

    <div class="section-title">Cuentas por perfil de velocidad</div>
    @include('gestisp.reports.pdf.partials.bars', [
        'filas' => $perfiles->map(fn ($p) => [
            'etiqueta' => $p['perfil'],
            'valor' => $p['total'],
            'color' => '#1F4E79',
        ])->all(),
    ])

    @if ($huerfanas->isNotEmpty())
        <div class="section-title">ONTs sin contrato asignado</div>
        <table class="data">
            <colgroup>
                <col style="width: 22%;"><col style="width: 16%;"><col style="width: 22%;">
                <col style="width: 28%;"><col style="width: 12%;">
            </colgroup>
            <thead>
            <tr>
                <th>Serial</th>
                <th>Ubicación</th>
                <th>OLT</th>
                <th>Descripción en la OLT</th>
                <th class="text-right">RX</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($huerfanas as $h)
                <tr>
                    <td>{{ $h['sn'] }}</td>
                    <td>{{ $h['ubicacion'] }}</td>
                    <td>{{ $h['olt'] }}</td>
                    <td>{{ $h['descripcion'] ?: '—' }}</td>
                    <td class="text-right">{{ $h['rx_power'] !== null ? $h['rx_power'] : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="note">
        <strong>Cómo leer estas cifras.</strong>
        Las cantidades de equipos son una foto del momento en que se generó el documento, no del
        período: el rango de fechas solo se aplica a las altas. Un contrato sin equipo registrado
        es un cliente al que se factura sin que el sistema sepa por dónde recibe el servicio, y un
        equipo sin contrato es capacidad instalada que no se está cobrando; ambas situaciones pueden
        ser legítimas (otra tecnología, equipos de reserva) pero conviene revisarlas. La potencia
        óptica corresponde a la última lectura SNMP guardada de cada ONT.
    </div>
@endsection
