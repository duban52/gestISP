<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        /* ============================================================
           HOJA Y NUMERACIÓN

           El pie con el número de página se pinta con `position: fixed`
           en el margen inferior: dompdf repite lo fijo en todas las
           hojas, que es justo lo que hace falta en un manual de treinta
           páginas que alguien va a imprimir y grapar.
           ============================================================ */
        @page { margin: 2.2cm 2cm 2cm 2cm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #1a1a1a;
        }

        /* Los títulos de nivel 1 son las PARTES del manual: cada una
           empieza en hoja nueva. Con el manual corrido, las partes se
           perdían a mitad de página. */
        h1 {
            font-size: 17pt;
            color: #0b4f6c;
            border-bottom: 2px solid #0b4f6c;
            padding-bottom: 5px;
            margin: 0 0 14px 0;
            page-break-before: always;
        }
        /* Menos la primera, que ya está en la portada. */
        h1:first-of-type { page-break-before: avoid; }

        h2 {
            font-size: 13.5pt;
            color: #0b4f6c;
            margin: 20px 0 7px 0;
            page-break-after: avoid;
        }
        h3 {
            font-size: 11.5pt;
            color: #333;
            margin: 14px 0 5px 0;
            page-break-after: avoid;
        }

        p { margin: 0 0 8px 0; text-align: justify; }
        ul, ol { margin: 0 0 8px 0; padding-left: 18px; }
        li { margin-bottom: 3px; }

        /* ============================================================
           TABLAS

           `page-break-inside: avoid` en la fila y no en la tabla: una
           tabla de veinte filas que no se pueda partir se va entera a
           la hoja siguiente y deja media página en blanco. Lo que no
           puede partirse es cada fila.
           ============================================================ */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 14px 0;
            font-size: 9.5pt;
        }
        th {
            background: #0b4f6c;
            color: #fff;
            text-align: left;
            padding: 5px 7px;
            font-weight: bold;
        }
        td {
            padding: 5px 7px;
            border-bottom: 1px solid #d9d9d9;
            vertical-align: top;
        }
        tr { page-break-inside: avoid; }
        tbody tr:nth-child(even) td { background: #f5f8fa; }

        /* Las advertencias del manual, que en Markdown son citas. */
        blockquote {
            margin: 10px 0;
            padding: 8px 12px;
            background: #fff8e1;
            border-left: 4px solid #e6a700;
            page-break-inside: avoid;
        }
        blockquote p:last-child { margin-bottom: 0; }

        code {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9pt;
            background: #eef2f5;
            padding: 1px 3px;
        }
        pre {
            background: #eef2f5;
            padding: 8px 10px;
            font-size: 9pt;
            page-break-inside: avoid;
        }
        pre code { background: none; padding: 0; }

        hr { border: none; border-top: 1px solid #ccc; margin: 18px 0; }
        a { color: #0b4f6c; text-decoration: none; }
        strong { color: #000; }

        /* ---------- Portada ---------- */
        .portada { text-align: center; padding-top: 6cm; page-break-after: always; }
        .portada .marca { font-size: 30pt; font-weight: bold; color: #0b4f6c; }
        .portada .nombre { font-size: 19pt; margin-top: 12px; }
        .portada .fecha { margin-top: 2.5cm; color: #666; font-size: 10pt; }

        /* ---------- Pie ---------- */
        .pie {
            position: fixed;
            bottom: -1.4cm;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #888;
            border-top: 1px solid #ddd;
            padding-top: 3px;
        }
        /* SIN `float` AQUÍ. Un flotante dentro de un bloque fijo hace
           que dompdf desplace el contenido de TODAS las páginas hacia
           la derecha: el texto arrancaba a un tercio de la hoja. Una
           línea centrada dice lo mismo y no rompe nada. */
        .pie { text-align: center; }
    </style>
</head>
<body>

<div class="pie">{{ $titulo }} &nbsp;·&nbsp; gestISP</div>

<div class="portada">
    <div class="marca">gestISP</div>
    <div class="nombre">{{ $titulo }}</div>
    <div class="fecha">{{ $generado }}</div>
</div>

{!! $cuerpo !!}

</body>
</html>
