<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Facturas Pendientes</title>
    <style>
        body {
            font-size: 9px;
            font-family: Arial, sans-serif;
            margin: 0;
        }
        p {
            margin-top: 0;
            margin-bottom: 0;
            margin-left: 3px;
            margin-right: 3px;
        }
        .container {
            width: 720px;
            margin-top: 0;
        }
        .table-border-rounded {
            border-collapse: separate;
            border-spacing: 0;
            border: solid 1px;
            border-radius: 7px;
            overflow: hidden;
        }
        .border-bottom {
            border-bottom: solid 1px;
        }
        .text-center {
            text-align: center;
        }
        .border-total {
            border-bottom: solid 1px;
            border-top: solid 1px;
            border-right: solid 1px;
            border-left: solid 1px;
        }
        .border-in {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            border: 1px solid;
            border-radius: 7px;
            overflow: hidden;
        }
        .border-in td {
            border: solid 1px;
            padding: 1px;
        }
        .info-service {
            width: 100%;
        }
        .border-top {
            border-top: 1px solid;
        }
        .border-left {
            border-left: 1px solid;
        }
        .interline {
            line-height: 0.5;
            font-size: 9px;
            margin: 8px;
        }
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
@foreach($invoices as $invoice)
    <div class="container">
        <div class="info-company">
            <table width="100%">
                <tr>
                    <td style="padding-right: 20px;">
                        <img width="100px" src="{{ asset('storage/'.$invoice->contract->branch->image) }}" alt="Logo"/>
                    </td>
                    <td style="padding-right: 55px; padding-left: 50px;">
                        <p class="interline">EASYNET GROUP S.A.S</p>
                        <p class="interline">Nit: {{ $invoice->contract->branch->nit }}</p>
                        <p class="interline">Tels: {{ $invoice->contract->branch->number_phone }}</p>
                        <p class="interline">{{ $invoice->contract->branch->address }}</p>
                        <p class="interline">{{ $invoice->contract->branch->municipality ?? 'N/A' }}-{{ $invoice->contract->branch->department ?? 'N/A' }} - {{ $invoice->contract->branch->country }}</p>
                    </td>
                    <td>
                        <table class="table-border-rounded">
                            <tbody>
                            <tr>
                                <td colspan="4" class="border-bottom text-center" style="padding-top: 4px; padding-bottom: 4px;">
                                    <strong>FACTURA DE VENTA No {{ $invoice->displayNumber() }}</strong>
                                </td>
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
                                <td colspan="4" style="font-size: 8px;">
                                    <strong>Fecha/Hora emisión: {{ $invoice->created_at }} Fecha/Hora Validación: {{ $invoice->created_at }}</strong>
                                </td>
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
                    <td colspan="2">C.C/NIT {{ $invoice->contract->client->identity_number }}</td>
                    <td colspan="3">SUSCRIPTOR {{ $invoice->contract->client->name }} {{ $invoice->contract->client->last_name }}</td>
                    <td>CODIGO {{ $invoice->contract->id }}</td>
                    <td colspan="2">CORREO {{ $invoice->contract->client->email }}</td>
                </tr>
                <tr>
                    <td colspan="5" class="border-bottom">DIRECCIÓN {{ $invoice->contract->address }} Barrio: {{ $invoice->contract->neighborhood }}</td>
                    <td colspan="2" class="border-bottom">{{ $invoice->contract->municipality ?? 'N/A' }}-{{ $invoice->contract->department ?? 'N/A' }}</td>
                    <td class="border-bottom">TELÉFONO {{ $invoice->contract->client->number_phone }}</td>
                </tr>
                </tbody>
            </table>
        </div>
        <div>
            <br>
        </div>
        <div>
            <table class="table-border-rounded info-service">
                <tbody>
                <tr>
                    <td class="border-bottom"><p>CÓDIGO.</p></td>
                    <td class="border-bottom" colspan="3"><p>DESCRIPCIÓN DEL SERVICIO</p></td>
                    <td class="border-bottom"><p>MEDIDA.</p></td>
                    <td class="border-bottom"><p>CANTIDAD.</p></td>
                    <td class="border-bottom"><p>VALOR UNITARIO.</p></td>
                    <td class="border-bottom border-left"><p>VALOR TOTAL.</p></td>
                </tr>
                @foreach($invoice->invoice_items as $item)
                    <tr>
                        <td><p>{{ $item->id }}</p></td>
                        <td colspan="3"><p>{{ $item->description }} DEL {{ $invoice->billed_period_short }} DEL MES DE {{ $invoice->billed_month_name }}</p></td>
                        <td><p>LUN</p></td>
                        <td><p>{{ $item->quantity }}</p></td>
                        <td><p>{{ $item->unit_price }}</p></td>
                        <td class="border-left" style="text-align: center;"><p>{{ $item->unit_price }}</p></td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="4"><p>Descripción del servicio: {{ $invoice->contract->plan->name }}</p></td>
                    <td colspan="3">
                        @if($invoice->service_suspension_warning)
                            <p style="color: red; font-weight: bold; margin-left: 20px; text-align: left;">
                                *** PAGO INMEDIATO - AVISO DE CORTE ***
                            </p>
                        @endif
                    </td>
                    <td colspan="1" class="border-left"></td>
                </tr>
                <tr>
                    <td colspan="6" rowspan="2" class="text-center border-top"><p>{{ $invoice->contract->branch->message_custom_invoice }}</p></td>
                    <td class="border-top border-left"><p><strong>SUBTOTAL</strong></p></td>
                    <td class="border-top border-left" style="text-align: right;"><p>{{ $invoice->total - $invoice->tax }}</p></td>
                </tr>
                <tr>
                    <td class="border-left"><p><strong>IVA 19%</strong></p></td>
                    <td class="border-left" style="text-align: right;"><p>{{ $invoice->tax }}</p></td>
                </tr>
                <tr>
                    <td colspan="6" rowspan="2" class="text-center border-top">
                        <p><strong>Quejas y reclamos</strong></p>
                        <p>Tel: {{ $invoice->contract->branch->number_phone }} - Cel: Dirección: {{ $invoice->contract->branch->address }}</p>
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
                    <td colspan="4">A la primera cuota vencida se le suspende la señal, la reconexión tiene un costo de $ {{ $invoice->contract->branch->reconnection_price }}</td>
                    <td>suscriptor</td>
                    <td colspan="2"><strong>TOTAL A PAGAR</strong></td>
                    <td><strong>{{ $invoice->total }}</strong></td>
                </tr>
                <tr>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </div>
        <hr style="margin: 5px 0; border-top: 1px dashed #000;">

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
                        <img width="80px" src="{{ asset('storage/'.$invoice->contract->branch->image) }}" alt="Logo"/>
                    </td>
                    <td style="padding-right: 50px; margin-bottom: 0;">
                        <img src="{{ $barcodeUrls[$invoice->id] }}" alt="Código de barras" width="250px">
                        <p style="text-align: center; margin: 0;">{{ $code }}</p>
                    </td>
                    <td>
                        <p style="text-align: right; padding-right: 15px;">Señal empaquetada</p>
                        <table class="table-border-rounded">
                            <td style="padding-right: 5px; padding-bottom: 10px; padding-left: 5px;">
                                <p><strong>FACTURA DE VENTA No {{ $invoice->displayNumber() }}</strong></p>
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
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>C.C <strong>{{ $invoice->contract->client->identity_number }}</strong></p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;" colspan="3">
                                    <p>SUSCRIPTOR <strong>{{ $invoice->contract->client->name }} {{ $invoice->contract->client->last_name }}</strong></p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>PERIODO <strong>{{ $invoice->billed_year_month }}</strong></p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>FECHA VENCE <strong>{{ $invoice->due_date }}</strong></p>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="3" style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>DIRECCIÓN <strong>{{ $invoice->contract->address }} Barrio {{ $invoice->contract->neighborhood }}</strong></p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>TELÉFONO <strong>{{ $invoice->contract->client->number_phone }}</strong></p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>CÓDIGO {{ $invoice->contract->id }}</p>
                                </td>
                                <td style="padding-top: 1px; padding-bottom: 1px; padding-left: 4px; padding-right: 2px;">
                                    <p>FECHA CORTE <strong>{{ $invoice->suspension_date }}</strong></p>
                                </td>
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
                <tr>
                    <td colspan="4">
                        <p style="margin: 0;">CUFE:d57b1f09fa4a0145379ee0e06db51efa66fb89ed93a5946cf5b9f5ff0d072e35a64e76c2499b38cba85a0d4e4eb05deb</p>
                    </td>
                    <td></td>
                    <td></td>
                    <td>
                        <p style="margin: 0;">SISTEMA</p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <p style="margin: 0;">Costo traslado servicios ${{ $invoice->contract->branch->moving_price }}</p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2">
                        <p style="margin: 0;">Costo reconexión servicio ${{ $invoice->contract->branch->reconnection_price }}</p>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    @if(!$loop->last)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
