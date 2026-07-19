{{-- ============================================================
     Plantilla base de los PDFs de GestISP

     Estructura común a todos los reportes y comprobantes
     (excepto las facturas, que conservan su formato fiscal):

       - Encabezado fijo, repetido en TODAS las páginas: logo y
         datos de la sucursal emisora + tipo de documento y fecha
         de generación.
       - Barra de metadatos opcional (período, filtros, usuario).
       - Contenido del reporte.
       - Pie fijo con la marca GestISP y "Página X de Y".

     Notas de dompdf (por qué está escrito así):
       - Los márgenes de @page reservan el espacio del encabezado
         y el pie; los elementos fijos se posicionan DENTRO de ese
         margen con desplazamientos negativos. Así el contenido
         nunca queda debajo de ellos (sin textos sobrepuestos).
       - No hay flexbox ni grid: la maquetación usa tablas.
       - Las tablas de datos usan table-layout fijo y quiebre de
         palabra para que ningún texto largo desborde la celda.
       - <thead> se repite automáticamente en cada página.

     Variables esperadas:
       $pdfTitle     — tipo de documento (ej. "Reporte de pagos")
       $pdfSubtitle  — opcional, bajo el título
       $branch       — sucursal emisora (opcional: usa la de sesión)
       $orientation  — 'portrait' (default) o 'landscape'
     ============================================================ --}}
@php
    use App\Support\PdfBranding;

    $pdfBranch = PdfBranding::branch($branch ?? null);
    $logoPath = PdfBranding::logoPath($pdfBranch);
    $location = PdfBranding::locationLine($pdfBranch);
    $phones = PdfBranding::phoneLine($pdfBranch);
    $isLandscape = ($orientation ?? 'portrait') === 'landscape';
