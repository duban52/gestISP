<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Factura Electrónica</title>
    <style>
        body{
            /* 8,5 px, MEDIDO, NO ELEGIDO A OJO.
               Media carta (396 pt), interlineado que no solape, y letra
               de 9 px son tres cosas que NO caben a la vez: a 9 px la
               factura electronica pide 414 pt y se parte en dos hojas.
               Se midio renderizando: a 8,5 px caben hasta 3 renglones;
               a 8 px, cuatro. 8,5 es el cambio mas pequeno que cumple
               las tres condiciones.
               Si algun dia hacen falta 4 renglones, bajar a 8px aqui. */
            font-size: 8.5px;
            font-family: Arial, sans-serif;
            /* MARGENES DE LA HOJA.
               El papel es media carta: 612 pt = 816 px a 96 ppp. Antes
               el cuerpo iba a margen cero y el contenido era una caja
               fija de 720 px, asi que la factura salia pegada al borde
               izquierdo y sobraban casi 100 px a la derecha. Ahora hay
               aire a los dos lados y el contenido ocupa lo que queda. */
            margin: 0;
            padding: 5px 12px;
        }
        p{
            margin-top: 0;
            margin-bottom: 0;
            margin-left: 3px;
            margin-right: 3px;
        }
        .container{
            /* Se estira a lo que deje el relleno del cuerpo en vez de
               una anchura fija: asi la factura llena la hoja y no deja
               una franja muerta a la derecha. */
            width: 100%;
            margin-top: 0;
        }
        .table-border-rounded{
            border-collapse: separate;
            border-spacing: 0;
            border: solid 1px;
            border-radius: 7px;
            overflow: hidden;
        }
        .border-bottom{
            border-bottom: solid 1px;
        }
        .text-center{
            text-align: center;
        }
        .border-total{
            border-bottom: solid 1px;
            border-top: solid 1px;
            border-right: solid 1px;
            border-left: solid 1px;
        }
        .border-in{
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border: 1px solid;
            border-radius: 7px;
            overflow: hidden;
        }
        .border-in td{
            border: solid 1px ;
            padding: 1px;
        }
        .info-service{
            width: 100%;
        }
        .border-top{
            border-top: 1px solid;
        }
        .border-left{
            border-left: 1px solid;
        }
        .interline{
            /* EL INTERLINEADO NO PUEDE BAJAR DE 1.
               Estuvo en 0.5 y el resultado era que un nombre de empresa
               largo, al partirse en dos lineas, se montaba sobre si
               mismo: ilegible. Medio interlineado solo "funciona"
               mientras cada linea quepa entera, y eso no se puede
               garantizar con nombres que escribe el usuario.
               La compacidad se saca de los margenes, no de solapar. */
            line-height: 1.05;
            font-size: 9px;
            margin: 0 8px;
        }

    </style>
</head>
<body>
@php
    /**
     * La tarifa de IVA que se imprime en el bloque de totales.
     *
     * Se toma la de las lineas que declaran impuesto. Si hay varias
     * tarifas distintas en la misma factura no se puede resumir en una
     * cifra, asi que se deja en blanco y manda el importe: preferible a
     * escribir una tarifa que solo vale para parte del documento.
     */
    $tarifas = $invoice->invoice_items
        ->map(fn ($linea) => (float) $linea->percentage_tax)
        ->filter(fn ($tarifa) => $tarifa > 0)
        ->unique()
        ->values();

    $tarifaIva = $tarifas->count() === 1 ? $tarifas->first() : 0.0;
