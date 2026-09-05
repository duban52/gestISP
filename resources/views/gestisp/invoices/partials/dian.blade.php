{{--
    Bloque DIAN de la representación gráfica.

    Solo se pinta cuando la factura ES electrónica y ya tiene su
    documento; en las internas $dian llega null y aquí no sale nada, que
    es lo correcto: una factura interna no puede parecerse a una
    electrónica.

    El CUFE y el QR salen del documento de verdad. Antes el CUFE estaba
    escrito a mano en la plantilla —el mismo en todas las facturas— y no
    había QR.
--}}
@if(!empty($dian))
    <div class="container">
        <table width="100%" style="font-size: 7px;">
            <tbody>
            <tr>
                <td style="width: 78px; vertical-align: top;">
                    @if($dian['qr'])
                        <img src="{{ $dian['qr'] }}" width="72" height="72" alt="QR"/>
                    @endif
                </td>
                <td style="vertical-align: top; padding-left: 6px;">
                    <p style="margin: 0;"><strong>FACTURA ELECTRÓNICA DE VENTA</strong></p>

                    @if($dian['emisor'])
                        <p style="margin: 0;">
                            {{ $dian['emisor']['nombre'] }} — NIT {{ $dian['emisor']['nit'] }}
                        </p>
                    @endif

                    <p style="margin: 0; word-wrap: break-word;">
                        <strong>CUFE:</strong> {{ $dian['cufe'] }}
                    </p>

                    @if($dian['resolucion'])
                        <p style="margin: 0;">
                            Resolución DIAN {{ $dian['resolucion']['numero'] }}
                            @if($dian['resolucion']['prefijo'])
                                — autoriza {{ $dian['resolucion']['prefijo'] }}
                                del {{ $dian['resolucion']['desde'] }} al {{ $dian['resolucion']['hasta'] }}
                            @endif
                            @if($dian['resolucion']['valida_desde'] && $dian['resolucion']['valida_hasta'])
                                — vigente del {{ $dian['resolucion']['valida_desde']->format('d/m/Y') }}
                                al {{ $dian['resolucion']['valida_hasta']->format('d/m/Y') }}
                            @endif
                        </p>
                    @endif

                    {{--
                        La fecha de validación solo si la DIAN contestó.
                        Antes se imprimía siempre, con la fecha de
                        creación de la factura: decía que estaba
                        validada cuando todavía no lo estaba.
                    --}}
                    @if($dian['validado_en'])
                        <p style="margin: 0;">
                            Validada por la DIAN el {{ $dian['validado_en']->format('d/m/Y H:i') }}
                        </p>
                    @else
                        <p style="margin: 0;">Pendiente de validación por la DIAN</p>
                    @endif

                    @if($dian['ambiente'] === \App\Models\DianConfiguration::PRUEBAS)
                        <p style="margin: 0;"><strong>AMBIENTE DE PRUEBAS — SIN VALIDEZ FISCAL</strong></p>
                    @endif
                </td>
            </tr>
            </tbody>
        </table>
    </div>
@endif
