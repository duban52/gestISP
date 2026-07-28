{{-- ============================================================
     Recibo de caja — versión HTML (tirilla de 80 mm)

     Es la que se ve dentro del modal (en un iframe) y la que se
     manda a la impresora térmica con window.print().

     Por qué imprimir el HTML y no el PDF: la impresora térmica
     corta el papel donde termina el contenido. El navegador,
     con @page { size: 80mm auto }, le entrega exactamente el alto
     del recibo; un PDF, en cambio, tiene una página de alto fijo y
     escupe papel en blanco hasta completarla.

     Variables: $recibos (arreglo de App\Support\PaymentReceipt::build)
     ============================================================ --}}
    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de caja</title>
    <style>
        /* ---------- Página ----------
           80 mm de ancho, alto automático: así el navegador manda a
           la térmica justo el papel que ocupa el recibo. */
        @page {
            size: 80mm auto;
            margin: 0;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #FFFFFF;
        }

        body {
            /* Monoespaciada: en una tirilla las cifras tienen que
               quedar en columna, y una proporcional las descuadra. */
            font-family: "DejaVu Sans Mono", "Consolas", "Courier New", monospace;
            font-size: 10px;
            line-height: 1.35;
            color: #000000;
        }

        .ticket {
            width: 72mm;          /* área imprimible de un rollo de 80 mm */
            margin: 0 auto;
            padding: 4mm 0 6mm;
            page-break-after: always;
        }

        .ticket:last-child {
            page-break-after: auto;
        }

        .center { text-align: center; }
        .l { text-align: left; }
        .r { text-align: right; }

        .logo {
            max-width: 40mm;
            max-height: 18mm;
            margin-bottom: 2mm;
        }

        .empresa {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .titulo {
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 3mm 0 2mm;
        }

        .subtitulo {
            font-weight: bold;
            margin-top: 2mm;
            border-top: 1px dashed #000000;
            padding-top: 1mm;
        }

        .small { font-size: 9px; }

        /* ---------- Datos del cobro ---------- */
        table { width: 100%; border-collapse: collapse; }

        .meta td {
            vertical-align: top;
            padding: 0.3mm 0;
        }

        .meta .k {
            width: 22mm;
            font-weight: bold;
        }

        .meta .v { word-break: break-word; }

        /* ---------- Detalle ---------- */
        .detalle {
            margin-top: 2mm;
            border-top: 1px solid #000000;
        }

        .detalle th {
            font-size: 9px;
            border-bottom: 1px solid #000000;
            padding: 1mm 0;
        }

        .detalle td {
            padding: 0.4mm 0;
            vertical-align: top;
        }

        .detalle .doc {
            font-weight: bold;
            padding-top: 1.5mm;
            border-bottom: 1px dotted #999999;
        }

        .detalle .concepto {
            padding-left: 2mm;
            word-break: break-word;
        }

        .detalle .r { width: 24mm; white-space: nowrap; }

        .aplicado { font-weight: bold; }

        /* ---------- Totales ---------- */
        .totales {
            margin-top: 2mm;
            border-top: 1px solid #000000;
            padding-top: 1mm;
        }

        .totales td { padding: 0.4mm 0; }
        .totales .r { width: 26mm; white-space: nowrap; }

        .totales .grande td {
            font-size: 13px;
            font-weight: bold;
            padding-top: 1mm;
        }

        .saldo {
            margin-top: 1.5mm;
            border-top: 1px dashed #000000;
            padding-top: 1mm;
            font-weight: bold;
        }

        .saldo .r { width: 26mm; white-space: nowrap; }

        .pie {
            margin-top: 3mm;
            border-top: 1px dashed #000000;
            padding-top: 2mm;
            font-size: 9px;
        }

        /* En pantalla (dentro del modal) se simula el papel para que
           el cajero vea de una vez cómo va a salir. Al imprimir esto
           desaparece: la térmica no tiene sombras ni bordes. */
        @media screen {
            body { background: #E9ECEF; padding: 8px 0; }

            .ticket {
                background: #FFFFFF;
                width: 72mm;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
                padding: 4mm 3mm 6mm;
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>

@foreach($recibos as $recibo)
    @include('gestisp.payments.partials.receipt-thermal-body', [
        'recibo' => $recibo,
        // En HTML el logo se sirve por URL; en el PDF va por ruta de disco.
        'logoSrc' => $recibo['branch']?->image ? asset('storage/' . $recibo['branch']->image) : null,
    ])
@endforeach

</body>
</html>
