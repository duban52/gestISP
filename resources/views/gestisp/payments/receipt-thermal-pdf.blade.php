{{-- ============================================================
     Recibo de caja — versión PDF (tirilla de 80 mm)

     Mismo contenido que la versión HTML, con el CSS que dompdf sí
     entiende. Diferencias que obligan a tener dos hojas de estilo:

       - dompdf no soporta @page { size: auto }: el alto lo fija
         PaymentReceipt::pdf() al construir el documento.
       - No hay milímetros fiables en dompdf para anchos de tabla;
         se trabaja en puntos y porcentajes.
       - El logo debe ser una RUTA DE DISCO, no una URL: si el
         servidor no se alcanza a sí mismo por HTTP, la imagen sale
         rota.

     Variables: $recibos (arreglo de App\Support\PaymentReceipt::build)
     ============================================================ --}}
    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de caja</title>
    <style>
        @page { margin: 0; }

        body {
            margin: 0;
            padding: 0;
            font-family: "DejaVu Sans Mono", monospace;
            font-size: 7px;
            line-height: 1.35;
            color: #000000;
        }

        .ticket {
            /* 80 mm = 226,77 pt; se dejan ~8 pt de margen a cada lado */
            width: 210pt;
            margin: 0 auto;
            padding: 8pt 0 12pt;
            page-break-after: always;
        }

        .ticket:last-child { page-break-after: auto; }

        .center { text-align: center; }
        .l { text-align: left; }
        .r { text-align: right; }

        .logo { max-width: 100pt; max-height: 50pt; margin-bottom: 4pt; }

        .empresa { font-size: 10px; font-weight: bold; }
        .titulo { font-size: 9px; font-weight: bold; margin: 6pt 0 4pt; }

        .subtitulo {
            font-weight: bold;
            margin-top: 4pt;
            border-top: 1px dashed #000000;
            padding-top: 2pt;
        }

        .small { font-size: 6px; }

        table { width: 100%; border-collapse: collapse; }

        .meta td { vertical-align: top; padding: 0.5pt 0; }
        .meta .k { width: 60pt; font-weight: bold; }
        .meta .v { word-wrap: break-word; }

        .detalle { margin-top: 4pt; border-top: 1px solid #000000; }
        .detalle th { font-size: 6px; border-bottom: 1px solid #000000; padding: 2pt 0; }
        .detalle td { padding: 0.8pt 0; vertical-align: top; }

        .detalle .doc {
            font-weight: bold;
            padding-top: 3pt;
            border-bottom: 1px dotted #999999;
        }

        .detalle .concepto { padding-left: 5pt; word-wrap: break-word; }
        .detalle .r { width: 65pt; }

        .aplicado { font-weight: bold; }

        .totales { margin-top: 4pt; border-top: 1px solid #000000; padding-top: 2pt; }
        .totales td { padding: 0.8pt 0; }
        .totales .r { width: 70pt; }
        .totales .grande td { font-size: 10px; font-weight: bold; padding-top: 2pt; }

        .saldo {
            margin-top: 3pt;
            border-top: 1px dashed #000000;
            padding-top: 2pt;
            font-weight: bold;
        }

        .saldo .r { width: 70pt; }

        .pie {
            margin-top: 6pt;
            border-top: 1px dashed #000000;
            padding-top: 4pt;
            font-size: 6px;
        }
    </style>
</head>
<body>

@foreach($recibos as $recibo)
    @include('gestisp.payments.partials.receipt-thermal-body', [
        'recibo' => $recibo,
        'logoSrc' => \App\Support\PdfBranding::logoPath($recibo['branch']),
    ])
@endforeach

</body>
</html>
