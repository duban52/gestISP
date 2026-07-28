{{-- ============================================================
     Gestión de caja

     Dos estados muy distintos en una sola pantalla:

       - SIN CAJA ABIERTA: lo único que importa es abrirla, así que
         el formulario es el protagonista y nada más compite con él.
       - CON CAJA ABIERTA: el cajero necesita saber de un vistazo
         cuánto debería tener y de qué forma le pagaron, y poder
         cerrar haciendo el arqueo.

     El desglose por método de pago no es decoración: el esperado en
     caja suma todos los métodos, pero en el cajón SOLO está el
     efectivo. Sin separarlos, quien cuenta el dinero reporta un
     faltante por cada transferencia que recibió.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Gestión de caja')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-cash-register mr-2"></i>Gestión de caja</h1>
@endsection

@section('content')

    @php
        $money = fn ($v) => '$' . number_format((float) $v, 2, ',', '.');

        // Se calcula desde los movimientos que ya están cargados, no
        // desde las columnas del registro: así lo que se muestra
        // siempre cuadra con la lista de abajo.
        $ingresos = (float) $movimientos->where('transaction_type', 'Ingreso')->sum('amount');
        $egresos = (float) $movimientos->where('transaction_type', 'Egreso')->sum('amount');
        $base = (float) ($caja->initial_amount ?? 0);
        $esperado = $base + $ingresos - $egresos;

        // Lo que debería estar FÍSICAMENTE en el cajón: la base más
        // los movimientos en efectivo. El resto llegó por tarjeta o
        // transferencia y nunca pasó por ahí.
        $efectivo = $porMetodo->filter(fn ($d, $metodo) => str_contains(strtolower($metodo), 'efectivo'));
        $esperadoEfectivo = $base
            + (float) $efectivo->sum('ingresos')
            - (float) $efectivo->sum('egresos');
        $noEfectivo = round($esperado - $esperadoEfectivo, 2);
    @endphp

    @if(!$caja)
        {{-- ============================================================
             SIN CAJA ABIERTA
             ============================================================ --}}
        <div class="row justify-content-center">
            <div class="col-12 col-lg-8">

                <div class="card card-outline card-secondary shadow-sm">
                    <div class="card-body text-center py-4">
                        <div class="estado-icono estado-cerrado mb-3">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h4 class="mb-1">No tienes una caja abierta</h4>
                        <p class="text-muted mb-0">
                            Ningún cobro será aceptado hasta que abras tu caja.
                            Declara con cuánto dinero empiezas el turno.
                        </p>
                    </div>
                </div>

                <div class="card card-outline card-success shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-play-circle mr-1"></i> Abrir caja</h3>
                    </div>
                    <form id="formAbrir">
                        <div class="card-body">
                            <div class="form-group">
                                <label for="initial_amount">Base inicial <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">$</span>
                                    </div>
                                    <input type="number" step="0.01" min="0" id="initial_amount"
                                           name="initial_amount" class="form-control" value="0" required autofocus>
                                </div>
                                <small class="form-text text-muted">
                                    Dinero con el que arranca el turno para dar cambio. Si no dejas base, escribe 0.
                                </small>

                                {{-- Atajos: las bases habituales de un punto
                                     de cobro, para no teclear. --}}
                                <div class="mt-2">
                                    @foreach([0, 50000, 100000, 200000, 500000] as $atajo)
                                        <button type="button" class="btn btn-outline-secondary btn-sm mr-1 mb-1 atajo-base"
                                                data-valor="{{ $atajo }}">
                                            ${{ number_format($atajo, 0, ',', '.') }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="form-group mb-0">
                                <label for="opening_notes">Notas de apertura</label>
                                <textarea id="opening_notes" name="opening_notes" class="form-control" rows="2"
                                          placeholder="Opcional: novedades del turno anterior, faltantes por revisar..."></textarea>
                            </div>
                        </div>
                        <div class="card-footer text-right">
                            <button type="submit" class="btn btn-success btn-lg" id="btnAbrir">
                                <i class="fas fa-lock-open"></i> Abrir caja
                            </button>
                        </div>
                    </form>
                </div>

                @if($ultimoCierre)
                    {{-- Contexto: qué pasó con el turno anterior --}}
                    <div class="card shadow-sm">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between align-items-center flex-wrap">
                                <span class="text-muted">
                                    <i class="fas fa-history mr-1"></i>
                                    Tu último cierre: caja #{{ $ultimoCierre->id }},
                                    {{ $ultimoCierre->closed_at?->diffForHumans() }}
                                </span>
                                <span>
                                    Contado {{ $money($ultimoCierre->final_amount) }}
                                    @if((float) $ultimoCierre->difference == 0.0)
                                        <span class="badge badge-success ml-1">Cuadró</span>
                                    @elseif((float) $ultimoCierre->difference > 0)
                                        <span class="badge badge-warning ml-1">
                                            Sobrante {{ $money($ultimoCierre->difference) }}
                                        </span>
                                    @else
                                        <span class="badge badge-danger ml-1">
                                            Faltante {{ $money(abs((float) $ultimoCierre->difference)) }}
                                        </span>
                                    @endif
                                </span>
                            </div>
                        </div>
                    </div>
                @endif

            </div>
        </div>

    @else
        {{-- ============================================================
             CAJA ABIERTA
             ============================================================ --}}

        <div class="card card-outline card-success shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div class="d-flex align-items-center">
                        <div class="estado-icono estado-abierto mr-3">
                            <i class="fas fa-lock-open"></i>
                        </div>
                        <div>
                            <h4 class="mb-0">Caja #{{ $caja->id }} abierta</h4>
                            <span class="text-muted">
                                {{ trim(($caja->user->name ?? '') . ' ' . ($caja->user->last_name ?? '')) }}
                                · desde {{ $caja->opened_at?->format('d/m/Y h:i a') }}
                                ({{ $caja->opened_at?->diffForHumans(null, true) }} de turno)
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 mt-md-0">
                        <a href="{{ route('payments.searchView') }}" class="btn btn-primary">
                            <i class="fas fa-hand-holding-usd"></i> Ir a cobrar
                        </a>
                        <button type="button" class="btn btn-danger" id="btnAbrirArqueo">
                            <i class="fas fa-lock"></i> Cerrar caja
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ---------- Cifras del turno ---------- --}}
        <div class="row">
            <div class="col-6 col-lg-3">
                <div class="info-box shadow-sm">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-wallet"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Base inicial</span>
                        <span class="info-box-number">{{ $money($base) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="info-box shadow-sm">
                    <span class="info-box-icon bg-success"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Ingresos</span>
                        <span class="info-box-number">{{ $money($ingresos) }}</span>
                        <span class="text-muted" style="font-size:.75rem;">
                            {{ $movimientos->where('transaction_type', 'Ingreso')->count() }} movimiento(s)
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="info-box shadow-sm">
                    <span class="info-box-icon bg-warning"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Egresos</span>
                        <span class="info-box-number">{{ $money($egresos) }}</span>
                        <span class="text-muted" style="font-size:.75rem;">
                            {{ $movimientos->where('transaction_type', 'Egreso')->count() }} movimiento(s)
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="info-box shadow-sm bg-gradient-primary">
                    <span class="info-box-icon"><i class="fas fa-calculator"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Esperado en caja</span>
                        <span class="info-box-number">{{ $money($esperado) }}</span>
                        <span style="font-size:.75rem; opacity:.85;">base + ingresos − egresos</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            {{-- ---------- Desglose por método ---------- --}}
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-header py-2">
                        <h3 class="card-title"><i class="fas fa-layer-group mr-1"></i> Por método de pago</h3>
                    </div>
                    <div class="card-body py-2">
                        @forelse($porMetodo as $metodo => $datos)
                            <div class="d-flex justify-content-between align-items-center py-2 metodo-fila">
                                <span>
                                    <i class="fas fa-circle mr-1"
                                       style="font-size:.6rem; color: {{ str_contains(strtolower($metodo), 'efectivo') ? '#28a745' : '#6c757d' }};"></i>
                                    {{ ucfirst($metodo) }}
                                    <small class="text-muted d-block ml-3">{{ $datos['movimientos'] }} movimiento(s)</small>
                                </span>
                                <strong>{{ $money($datos['ingresos'] - $datos['egresos']) }}</strong>
                            </div>
                        @empty
                            <p class="text-muted mb-0 py-3 text-center">
                                Todavía no se ha registrado ningún movimiento en este turno.
                            </p>
                        @endforelse

                        @if($noEfectivo > 0)
                            {{-- La advertencia que evita el falso faltante --}}
                            <div class="alert alert-info mb-0 mt-2 py-2">
                                <i class="fas fa-info-circle"></i>
                                En el cajón solo debe haber <strong>{{ $money($esperadoEfectivo) }}</strong>.
                                Los otros {{ $money($noEfectivo) }} entraron por tarjeta o transferencia
                                y no pasaron por caja.
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ---------- Últimos movimientos ---------- --}}
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><i class="fas fa-list mr-1"></i> Movimientos del turno</h3>
                        {{-- Al registro de pagos, no a movimientos de caja:
                             lo que el cajero busca al salir de aquí son los
                             cobros con su cliente y su recibo reimprimible,
                             no el libro de entradas y salidas del cajón. --}}
                        <a href="{{ route('payments.index') }}" class="btn btn-xs btn-outline-secondary">
                            Ver todos
                        </a>
                    </div>
                    <div class="card-body p-0" style="max-height: 340px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0">
                            <tbody>
                            @forelse($movimientos->take(15) as $movimiento)
                                <tr>
                                    <td style="width: 78px;" class="text-muted small align-middle">
                                        {{ $movimiento->created_at->format('h:i a') }}
                                    </td>
                                    <td class="align-middle">
                                        {{ $movimiento->descripcionLegible() }}
                                        @php
                                            $detalleCliente = $movimiento->detalleDelCliente();
                                        @endphp
                                        @if($detalleCliente)
                                            <small class="d-block text-muted">{{ $detalleCliente }}</small>
                                        @endif
                                    </td>
                                    <td class="text-right align-middle text-nowrap">
                                        <span class="{{ $movimiento->transaction_type === 'Egreso' ? 'text-danger' : 'text-success' }}">
                                            {{ $movimiento->transaction_type === 'Egreso' ? '−' : '+' }}{{ $money($movimiento->amount) }}
                                        </span>
                                        <small class="d-block text-muted">{{ $movimiento->payment_method }}</small>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="text-center text-muted py-4">
                                        Sin movimientos todavía.
                                        <a href="{{ route('payments.searchView') }}">Registrar un cobro</a>.
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($movimientos->count() > 15)
                        <div class="card-footer py-1 text-center text-muted small">
                            Mostrando los 15 más recientes de {{ $movimientos->count() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ============================================================
             Arqueo de cierre

             El contador de denominaciones es lo que hace la
             diferencia: sumar de cabeza un cajón lleno de billetes es
             donde se producen los descuadres que después nadie sabe
             explicar. Aquí se cuentan cantidades y el total sale solo.
             ============================================================ --}}
        <div class="modal fade" id="modalArqueo" tabindex="-1" role="dialog" data-backdrop="static">
            <div class="modal-dialog modal-xl" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-lock mr-1"></i> Cerrar caja — arqueo</h5>
                        <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                    </div>

                    <div class="modal-body">
                        <div class="row">

                            {{-- Contador de billetes y monedas --}}
                            <div class="col-12 col-lg-7">
                                <h6 class="text-uppercase text-muted mb-2">Cuente el efectivo del cajón</h6>

                                <div class="row">
                                    <div class="col-6">
                                        <div class="denom-titulo">Billetes</div>
                                        @foreach([100000, 50000, 20000, 10000, 5000, 2000] as $valor)
                                            <div class="denom-fila">
                                                <span class="denom-valor">${{ number_format($valor, 0, ',', '.') }}</span>
                                                <input type="number" min="0" step="1" class="form-control form-control-sm denom"
                                                       data-valor="{{ $valor }}" placeholder="0">
                                                <span class="denom-subtotal">—</span>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="col-6">
                                        <div class="denom-titulo">Monedas</div>
                                        @foreach([1000, 500, 200, 100, 50] as $valor)
                                            <div class="denom-fila">
                                                <span class="denom-valor">${{ number_format($valor, 0, ',', '.') }}</span>
                                                <input type="number" min="0" step="1" class="form-control form-control-sm denom"
                                                       data-valor="{{ $valor }}" placeholder="0">
                                                <span class="denom-subtotal">—</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                    <span class="h6 mb-0">Efectivo contado</span>
                                    <span class="h5 mb-0 text-success"><strong id="totalContado">$0,00</strong></span>
                                </div>
                                <small class="text-muted">
                                    Opcional: si prefiere, escriba el total directamente en el campo de la derecha.
                                </small>
                            </div>

                            {{-- Resultado del arqueo --}}
                            <div class="col-12 col-lg-5 mt-3 mt-lg-0">
                                <div class="card bg-light mb-2">
                                    <div class="card-body py-2">
                                        <table class="table table-sm mb-0">
                                            <tr>
                                                <td>Esperado en caja</td>
                                                <td class="text-right"><strong>{{ $money($esperado) }}</strong></td>
                                            </tr>
                                            @if($noEfectivo > 0)
                                                <tr class="text-muted">
                                                    <td class="pl-3">· en efectivo</td>
                                                    <td class="text-right">{{ $money($esperadoEfectivo) }}</td>
                                                </tr>
                                                <tr class="text-muted">
                                                    <td class="pl-3">· tarjeta / transferencia</td>
                                                    <td class="text-right">{{ $money($noEfectivo) }}</td>
                                                </tr>
                                            @endif
                                        </table>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="final_amount">Total declarado al cierre <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-lg">
                                        <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                        <input type="number" step="0.01" min="0" id="final_amount"
                                               class="form-control" value="{{ $esperado }}" required>
                                    </div>
                                    @if($noEfectivo > 0)
                                        <small class="form-text text-muted">
                                            Se completa solo: efectivo contado + {{ $money($noEfectivo) }} de tarjeta
                                            y transferencia, que no están en el cajón.
                                        </small>
                                    @else
                                        <small class="form-text text-muted">Se completa solo con lo que cuente arriba.</small>
                                    @endif
                                </div>

                                {{-- La diferencia, en vivo: se ve ANTES de
                                     confirmar, no en el comprobante. --}}
                                <div id="cajaDiferencia" class="alert mb-2 py-2 text-center"></div>

                                <div class="form-group mb-0">
                                    <label for="closing_notes">Notas de cierre</label>
                                    <textarea id="closing_notes" class="form-control" rows="2"
                                              placeholder="Explique aquí cualquier diferencia"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="alert alert-danger mt-3 d-none" id="errorArqueo"></div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-danger" id="btnConfirmarCierre">
                            <i class="fas fa-lock"></i> Confirmar cierre
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ---------- Comprobante de cierre ---------- --}}
    <div class="modal fade" id="modalComprobante" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle mr-1"></i> Caja cerrada</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="pdfIframe" src="" style="width:100%; height:520px; border:0;"></iframe>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" id="btnCerrarComprobante">Cerrar</button>
                    <div>
                        <a id="downloadPdf" class="btn btn-outline-primary" href="#" target="_blank">
                            <i class="fas fa-download"></i> Guardar PDF
                        </a>
                        <button type="button" class="btn btn-primary" id="printPdf">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('css')
    <style>
        /* Círculo de estado: comunica abierto/cerrado antes de leer */
        .estado-icono {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .estado-abierto { background: #d4edda; color: #1e7e34; }
        .estado-cerrado { background: #e9ecef; color: #6c757d; }

        .estado-icono.estado-cerrado { margin: 0 auto; }

        .metodo-fila + .metodo-fila { border-top: 1px solid rgba(0, 0, 0, .06); }

        /* ---------- Contador de denominaciones ---------- */
        .denom-titulo {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #6c757d;
            margin-bottom: .35rem;
        }

        .denom-fila {
            display: flex;
            align-items: center;
            gap: .4rem;
            margin-bottom: .3rem;
        }

        .denom-fila .denom-valor {
            width: 72px;
            font-variant-numeric: tabular-nums;
            font-size: .85rem;
            text-align: right;
            flex-shrink: 0;
        }

        .denom-fila input { width: 62px; text-align: center; }

        .denom-fila .denom-subtotal {
            flex: 1;
            font-size: .8rem;
            color: #6c757d;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        /* ---------- Modo oscuro ---------- */
        .dark-mode .estado-abierto { background: #1e4620; color: #7bd88f; }
        .dark-mode .estado-cerrado { background: #2c3136; color: #adb5bd; }
        .dark-mode .metodo-fila + .metodo-fila { border-top-color: rgba(255, 255, 255, .08); }
        .dark-mode .denom-titulo,
        .dark-mode .denom-fila .denom-subtotal { color: #adb5bd; }
        .dark-mode .card.bg-light { background-color: #454d55 !important; }
    </style>
@endsection

@section('js')
    <script>
        /* ============================================================
           Gestión de caja
           ============================================================ */
        (function () {
            'use strict';

            const pesos = new Intl.NumberFormat('es-CO', {
                style: 'currency', currency: 'COP',
                minimumFractionDigits: 2, maximumFractionDigits: 2,
            });

            const TOKEN = $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}';

            /* ---------------- Apertura ---------------- */

            $('.atajo-base').on('click', function () {
                $('#initial_amount').val($(this).data('valor')).focus();
            });

            $('#formAbrir').on('submit', function (e) {
                e.preventDefault();

                const $boton = $('#btnAbrir');

                $boton.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm"></span> Abriendo…'
                );

                $.ajax({
                    url: '{{ route('cash_register.open') }}',
                    method: 'POST',
                    data: {
                        _token: TOKEN,
                        initial_amount: $('#initial_amount').val(),
                        opening_notes: $('#opening_notes').val(),
                    },
                    success: function () {
                        // La pantalla se arma en el servidor: recargar
                        // es más simple y más seguro que redibujarla.
                        window.location.reload();
                    },
                    error: function (xhr) {
                        $boton.prop('disabled', false).html('<i class="fas fa-lock-open"></i> Abrir caja');
                        alert(xhr.responseJSON?.error || 'No se pudo abrir la caja.');
                    },
                });
            });

            /* ---------------- Arqueo ---------------- */

            // Lo que el sistema dice que debería haber
            const ESPERADO = {{ $esperado ?? 0 }};
            // Lo que llegó por tarjeta o transferencia: no está en el
            // cajón, así que no se cuenta pero sí se suma al declarado.
            const NO_EFECTIVO = {{ $noEfectivo ?? 0 }};

            $('#btnAbrirArqueo').on('click', function () {
                $('#errorArqueo').addClass('d-none').text('');
                pintarDiferencia();
                $('#modalArqueo').modal('show');
            });

            /** Suma las denominaciones contadas y completa el declarado. */
            function totalizarDenominaciones() {
                let efectivo = 0;

                $('.denom').each(function () {
                    const cantidad = parseInt($(this).val(), 10) || 0;
                    const valor = Number($(this).data('valor'));
                    const subtotal = cantidad * valor;

                    efectivo += subtotal;

                    $(this).closest('.denom-fila').find('.denom-subtotal')
                        .text(subtotal > 0 ? pesos.format(subtotal) : '—');
                });

                $('#totalContado').text(pesos.format(efectivo));

                // El declarado es el efectivo contado MÁS lo que entró
                // por otros medios: el comprobante compara el total.
                $('#final_amount').val((efectivo + NO_EFECTIVO).toFixed(2));

                pintarDiferencia();
            }

            $(document).on('input', '.denom', totalizarDenominaciones);
            $('#final_amount').on('input', pintarDiferencia);

            /** Muestra sobrante/faltante en vivo, antes de confirmar. */
            function pintarDiferencia() {
                const declarado = Number($('#final_amount').val() || 0);
                const diferencia = Math.round((declarado - ESPERADO) * 100) / 100;

                const $caja = $('#cajaDiferencia').removeClass('alert-success alert-warning alert-danger');

                if (diferencia === 0) {
                    $caja.addClass('alert-success').html(
                        '<i class="fas fa-check-circle"></i> <strong>La caja cuadra.</strong>'
                    );
                } else if (diferencia > 0) {
                    $caja.addClass('alert-warning').html(
                        '<i class="fas fa-arrow-up"></i> <strong>Sobrante de ' + pesos.format(diferencia) + '</strong>' +
                        '<br><small>Hay más dinero del esperado. Explíquelo en las notas.</small>'
                    );
                } else {
                    $caja.addClass('alert-danger').html(
                        '<i class="fas fa-arrow-down"></i> <strong>Faltante de ' + pesos.format(Math.abs(diferencia)) + '</strong>' +
                        '<br><small>Falta dinero. Verifique el conteo antes de cerrar.</small>'
                    );
                }
            }

            $('#btnConfirmarCierre').on('click', function () {
                const $boton = $(this);
                const declarado = Number($('#final_amount').val() || 0);
                const diferencia = Math.round((declarado - ESPERADO) * 100) / 100;
                const notas = $('#closing_notes').val().trim();

                // Una diferencia sin explicación es justo lo que nadie
                // logra reconstruir después.
                if (diferencia !== 0 && notas === '') {
                    $('#errorArqueo').removeClass('d-none').text(
                        'La caja no cuadra. Explique la diferencia en las notas de cierre antes de confirmar.'
                    );
                    $('#closing_notes').focus();
                    return;
                }

                $boton.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm"></span> Cerrando…'
                );

                $.ajax({
                    url: '{{ route('cash_register.close') }}',
                    method: 'POST',
                    data: {
                        _token: TOKEN,
                        final_amount: declarado,
                        closing_notes: notas,
                    },
                    success: function (respuesta) {
                        // El comprobante se abre CUANDO el arqueo ya
                        // terminó de cerrarse: encadenar hide+show deja
                        // el fondo oscuro encima y bloquea la pantalla.
                        $('#modalArqueo').one('hidden.bs.modal', function () {
                            $('#pdfIframe').attr('src', respuesta.pdf_url);
                            $('#downloadPdf').attr('href', respuesta.pdf_url);
                            $('#modalComprobante').modal('show');
                        });

                        $('#modalArqueo').modal('hide');
                    },
                    error: function (xhr) {
                        $boton.prop('disabled', false).html('<i class="fas fa-lock"></i> Confirmar cierre');
                        $('#errorArqueo').removeClass('d-none').text(
                            xhr.responseJSON?.error || 'No se pudo cerrar la caja.'
                        );
                    },
                });
            });

            /* ---------------- Comprobante ---------------- */

            $('#btnCerrarComprobante').on('click', function () {
                $('#modalComprobante').modal('hide');
            });

            // Al cerrarlo se recarga: ya no hay caja abierta
            $('#modalComprobante').on('hidden.bs.modal', function () {
                window.location.reload();
            });

            $('#printPdf').on('click', function () {
                const marco = document.getElementById('pdfIframe');

                if (marco && marco.contentWindow) {
                    marco.contentWindow.focus();
                    marco.contentWindow.print();
                }
            });
        })();
    </script>
@endsection
