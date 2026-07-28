{{-- ============================================================
     Cuerpo de la tirilla de un recibo de caja.

     Se usa TAL CUAL en dos sitios: la vista HTML que se muestra en
     el modal y se manda a la impresora térmica, y la vista PDF que
     se descarga. Por eso aquí no hay <html> ni <style>: cada
     envoltorio pone los suyos.

     Reglas de maquetación (el ancho útil son ~72 mm):
       - Todo se alinea con tablas: dompdf no tiene flexbox.
       - Los importes van a la derecha en su propia celda; no se
         alinean con espacios, que en proporcional no cuadran.
       - Nada de columnas estrechas para texto largo: las
         descripciones ocupan la fila completa cuando hace falta.

     Variables:
       $recibo — un elemento del arreglo que arma App\Support\PaymentReceipt
       $logoSrc — ruta/URL del logo, o null
     ============================================================ --}}
@php
    /** Formato de moneda de la tirilla: sin símbolo, para ganar ancho. */
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');

    $branch = $recibo['branch'];
    $cliente = $recibo['cliente'];
    $contrato = $recibo['contrato'];
@endphp

<div class="ticket">

    {{-- ---------- Encabezado: quién cobra ---------- --}}
    <div class="center">
        @if($logoSrc)
            <img src="{{ $logoSrc }}" class="logo" alt="">
        @endif

        <div class="empresa">{{ $branch->name ?? 'GestISP' }}</div>

        @if($branch?->nit)
            <div class="small">NIT {{ $branch->nit }}</div>
        @endif

        @if($branch?->address)
            <div class="small">{{ $branch->address }}</div>
        @endif

        @php
            $ubicacion = \App\Support\PdfBranding::locationLine($branch);
            $telefonos = \App\Support\PdfBranding::phoneLine($branch);
        @endphp

        @if($ubicacion)
            <div class="small">{{ $ubicacion }}</div>
        @endif

        @if($telefonos)
            <div class="small">Tel. {{ $telefonos }}</div>
        @endif

        <div class="titulo">RECIBO DE CAJA</div>
    </div>

    {{-- ---------- Datos del cobro ---------- --}}
    <table class="meta">
        <tr>
            <td class="k">Fecha</td>
            <td class="v">{{ \Illuminate\Support\Carbon::parse($recibo['fecha'])->format('d/m/Y') }}
                {{ optional($recibo['hora'])->format('h:i a') }}</td>
        </tr>
        <tr>
            <td class="k">Recibo N.º</td>
            <td class="v">{{ $recibo['numero'] }}</td>
        </tr>
        <tr>
            <td class="k">Contrato</td>
            <td class="v">{{ $contrato->numero_visible ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Cliente</td>
            <td class="v">{{ trim(($cliente->name ?? '') . ' ' . ($cliente->last_name ?? '')) ?: '—' }}</td>
        </tr>
        <tr>
            <td class="k">{{ $cliente->type_document ?? 'Doc.' }}</td>
            <td class="v">{{ $cliente->identity_number ?? '—' }}</td>
        </tr>
        @if($contrato?->address)
            <tr>
                <td class="k">Dirección</td>
                <td class="v">{{ $contrato->address }}@if($contrato->neighborhood), {{ $contrato->neighborhood }}@endif</td>
            </tr>
        @endif
        <tr>
            <td class="k">Cajero(a)</td>
            <td class="v">{{ trim(($recibo['cajero']->name ?? '') . ' ' . ($recibo['cajero']->last_name ?? '')) ?: '—' }}</td>
        </tr>
        @if($recibo['caja'])
            <tr>
                <td class="k">Caja</td>
                <td class="v">N.º {{ $recibo['caja']->id }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">Forma pago</td>
            <td class="v">{{ $recibo['metodo'] }}@if($recibo['referencia']) · Ref. {{ $recibo['referencia'] }}@endif</td>
        </tr>
        @if($recibo['lote']?->payer_name)
            {{-- Cobro múltiple: se deja constancia de quién pagó,
                 porque no es el titular del contrato. Solo se imprime
                 si se anotó el nombre; un "Tercero" genérico no le
                 aporta nada a quien recibe la tirilla. --}}
            <tr>
                <td class="k">Pagó</td>
                <td class="v">{{ $recibo['lote']->payer_name }}
                    @if($recibo['lote']->payer_document)({{ $recibo['lote']->payer_document }})@endif
                </td>
            </tr>
        @endif
    </table>

    {{-- ---------- Detalle de lo facturado ---------- --}}
    <table class="detalle">
        <thead>
        <tr>
            <th class="l">DESCRIPCIÓN</th>
            <th class="r">MONTO</th>
        </tr>
        </thead>
        <tbody>
        @foreach($recibo['lineas'] as $linea)
            <tr>
                <td colspan="2" class="doc">
                    {{ $linea['titulo'] }}@if($linea['periodo']) · {{ $linea['periodo'] }}@endif
                </td>
            </tr>

            @foreach($linea['conceptos'] as $concepto)
                <tr>
                    <td class="l concepto">
                        @if($concepto['cantidad'] > 1){{ rtrim(rtrim(number_format($concepto['cantidad'], 2, ',', '.'), '0'), ',') }}x @endif
                        {{ $concepto['descripcion'] }}
                    </td>
                    <td class="r">{{ $money($concepto['valor']) }}</td>
                </tr>
            @endforeach

            <tr>
                <td class="l aplicado">{{ $linea['etiqueta_aplicado'] }}</td>
                <td class="r aplicado">{{ $money($linea['aplicado']) }}</td>
            </tr>

            @if($linea['saldo_documento'] > 0)
                <tr>
                    <td class="l small">Queda pendiente</td>
                    <td class="r small">{{ $money($linea['saldo_documento']) }}</td>
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>

    {{-- ---------- Retenciones ---------- --}}
    @if(!empty($recibo['retenciones']))
        {{-- Se imprimen SIEMPRE que existan: el cliente necesita ver
             que su retención se aplicó, y nosotros que el faltante en
             efectivo tiene explicación. --}}
        <div class="subtitulo">RETENCIONES PRACTICADAS</div>
        <table class="detalle">
            @foreach($recibo['retenciones'] as $retencion)
                <tr>
                    <td class="l concepto">
                        {{ $retencion['descripcion'] }}
                        @if($retencion['certificado'])
                            <span class="small">Cert. {{ $retencion['certificado'] }}</span>
                        @endif
                    </td>
                    <td class="r">{{ $money($retencion['monto']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    {{-- ---------- Totales ---------- --}}
    <table class="totales">
        @if($recibo['total_retenido'] > 0)
            <tr>
                <td class="l">Valor cancelado</td>
                <td class="r">{{ $money($recibo['total_cancelado']) }}</td>
            </tr>
            <tr>
                <td class="l">(-) Retenciones</td>
                <td class="r">{{ $money($recibo['total_retenido']) }}</td>
            </tr>
        @endif
        <tr class="grande">
            <td class="l">TOTAL RECIBIDO</td>
            <td class="r">{{ $money($recibo['total_efectivo']) }}</td>
        </tr>
    </table>

    <table class="saldo">
        <tr>
            <td class="l">
                @if($recibo['saldo_actual'] < 0)
                    Saldo a favor
                @else
                    Saldo actual
                @endif
            </td>
            <td class="r">{{ $money(abs($recibo['saldo_actual'])) }}</td>
        </tr>
    </table>

    {{-- ---------- Pie ----------
         A propósito NO se imprime aquí el mensaje fiscal de la
         sucursal (resolución DIAN, régimen, registro TIC): ese texto
         pertenece a la FACTURA. El recibo de caja es el comprobante
         de que se recibió un dinero, no un documento fiscal, y en una
         tirilla de 80 mm ese párrafo se come media hoja de papel en
         cada cobro. --}}
    <div class="center pie">
        <div>*Gracias por su pago*</div>
        <div>*Un mes de atraso amerita corte*</div>
        <div class="small">Conserve este recibo como soporte.</div>
    </div>

</div>