@endphp
<div class="container">
    <div class="info-company">
        <table width="100%">
            <tr>
                <td style="padding-right: 20px;"><img width="100px" src="{{ asset('storage/'.$invoice->contract?->branch?->image) }}" alt="Logo"/></td>
                {{-- El aire de esta celda se reduce cuando hay QR: es el
                     hueco de donde sale su sitio. --}}
                <td style="padding-right: {{ empty($dian) ? '55px' : '10px' }}; padding-left: {{ empty($dian) ? '50px' : '20px' }};">
                    <p class="interline">{{ $dian['emisor']['nombre'] ?? $invoice->contract?->branch?->name ?? '' }}</p>
                    <p class="interline">Nit: {{ $invoice->contract?->branch?->nit }}</p>
                    <p class="interline">Tels: {{ $invoice->contract?->branch?->number_phone }}</p>
                    <p class="interline">{{ $invoice->contract?->branch?->address }}</p>
                    <p class="interline">{{ $invoice->contract?->branch?->municipality }}-{{ $invoice->contract?->branch?->department ?? 'N/A' }} - {{ $invoice->contract?->branch?->country }}</p>
                </td>
                {{-- EL QR VA AQUÍ ARRIBA, NO AL PIE.
                     La hoja es media carta y el bloque DIAN iba al
                     final, detrás de los costos: no cabía y se llevaba el QR
                     a una segunda página. Aquí ocupa un hueco que ya existía
                     y el pie queda en una línea. --}}
                @include('gestisp.invoices.partials.dian_qr', ['dian' => $dian ?? null])
                <td>
                    <table class="table-border-rounded">
                        <tbody>
                        <tr>
                            {{-- Electrónica e interna no se llaman igual: una factura
                                 interna que se anuncie como electrónica induce a error
                                 sobre su valor fiscal. --}}
                            <td colspan="4" class="border-bottom text-center" style="padding-top: 4px; padding-bottom: 4px;"><strong>{{ !empty($dian) ? 'FACTURA ELECTRÓNICA DE VENTA' : 'FACTURA DE VENTA' }} No {{ $invoice->displayNumber() }}</strong></td>
                        </tr>
                        <tr>
                            <td>FECHA DE FACTURA</td>
                            <td><strong>{{ $invoice->issue_date }}</strong></td>
                            <td>FECHA DE CORTE</td>
                            <td><strong>{{ $invoice->suspension_date }}</strong></td>
                        </tr>
                        <tr>
                            <td>FECHA DE VENCIMIENTO</td>
                            <td><strong>{{ $invoice->due_date }}</strong></td>
                            <td>FORMA DE PAGO</td>
                            <td>Crédito</td>
                        </tr>
                        <tr>
                            <td>PERIODO</td>
                            <td><strong>{{ $invoice->billed_month_name }}</strong></td>
                            <td>METODO DE PAGO</td>
                            <td>EFECTIVO</td>
                        </tr>
                        <tr>
                            {{-- La fecha de validación la pone la DIAN, no nosotros.
                                 Aquí venía impresa la de creación de la factura: una
                                 fecha inventada que afirmaba una validación que podía
                                 no haber ocurrido. Ahora sale en el bloque DIAN, y
                                 solo cuando existe de verdad. --}}
                            <td colspan="4" style="font-size: 8px;"><strong>Fecha/Hora emisión: {{ $invoice->created_at }}</strong></td>
                        </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
    </div>

    <div class="info-suscriptor">
        <table class="table-border-rounded border-in">
            <tbody>
            <tr>
                <td colspan="8" class="border-bottom text-center"><strong>DATOS DEL SUSCRIPTOR</strong></td>
            </tr>
            <tr>
                <td colspan="2">C.C/NIT {{ $invoice->contract?->client?->identity_number}}</td>
                <td colspan="3">SUSCRIPTOR {{ trim(($invoice->contract?->client?->name ?? '') . ' ' . ($invoice->contract?->client?->last_name ?? '')) ?: '—' }}</td>
                <td>CODIGO {{ $invoice->contract->numero_visible }}</td>
                <td colspan="2">CORREO {{ $invoice->contract?->client?->email }}</td>
            </tr>
            <tr>
                <td colspan="5" class="border-bottom">DIRECCIÓN {{ $invoice->contract->address }} Barrio: {{ $invoice->contract->neighborhood }}</td>
                <td colspan="2" class="border-bottom">{{ $invoice->contract->municipality ?? 'N/A' }}-{{ $invoice->contract->department  ?? 'N/A'}}</td>
                <td class="border-bottom">TELÉFONO {{ $invoice->contract?->client?->number_phone }}</td>
            </tr>
            </tbody>
        </table>
    </div>
    <div>
        <table class="table-border-rounded info-service">
            <tbody>
            <tr>
                <td class="border-bottom"><p>CÓDIGO.</p></td>
                <td  class="border-bottom" colspan="3"><p>DESCRIPCIÓN DEL SERVICIO</p></td>
                <td class="border-bottom"><p>MEDIDA.</p></td>
                <td class="border-bottom"><p>CANTIDAD.</p></td>
                <td class="border-bottom"><p>VALOR UNITARIO.</p></td>
                <td class="border-bottom border-left"><p>VALOR TOTAL.</p></td>
            </tr>
            @foreach($invoice->invoice_items as $item)
            <tr>
                {{-- El codigo de PRODUCTO que se congelo en la linea al
                     emitir, no el id interno del renglon. El id no le
                     dice nada a nadie y cambia entre facturas del mismo
                     servicio; el codigo es el que identifica lo que se
                     esta cobrando. Las lineas que no vienen de un
                     servicio —cargos sueltos— no lo tienen. --}}
                <td><p>{{ $item->product_code ?: '—' }}</p></td>
                <td colspan="3"><p>{{ $item->description }} DEL {{ $invoice->billed_period_short }} DEL MES DE {{ $invoice->billed_month_name }}</p></td>
                <td><p>LUN</p></td>
                <td><p>{{ $item->quantity }}</p></td>
                <td><p>{{ $item->unit_price }}</p></td>
                <td class="border-left" style="text-align: center;"><p>{{ $item->unit_price }}</p></td>
            </tr>
            @endforeach
            <tr>
                <td colspan="4"><p>Descripción del servicio: {{ $invoice->contract?->plan?->name ?? '—' }} </p></td>
                <td colspan="3">
                        @if($invoice->service_suspension_warning)
                            <p style="color: red; font-weight: bold; margin-left: 20px; text-align: left;">
                                *** PAGO INMEDIATO - AVISO DE CORTE ***
                            </p>
                        @endif
                </td>
                <td colspan="1" class="border-left">
                </td>
            </tr>
            <tr>
                <td colspan="6" rowspan="2" class="text-center border-top"><p>{{ $invoice->contract?->branch?->message_custom_invoice }}</p></td>
                <td class="border-top border-left"><p><strong>SUBTOTAL</strong></p></td>
                <td class="border-top border-left" style="text-align: right;"><p>{{ $invoice->total - $invoice->tax }}</p></td>
            </tr>
            <tr>
                {{-- LA TARIFA SALE DEL DOCUMENTO, NO ESTA ESCRITA A MANO.
                     Decia «IVA 19%» siempre. En una factura de servicios
                     EXCLUIDOS —el caso normal de un ISP: internet
                     residencial de estratos 1 a 3— eso imprimia
                     «IVA 19% ... 0.00», afirmando una tarifa que no se
                     aplico. Y en una linea al 5% mentia igual. --}}
                <td class="border-left"><p><strong>IVA {{ rtrim(rtrim(number_format($tarifaIva, 2, ',', '.'), '0'), ',') }}%</strong></p></td>
                <td class="border-left" style="text-align: right;"><p>{{ $invoice->tax }}</p></td>
            </tr>
            <tr>
                <td colspan="6" rowspan="2" class="text-center border-top">
                    <p><strong>Quejas y reclamos</strong></p>
                    <p>Tel: {{ $invoice->contract?->branch?->number_phone }} - Cel: Dirección: {{ $invoice->contract?->branch?->address }}</p>
                </td>
                <td class="border-left"><strong>TOTAL DEL MES</strong></td>
                <td class="border-left" style="text-align: right;">{{ $invoice->total }}</td>
            </tr>
            <tr>
                <td class="border-top border-left"><p><strong>SALDO ANTERIOR</strong></p></td>
                <td class="border-top border-left" style="text-align: right;"><p>{{ $invoice->pending_invoice_amount }}</p></td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="footer-suscriptor">
        <table style="width: 100%;">
            <tbody>
            <tr>
                <td colspan="4">A la primera cuota vencida se le suspende la señal, la reconexión tiene un costo de $ {{ $invoice->contract?->branch?->reconnection_price }}</td>
                <td>suscriptor</td>
                <td colspan="2"><strong>TOTAL A PAGAR</strong></td>
                <td><strong>{{ $invoice->total }}</strong></td>
            </tr>
            </tbody>
        </table>
    </div>
    <hr style="margin: 2px 0; border-top: 1px dashed #000;">

    <div class="container">
        @if($invoice->service_suspension_warning)
            <p style="color: red; font-weight: bold; margin-right: 122px; text-align: right;">
                *** PAGO INMEDIATO - AVISO DE CORTE ***
            </p>
        @endif
        <table width="100%">
            <tbody>
                <tr>
                   <td style="padding-right: 80px">
                       <img width="80px" src="{{ asset('storage/'.$invoice->contract?->branch?->image) }}" alt="Logo"/>
                   </td>
                    <td style="padding-right: 50px; margin-bottom: 0;">
                        <img src="{{ $barcodeUrl }}" alt="Código de barras" width="250px">
                        <p style="text-align: center; margin: 0;">{{ $codeString }}</p>
                    </td>
                    <td>
                        <p style="text-align: right; padding-right: 15px;" >Señal empaquetada</p>
                        <table class="table-border-rounded">
                            <td style="padding-right: 5px; padding-bottom: 10px; padding-left: 5px;">
                                <p><strong>{{ !empty($dian) ? 'FACTURA ELECTRÓNICA DE VENTA' : 'FACTURA DE VENTA' }} No {{ $invoice->displayNumber() }}</strong></p>
                            </td>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="container">
        <table width="100%">
            <tbody>
                <tr>
                    <td>
                        <table class="border-in" style="font-size: 8px;">
                            <tbody>
                                <tr>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>C.C <strong>{{ $invoice->contract?->client?->identity_number}}</strong></p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;" colspan="3"><p>SUSCRIPTOR <strong>{{ trim(($invoice->contract?->client?->name ?? '') . ' ' . ($invoice->contract?->client?->last_name ?? '')) ?: '—' }}</strong></p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>PERIODO <strong>{{ $invoice->billed_year_month }}</strong></p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>FECHA VENCE <strong>{{ $invoice->due_date }}</strong></p></td>
                                </tr>
                                <tr>
                                    <td colspan="3" style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>DIRECCIÓN <strong>{{ $invoice->contract->address }} Barrio {{ $invoice->contract->neighborhood }}</strong></p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>TELÉFONO <strong>{{ $invoice->contract?->client?->number_phone}}</strong></p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>CÓDIGO {{ $invoice->contract->numero_visible }}</p></td>
                                    <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;"><p>FECHA CORTE <strong>{{ $invoice->suspension_date }}</strong></p></td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                    <td>
                        <table class="table-border-rounded">
                            <tbody>
                                <tr>
                                    <td style="padding-left: 5px; padding-right: 3px;">
                                        <p><strong>TOTAL A PAGAR</strong></p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-left: 5px; padding-right: 3px;">
                                        <p><strong>{{ $invoice->total }}</strong></p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="container">
        <table width="100%">
            <tbody>
            {{-- Las dos en una linea: en media carta cada renglon cuenta,
                 y asi es como van en la representacion grafica que se
                 tomo de referencia. --}}
            <tr>
                <td colspan="2">
                    <p style="margin: 0;">Costo traslado servicios ${{ $invoice->contract?->branch?->moving_price }} &nbsp;&nbsp; Costo reconexión servicio ${{ $invoice->contract?->branch?->reconnection_price }}</p>
                    @include('gestisp.invoices.partials.dian', ['dian' => $dian ?? null])
                </td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
