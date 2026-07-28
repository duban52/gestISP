{{-- ============================================================
     Cortes masivos de servicio (PPPoE)

     Dos pasos que no se pueden saltar: primero se REVISA contra la
     base de datos y se ve a quién se va a cortar, y solo después se
     EJECUTA. Un corte masivo deja a decenas de clientes sin
     servicio y no tiene botón de deshacer; ver los nombres antes de
     confirmar es lo que evita cortar a la lista equivocada.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cortes masivos')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-user-slash mr-2"></i>Cortes masivos de servicio</h1>
@endsection

@section('content')

    <div class="callout callout-warning">
        <p class="mb-1">
            Cortar significa <strong>deshabilitar la cuenta PPPoE y tumbar la conexión activa</strong>:
            el cliente deja de navegar de inmediato.
        </p>
        <p class="mb-0">
            Puede indicar <strong>números de contrato</strong> (ENG000123) o <strong>usuarios PPPoE</strong>,
            mezclados. Máximo {{ number_format($maximo, 0, ',', '.') }} por tanda.
        </p>
    </div>

    {{-- ============================================================
         Paso 1: la lista
         ============================================================ --}}
    <div class="card card-outline card-primary shadow-sm" id="cardEntrada">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-list-ol mr-1"></i> 1. Indique a quién cortar</h3>
        </div>

        <div class="card-body">
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#tabPegar" role="tab">
                        <i class="fas fa-keyboard mr-1"></i> Escribir o pegar
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#tabArchivo" role="tab">
                        <i class="fas fa-file-upload mr-1"></i> Subir archivo
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tabPegar" role="tabpanel">
                    <div class="form-group mb-2">
                        <label for="lista">Uno por línea</label>
                        <textarea id="lista" class="form-control text-monospace" rows="10"
                                  placeholder="ENG000001&#10;ENG000058&#10;pepito.perez&#10;..."></textarea>
                        <small class="form-text text-muted">
                            También se aceptan separados por comas o punto y coma. Los repetidos se descartan solos.
                        </small>
                    </div>
                    <div id="contadorLista" class="text-muted small"></div>
                </div>

                <div class="tab-pane fade" id="tabArchivo" role="tabpanel">
                    <div class="form-group">
                        <label for="archivo">Archivo con la lista</label>
                        <input type="file" id="archivo" class="form-control-file"
                               accept=".txt,.csv,.xlsx,.xls">
                        <small class="form-text text-muted">
                            Formatos: <code>.txt</code>, <code>.csv</code>, <code>.xlsx</code>, <code>.xls</code>
                            (máximo 5 MB).
                        </small>
                    </div>

                    <div class="alert alert-light border">
                        <strong>Cómo se lee el archivo</strong>
                        <ul class="mb-0 mt-1 pl-3" style="font-size: .9rem;">
                            <li>
                                Si la primera fila trae un encabezado reconocible
                                (<code>contrato</code>, <code>usuario</code>, <code>pppoe</code>…),
                                se usa esa columna.
                            </li>
                            <li>
                                Si no, se usa la <strong>primera columna</strong> desde la fila uno
                                — que es como llega un <code>.txt</code> pelado.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-footer text-right">
            <button type="button" class="btn btn-primary" id="btnRevisar">
                <i class="fas fa-search"></i> Revisar la lista
            </button>
        </div>
    </div>

    <div class="alert alert-danger d-none" id="errorEntrada"></div>

    {{-- ============================================================
         Paso 2: la revisión
         ============================================================ --}}
    <div class="card card-outline card-warning shadow-sm d-none" id="cardRevision">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0"><i class="fas fa-clipboard-check mr-1"></i> 2. Revise antes de cortar</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnVolver">
                <i class="fas fa-arrow-left"></i> Cambiar la lista
            </button>
        </div>

        <div class="card-body">
            {{-- Resumen por estado --}}
            <div class="row text-center mb-3" id="resumen"></div>

            <div class="table-responsive">
                <table class="table table-sm table-hover" id="tablaRevision">
                    <thead class="thead-light">
                    <tr>
                        <th style="width: 160px;">Se buscó</th>
                        <th style="width: 150px;">Estado</th>
                        <th>Cuenta PPPoE</th>
                        <th>Contrato</th>
                        <th>Cliente</th>
                        <th>Router</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

        <div class="card-footer">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div class="text-muted" id="avisoCorte"></div>
                <button type="button" class="btn btn-danger btn-lg" id="btnEjecutar">
                    <i class="fas fa-user-slash"></i> Ejecutar corte
                </button>
            </div>
        </div>
    </div>

    {{-- ---------- Confirmación ---------- --}}
    <div class="modal fade" id="modalConfirmar" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle mr-1"></i> Confirmar el corte</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p id="textoConfirmar" class="mb-2"></p>
                    <p class="text-muted mb-0">
                        Se deshabilitará la cuenta en el router y se tumbará la conexión activa.
                        <strong>Los clientes dejarán de navegar de inmediato.</strong>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarCorte">
                        <i class="fas fa-user-slash"></i> Sí, cortar
                    </button>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('css')
    <style>
        #lista { font-size: .95rem; line-height: 1.5; }

        .estado-badge { font-size: .8rem; }

        /* Fila atenuada: nada que hacer con ella */
        tr.sin-accion { opacity: .62; }

        .resumen-caja {
            border-radius: .35rem;
            padding: .6rem .3rem;
            border: 1px solid rgba(0, 0, 0, .08);
        }

        .resumen-caja .numero { font-size: 1.5rem; font-weight: 700; line-height: 1; }
        .resumen-caja .texto { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }

        .dark-mode .resumen-caja { border-color: rgba(255, 255, 255, .12); }
        .dark-mode .alert-light.border { background-color: #454d55; border-color: rgba(255,255,255,.12) !important; }
    </style>
@endsection

@section('js')
    <script>
        /* ============================================================
           Cortes masivos

           El navegador nunca decide a quién se corta: manda la LISTA
           DE IDENTIFICADORES y el servidor la vuelve a resolver
           contra la base. Así lo que se corta no depende de lo que
           se pueda manipular aquí.
           ============================================================ */
        (function () {
            'use strict';

            const TOKEN = $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}';

            // Identificadores tal como los normalizó el servidor
            let identificadores = [];

            /** Cómo se pinta cada estado devuelto por el servidor. */
            const ESTADOS = {
                lista:         { color: 'success',   texto: 'Se cortará',      accion: true },
                ya_suspendida: { color: 'secondary', texto: 'Ya suspendida',   accion: false },
                sin_cuenta:    { color: 'warning',   texto: 'Sin cuenta PPPoE', accion: false },
                otra_sucursal: { color: 'warning',   texto: 'Otra sucursal',   accion: false },
                no_encontrado: { color: 'danger',    texto: 'No encontrado',   accion: false },
                cortada:       { color: 'success',   texto: 'Cortada',         accion: false },
                error:         { color: 'danger',    texto: 'Error',           accion: false },
            };

            /* ---------------- Contador en vivo ---------------- */

            $('#lista').on('input', function () {
                const n = ($(this).val() || '')
                    .split(/[\r\n,;\t]+/)
                    .map(s => s.trim())
                    .filter(Boolean).length;

                $('#contadorLista').text(n === 0 ? '' : n + ' línea(s) escritas');
            });

            /* ---------------- Paso 1: revisar ---------------- */

            $('#btnRevisar').on('click', function () {
                const $boton = $(this);
                const archivo = document.getElementById('archivo').files[0];

                const datos = new FormData();
                datos.append('_token', TOKEN);

                // El archivo manda si está cargado; si no, el texto.
                if (archivo) {
                    datos.append('archivo', archivo);
                } else {
                    datos.append('lista', $('#lista').val() || '');
                }

                $boton.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm"></span> Revisando…'
                );
                $('#errorEntrada').addClass('d-none').text('');

                $.ajax({
                    url: '{{ route('pppoe.cutoff.preview') }}',
                    method: 'POST',
                    data: datos,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function (respuesta) {
                        identificadores = respuesta.identificadores;
                        pintarRevision(respuesta.filas, respuesta.resumen, false);

                        $('#cardEntrada').addClass('d-none');
                        $('#cardRevision').removeClass('d-none');
                        $('html, body').animate({ scrollTop: $('#cardRevision').offset().top - 60 }, 250);
                    },
                    error: mostrarError,
                    complete: function () {
                        $boton.prop('disabled', false).html('<i class="fas fa-search"></i> Revisar la lista');
                    },
                });
            });

            $('#btnVolver').on('click', function () {
                $('#cardRevision').addClass('d-none');
                $('#cardEntrada').removeClass('d-none');
            });

            /* ---------------- Pintado de la revisión ---------------- */

            function pintarRevision(filas, resumen, ejecutado) {
                pintarResumen(resumen, ejecutado);

                const $cuerpo = $('#tablaRevision tbody').empty();

                filas.forEach(function (fila) {
                    const estado = ESTADOS[fila.estado] || { color: 'secondary', texto: fila.estado, accion: false };

                    if (fila.cuentas.length === 0) {
                        // Sin cuentas que mostrar: una sola fila con el motivo
                        $cuerpo.append(
                            '<tr class="sin-accion">' +
                            '  <td><code>' + escapar(fila.identificador) + '</code></td>' +
                            '  <td><span class="badge badge-' + estado.color + ' estado-badge">' + estado.texto + '</span></td>' +
                            '  <td colspan="4" class="text-muted">' + escapar(fila.mensaje) + '</td>' +
                            '</tr>'
                        );
                        return;
                    }

                    fila.cuentas.forEach(function (cuenta, i) {
                        // El estado de cada cuenta manda cuando ya se ejecutó
                        const suEstado = cuenta.resultado
                            ? (ESTADOS[cuenta.resultado === 'cortada' ? 'cortada' : 'error'])
                            : estado;

                        $cuerpo.append(
                            '<tr class="' + (suEstado.accion || cuenta.resultado === 'cortada' ? '' : 'sin-accion') + '">' +
                            '  <td>' + (i === 0 ? '<code>' + escapar(fila.identificador) + '</code>' : '') + '</td>' +
                            '  <td><span class="badge badge-' + suEstado.color + ' estado-badge">' + suEstado.texto + '</span>' +
                            (cuenta.error ? '<small class="d-block text-danger">' + escapar(cuenta.error) + '</small>' : '') +
                            '  </td>' +
                            '  <td><strong>' + escapar(cuenta.username) + '</strong></td>' +
                            '  <td>' + escapar(cuenta.contrato || '—') + '</td>' +
                            '  <td>' + escapar(cuenta.cliente || '—') + '</td>' +
                            '  <td>' + escapar(cuenta.router || '—') + '</td>' +
                            '</tr>'
                        );
                    });
                });
            }

            function pintarResumen(resumen, ejecutado) {
                const cajas = ejecutado
                    ? [
                        { n: resumen.cortadas, t: 'Cortadas', c: 'success' },
                        { n: resumen.errores, t: 'Con error', c: 'danger' },
                      ]
                    : [
                        { n: resumen.cuentas, t: 'Se cortarán', c: 'success' },
                        { n: resumen.ya_suspendida, t: 'Ya suspendidas', c: 'secondary' },
                        { n: resumen.sin_cuenta, t: 'Sin cuenta', c: 'warning' },
                        { n: resumen.otra_sucursal, t: 'Otra sucursal', c: 'warning' },
                        { n: resumen.no_encontrado, t: 'No encontrados', c: 'danger' },
                      ];

                const $fila = $('#resumen').empty();

                cajas.forEach(function (caja) {
                    $fila.append(
                        '<div class="col">' +
                        '  <div class="resumen-caja">' +
                        '    <div class="numero text-' + caja.c + '">' + caja.n + '</div>' +
                        '    <div class="texto text-muted">' + caja.t + '</div>' +
                        '  </div>' +
                        '</div>'
                    );
                });

                if (ejecutado) {
                    $('#avisoCorte').html('<i class="fas fa-check-circle text-success"></i> Corte ejecutado.');
                    $('#btnEjecutar').addClass('d-none');
                    return;
                }

                // Sin nada que cortar, el botón no debe estar disponible
                const hay = resumen.cuentas > 0;

                $('#btnEjecutar').toggleClass('d-none', !hay).prop('disabled', !hay);

                $('#avisoCorte').html(hay
                    ? '<i class="fas fa-exclamation-triangle text-warning"></i> Se cortará el servicio de <strong>' +
                      resumen.cuentas + ' cuenta(s)</strong>. Las demás filas no se tocan.'
                    : '<i class="fas fa-info-circle"></i> No hay ninguna cuenta activa por cortar en esta lista.');
            }

            /* ---------------- Paso 2: ejecutar ---------------- */

            $('#btnEjecutar').on('click', function () {
                const total = $('#resumen .numero').first().text();

                $('#textoConfirmar').html(
                    'Está a punto de cortar el servicio de <strong>' + total + ' cuenta(s)</strong>.'
                );
                $('#modalConfirmar').modal('show');
            });

            $('#btnConfirmarCorte').on('click', function () {
                const $boton = $(this);

                $boton.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm"></span> Cortando…'
                );

                $.ajax({
                    url: '{{ route('pppoe.cutoff.execute') }}',
                    method: 'POST',
                    data: { _token: TOKEN, identificadores: identificadores },
                    dataType: 'json',
                    success: function (respuesta) {
                        $('#modalConfirmar').modal('hide');

                        pintarRevision(respuesta.filas, {
                            cortadas: respuesta.cortadas,
                            errores: respuesta.errores,
                        }, true);

                        $('#errorEntrada')
                            .removeClass('d-none alert-danger')
                            .addClass(respuesta.errores > 0 ? 'alert-warning' : 'alert-success')
                            .text(respuesta.mensaje);

                        $('#btnVolver').html('<i class="fas fa-redo"></i> Hacer otro corte');
                    },
                    error: function (xhr) {
                        $('#modalConfirmar').modal('hide');
                        mostrarError(xhr);
                    },
                    complete: function () {
                        $boton.prop('disabled', false).html('<i class="fas fa-user-slash"></i> Sí, cortar');
                    },
                });
            });

            /* ---------------- Utilidades ---------------- */

            function mostrarError(xhr) {
                let mensaje = 'No se pudo procesar la lista.';

                if (xhr.responseJSON) {
                    if (xhr.responseJSON.error) {
                        mensaje = xhr.responseJSON.error;
                    } else if (xhr.responseJSON.errors) {
                        mensaje = Object.values(xhr.responseJSON.errors).flat().join(' ');
                    }
                }

                $('#errorEntrada')
                    .removeClass('d-none alert-success alert-warning')
                    .addClass('alert-danger')
                    .text(mensaje);
            }

            /** Los datos vienen de la base, pero se escapan igual. */
            function escapar(valor) {
                return $('<div>').text(valor === null || valor === undefined ? '' : valor).html();
            }
        })();
    </script>
@endsection