@endphp
    <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $pdfTitle ?? 'Reporte' }} — GestISP</title>
    <style>
        @page {
            /* El margen superior reserva el alto del encabezado y
               el inferior el del pie: el contenido jamás los pisa */
            margin: 122px 34px 78px 34px;
        }

        body {
            /* DejaVu Sans es la fuente incrustada de dompdf con
               soporte completo de acentos y ñ */
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5px;
            color: #222222;
            margin: 0;
        }

        /* ===================== ENCABEZADO ===================== */
        .pdf-header {
            position: fixed;
            top: -104px;
            left: 0;
            right: 0;
            height: 96px;
        }

        .pdf-header table {
            width: 100%;
            border-collapse: collapse;
        }

        .pdf-header td {
            vertical-align: top;
            padding: 0;
        }

        .header-logo {
            width: 78px;
        }

        .header-logo img {
            max-width: 70px;
            max-height: 58px;
        }

        .branch-name {
            font-size: 15px;
            font-weight: bold;
            color: #1F4E79;
            letter-spacing: 0.3px;
        }

        .branch-line {
            font-size: 8.5px;
            color: #555555;
            line-height: 1.45;
        }

        .doc-box {
            width: 210px;
            text-align: right;
        }

        .doc-type {
            display: block;
            background-color: #1F4E79;
            color: #FFFFFF;
            font-size: 10.5px;
            font-weight: bold;
            padding: 5px 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .doc-subtitle {
            display: block;
            font-size: 8.5px;
            color: #555555;
            padding-top: 4px;
            line-height: 1.4;
        }

        .header-rule {
            border-bottom: 2px solid #1F4E79;
            margin-top: 8px;
        }

        /* ======================== PIE ======================== */
        .pdf-footer {
            position: fixed;
            bottom: -62px;
            left: 0;
            right: 0;
            height: 54px;
            border-top: 1px solid #CCCCCC;
            padding-top: 5px;
            font-size: 7.8px;
            color: #777777;
        }

        .pdf-footer table {
            width: 100%;
            border-collapse: collapse;
        }

        .pdf-footer td {
            padding: 0;
            vertical-align: top;
        }

        .footer-brand {
            font-weight: bold;
            color: #1F4E79;
            font-size: 8.5px;
        }

        .footer-right {
            text-align: right;
        }

        /* La paginación "Página X de Y" NO se imprime aquí: la
           estampa App\Support\PdfBranding::make() con la API de
           canvas, porque counter(pages) devuelve 0 dentro de
           elementos de posición fija. Este bloque solo reserva el
           espacio a la derecha del pie. */
        .footer-right { min-height: 10px; }

        /* ==================== METADATOS ==================== */
        .meta-bar {
            background-color: #F1F5FA;
            border-left: 3px solid #1F4E79;
            padding: 6px 9px;
            margin-bottom: 11px;
        }

        .meta-bar table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta-bar td {
            font-size: 8.6px;
            color: #333333;
            padding: 1.5px 10px 1.5px 0;
            vertical-align: top;
        }

        .meta-label {
            color: #666666;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 7.6px;
            letter-spacing: 0.3px;
        }

        /* ================== TÍTULOS DE SECCIÓN ================== */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #1F4E79;
            border-bottom: 1px solid #D5DEE8;
            padding-bottom: 3px;
            margin: 14px 0 7px 0;
        }

        /* ==================== TABLAS DE DATOS ==================== */
        table.data {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed; /* evita que una celda ancha desborde */
            margin-bottom: 6px;
        }

        table.data thead {
            display: table-header-group; /* repite el encabezado por página */
        }

        table.data th {
            background-color: #1F4E79;
            color: #FFFFFF;
            font-size: 8.4px;
            font-weight: bold;
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #1F4E79;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }

        table.data td {
            font-size: 8.6px;
            padding: 4px 6px;
            border: 1px solid #D9DEE4;
            vertical-align: top;
            /* Quiebre de palabras largas (seriales, direcciones):
               sin esto el texto se sale de la celda */
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        table.data tbody tr:nth-child(even) td {
            background-color: #F7F9FB;
        }

        table.data tfoot td {
            font-size: 9px;
            font-weight: bold;
            background-color: #E8EEF6;
            border: 1px solid #C3D0E0;
            padding: 5px 6px;
        }

        .empty-row td {
            text-align: center;
            color: #888888;
            font-style: italic;
            padding: 14px 6px;
        }

        /* ==================== FICHAS / RESUMEN ==================== */
        table.summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.summary td {
            width: 25%;
            border: 1px solid #D9DEE4;
            padding: 7px 9px;
            vertical-align: top;
        }

        .summary-label {
            display: block;
            font-size: 7.6px;
            color: #666666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .summary-value {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: #1F4E79;
        }

        /* ==================== FICHA DE DATOS ==================== */
        table.detail {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        table.detail td {
            font-size: 9px;
            padding: 3.5px 6px;
            border-bottom: 1px solid #EDF0F3;
            vertical-align: top;
        }

        table.detail td.label {
            width: 130px;
            color: #666666;
            font-weight: bold;
        }

        /* ==================== UTILIDADES ==================== */
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .nowrap { white-space: nowrap; }
        .strong { font-weight: bold; }
        .muted { color: #777777; }
        .positive { color: #1E7B34; font-weight: bold; }
        .negative { color: #B32020; font-weight: bold; }
        .note {
            font-size: 8.2px;
            color: #666666;
            background-color: #FAFBFC;
            border: 1px solid #E4E8EC;
            padding: 6px 8px;
            margin-top: 10px;
        }
        .signature-area {
            margin-top: 34px;
            width: 100%;
        }
        .signature-area td {
            width: 50%;
            padding-top: 26px;
            font-size: 8.4px;
            color: #555555;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #999999;
            padding-top: 4px;
            margin: 0 22px;
        }
    </style>
</head>
<body>

{{-- ================= ENCABEZADO (todas las páginas) ================= --}}
<div class="pdf-header">
    <table>
        <tr>
            @if($logoPath)
                <td class="header-logo">
                    <img src="{{ $logoPath }}" alt="Logo">
                </td>
            @endif
            <td>
                <span class="branch-name">{{ $pdfBranch->name ?? 'GestISP' }}</span>
                <div class="branch-line">
                    @if($pdfBranch?->nit)NIT {{ $pdfBranch->nit }}<br>@endif
                    @if($pdfBranch?->address){{ $pdfBranch->address }}@endif
                    @if($location) — {{ $location }}@endif
                    @if($phones)<br>Tel: {{ $phones }}@endif
                </div>
            </td>
            <td class="doc-box">
                <span class="doc-type">{{ $pdfTitle ?? 'Reporte' }}</span>
                <span class="doc-subtitle">
                    @isset($pdfSubtitle){{ $pdfSubtitle }}<br>@endisset
                    Generado: {{ now()->format('d/m/Y h:i a') }}
                </span>
            </td>
        </tr>
    </table>
    <div class="header-rule"></div>
</div>

{{-- ==================== PIE (todas las páginas) ==================== --}}
<div class="pdf-footer">
    <table>
        <tr>
            <td>
                <span class="footer-brand">GestISP</span> — Sistema de gestión para proveedores de Internet<br>
                {{-- El usuario se arma en PHP: una directiva Blade pegada
                     a una palabra (texto@if) no se compila --}}
                @php
                    $generatedBy = auth()->check()
                        ? ' por ' . auth()->user()->name . ' ' . auth()->user()->last_name
                        : '';
                @endphp
                Documento generado automáticamente{{ $generatedBy }}.
                Este reporte refleja la información registrada en el sistema al momento de su generación.
            </td>
            {{-- La paginación la estampa PdfBranding sobre esta
                 zona; aquí solo va la fecha de generación --}}
            <td class="footer-right" style="width: 130px;">
                <br>{{ now()->format('d/m/Y H:i') }}
            </td>
        </tr>
    </table>
</div>

{{-- ========================= CONTENIDO ========================= --}}
@hasSection('meta')
    <div class="meta-bar">
        <table>
            @yield('meta')
        </table>
    </div>
@endif

@yield('content')

</body>
</html>
