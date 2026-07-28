{{-- ============================================================
     Pantalla de cobro

     Tres cosas conviven aquí:

       1. El buscador de facturas abiertas (por cédula, nombre,
          número de contrato, factura, teléfono, PPPoE o dirección).
       2. Un CARRITO DE COBRO que sobrevive a las búsquedas: se
          guarda en sessionStorage, de modo que el cajero puede
          buscar a la mamá, marcar su factura, buscar a la abuela,
          marcar la suya, y cobrarlas todas de una vez. Es el caso
          real de quien llega a pagar por varios familiares.
       3. Un único modal de cobro que sirve para una factura o para
          veinte. Si hay un solo contrato se cobra por la ruta
          normal; si hay varios, por la de cobro múltiple (que
          además pide los datos de quien paga, porque no es el
          titular).

     El recibo se muestra al terminar dentro del mismo modal, en un
     iframe con la tirilla de 80 mm lista para imprimir.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Cobrar')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-hand-holding-usd mr-2"></i>Cobrar</h1>
@endsection

@section('content')

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Estado de la caja: todo cobro exige caja abierta, así que se
         recuerda ANTES de buscar.
         ============================================================ --}}
    @if($activeCashRegister ?? null)
        <div class="alert alert-success d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-cash-register"></i>
                <strong>Caja #{{ $activeCashRegister->id }} abierta</strong>
                desde {{ $activeCashRegister->opened_at->format('d/m/Y h:i a') }}
                — base inicial ${{ number_format($activeCashRegister->initial_amount, 2) }}
            </span>
            <span class="badge badge-success" style="font-size: 0.95rem;">Listo para cobrar</span>
        </div>
    @else
        <div class="alert alert-danger d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-exclamation-triangle"></i>
                <strong>No tienes una caja abierta.</strong>
                Ningún cobro será aceptado hasta que abras tu caja.
            </span>
            <a href="{{ route('cashRegisters.index') }}" class="btn btn-info btn-sm">
                <i class="fas fa-cash-register"></i> Ir a Gestión de caja
            </a>
        </div>
    @endif

    {{-- ---------- Buscador ---------- --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <form method="GET" action="{{ route('payments.searchView') }}">
                <div class="row align-items-end">
                    <div class="col-md-3 mt-1 mb-1">
                        <label for="search_field" class="mb-1">Buscar por</label>
                        <select name="search_field" id="search_field" class="form-control">
                            <option value="all" {{ request('search_field', 'all') == 'all' ? 'selected' : '' }}>Todos los campos</option>
                            <option value="identity" {{ request('search_field') == 'identity' ? 'selected' : '' }}>Identificación del cliente</option>
                            <option value="name" {{ request('search_field') == 'name' ? 'selected' : '' }}>Nombre del cliente</option>
                            <option value="contract" {{ request('search_field') == 'contract' ? 'selected' : '' }}>Número de contrato</option>
                            <option value="invoice" {{ request('search_field') == 'invoice' ? 'selected' : '' }}>Número de factura</option>
                            <option value="phone" {{ request('search_field') == 'phone' ? 'selected' : '' }}>Teléfono</option>
                            <option value="pppoe" {{ request('search_field') == 'pppoe' ? 'selected' : '' }}>Usuario PPPoE</option>
                            <option value="address" {{ request('search_field') == 'address' ? 'selected' : '' }}>Dirección / barrio</option>
                        </select>
                    </div>
                    <div class="col-md-4 mt-1 mb-1">
                        <label for="search_term" class="mb-1">Criterio</label>
                        <input
                            type="text"
                            id="search_term"
                            name="search_term"
                            class="form-control"
                            placeholder="Cédula, nombre, contrato, factura, teléfono..."
                            value="{{ request('search_term') }}"
                            autofocus>
                    </div>
                    <div class="col-md-2 mt-1 mb-1">
                        <label for="per_page" class="mb-1">Por página</label>
                        <select name="per_page" id="per_page" class="form-control">
                            <option value="8" {{ request('per_page') == 8 ? 'selected' : '' }}>8</option>
                            <option value="15" {{ request('per_page') == 15 ? 'selected' : '' }}>15</option>
                            <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>25</option>
                            <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-center text-md-right mt-1 mb-1">
                        <button type="submit" class="btn btn-primary" title="Buscar">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                        <a href="{{ route('payments.searchView') }}" class="btn btn-secondary" title="Limpiar búsqueda">
                            <i class="fas fa-eraser"></i>
                        </a>
                    </div>
                </div>
                <small class="form-text text-muted">
                    Ejemplos: <code>12345678</code> (cédula) · <code>Juan Rodríguez</code> (nombre) ·
                    <code>{{ $activeCashRegister?->branch?->contract_prefix ?? 'ENG' }}000123</code> (contrato) ·
                    <code>FAC1-25</code> (factura) · <code>pepito.perez</code> (usuario PPPoE)
                </small>
                <small class="form-text text-info">
                    <i class="fas fa-lightbulb"></i>
                    ¿Le van a pagar el servicio de varias personas? Marque las facturas de cada una,
                    busque a la siguiente y siga marcando: la selección se conserva entre búsquedas.
                </small>
            </form>
        </div>
    </div>

    @if(isset($invoices) && $invoices->isNotEmpty())
        <div class="alert alert-warning d-flex justify-content-between align-items-center">
            <span>
                <i class="fas fa-file-invoice-dollar"></i>
                <strong>{{ $resultCount }}</strong> factura(s) abierta(s) encontrada(s)
            </span>
            <span class="h5 mb-0">
                Deuda total: <strong>${{ number_format($totalBalance, 2) }}</strong>
            </span>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="resultsTable">
                    <thead class="thead-light">
                    <tr>
                        <th style="width: 38px;" class="text-center">
                            <input type="checkbox" id="checkAll" title="Marcar todas las de esta página">
                        </th>
                        <th>Factura</th>
                        <th>Cliente</th>
                        <th>Contrato</th>
                        <th>Detalle del cobro</th>
                        <th>Vence</th>
                        <th>Estado</th>
                        <th class="text-right">Saldo</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($invoices as $invoice)
                        @php
                            $cliente = $invoice->contract?->client;
                            $saldo = $invoice->getPendingAmount();
                            // Detalle de lo que se está cobrando: los
                            // conceptos de la factura. Si es una factura
                            // vieja sin ítems se muestra su tipo.
                            $conceptos = $invoice->invoice_items->map(fn ($i) => [
                                'descripcion' => $i->description,
                                'valor' => (float) $i->total,
                            ])->values();
                        @endphp
                        <tr data-invoice-id="{{ $invoice->id }}"
                            class="{{ $invoice->status === \App\Billing\Enums\InvoiceStatus::Vencida->value ? 'table-danger' : '' }}">
                            <td class="text-center align-middle">
                                {{-- Los data-* llevan todo lo que el carrito
                                     necesita: así funciona sin volver al servidor. --}}
                                <input type="checkbox"
                                       class="pick-invoice"
                                       data-id="{{ $invoice->id }}"
                                       data-numero="{{ $invoice->displayNumber() }}"
                                       data-cliente="{{ trim(($cliente->name ?? '') . ' ' . ($cliente->last_name ?? '')) ?: 'Sin cliente' }}"
                                       data-documento="{{ $cliente->identity_number ?? '' }}"
                                       data-contrato-id="{{ $invoice->contract_id }}"
                                       data-contrato="{{ $invoice->contract?->numero_visible ?? '—' }}"
                                       data-periodo="{{ trim(($invoice->billed_month_name ?? '') . ' ' . ($invoice->billed_period_short ?? '')) }}"
                                       data-saldo="{{ $saldo }}"
                                       data-total="{{ $invoice->total }}"
                                       data-subtotal="{{ $invoice->subtotal }}"
                                       data-iva="{{ round((float) $invoice->total - (float) $invoice->subtotal, 2) }}">
                            </td>
                            <td class="align-middle"><strong>{{ $invoice->displayNumber() }}</strong></td>
                            <td class="align-middle">
                                {{ $cliente->name ?? 'N/A' }} {{ $cliente->last_name ?? '' }}
                                <small class="d-block text-muted">
                                    {{ $cliente->type_document ?? 'CC' }} {{ $cliente->identity_number ?? '—' }}
                                </small>
                            </td>
                            <td class="align-middle">
                                {{-- Número de contrato, que es lo que el
                                     cliente tiene impreso. El id interno no
                                     le dice nada a nadie en el mostrador. --}}
                                <strong>{{ $invoice->contract?->numero_visible ?? '—' }}</strong>
                                @if($invoice->contract?->address)
                                    <small class="d-block text-muted">{{ $invoice->contract->address }}</small>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-light border">
                                    {{ trim(($invoice->billed_month_name ?? '') . ' ' . ($invoice->billed_period_short ?? '')) ?: $invoice->billed_period }}
                                </span>
                                <ul class="list-unstyled mb-0 mt-1" style="font-size: .82rem;">
                                    @forelse($conceptos as $concepto)
                                        <li class="d-flex justify-content-between" style="max-width: 320px;">
                                            <span class="text-muted mr-2">{{ $concepto['descripcion'] }}</span>
                                            <span>${{ number_format($concepto['valor'], 0, ',', '.') }}</span>
                                        </li>
                                    @empty
                                        <li class="text-muted">{{ $invoice->type ?: 'Servicio' }}</li>
                                    @endforelse
                                    <li class="d-flex justify-content-between border-top pt-1 mt-1" style="max-width: 320px;">
                                        <strong class="mr-2">Total factura</strong>
                                        <strong>${{ number_format($invoice->total, 0, ',', '.') }}</strong>
                                    </li>
                                </ul>
                            </td>
                            <td class="align-middle">{{ $invoice->due_date->format('d/m/Y') }}</td>
                            <td class="align-middle">
                                @switch($invoice->status)
                                    @case(\App\Billing\Enums\InvoiceStatus::Vencida->value)
                                        <span class="badge badge-danger">Vencida</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteRiesgoCorte->value)
                                        <span class="badge badge-danger">Riesgo de corte</span>
                                        @break
                                    @case(\App\Billing\Enums\InvoiceStatus::PendienteParcial->value)
                                        <span class="badge badge-info">Abonada</span>
                                        @break
                                    @default
                                        <span class="badge badge-warning">{{ $invoice->status }}</span>
                                @endswitch
                            </td>
                            <td class="text-right align-middle saldo-cell">
                                <strong>${{ number_format($saldo, 2) }}</strong>
                            </td>
                            <td class="text-center align-middle">
                                @if($saldo > 0)
                                    <button class="btn btn-success btn-sm btn-cobrar-ya" data-id="{{ $invoice->id }}">
                                        <i class="fas fa-hand-holding-usd"></i> Cobrar
                                    </button>
                                @else
                                    <span class="badge badge-success">Pagada</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="text-center mt-3">
            {{ $invoices->links() }}
        </div>
    @elseif(request()->filled('search_term'))
        <div class="alert alert-info mt-3">
            No se encontraron facturas abiertas con ese criterio.
            Verifique el término o pruebe con "Todos los campos".
        </div>
    @endif

    {{-- Espacio para que la barra fija no tape el final de la tabla --}}
    <div id="cartSpacer" style="height: 0;"></div>

    {{-- ============================================================
         Barra del carrito: aparece cuando hay algo seleccionado y se
         queda fija abajo, para que el cajero no la pierda de vista al
         hacer scroll o cambiar de búsqueda.
         ============================================================ --}}
    <div id="cartBar" class="d-none">
        <div class="cart-bar-inner">
            <div class="cart-summary">
                <i class="fas fa-shopping-basket mr-1"></i>
                <strong id="cartCount">0</strong> factura(s) ·
                <strong id="cartContracts">0</strong> contrato(s)
                <span class="cart-total ml-3">Total: <strong id="cartTotal">$0</strong></span>
            </div>
            <div>
                <button type="button" class="btn btn-outline-light btn-sm mr-1" id="cartClear">
                    <i class="fas fa-times"></i> Vaciar
                </button>
                <button type="button" class="btn btn-success" id="cartCheckout">
                    <i class="fas fa-cash-register"></i> Cobrar seleccionadas
                </button>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de cobro. Sirve igual para una factura o para varias:
         lo único que cambia es que con más de un contrato pide los
         datos de quien está pagando.
         ============================================================ --}}
    <div class="modal fade" id="checkoutModal" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-cash-register mr-1"></i> Registrar cobro
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="modal-body">

                    {{-- Datos de quien paga: solo se piden cuando el
                         cobro abarca varios contratos, porque ahí quien
                         entrega el dinero no es el titular y el recibo
                         debe dejarlo constando. --}}
                    <div id="payerBlock" class="card card-outline card-info d-none">
                        <div class="card-header py-2">
                            <h3 class="card-title" style="font-size: 1rem;">
                                <i class="fas fa-user-friends mr-1"></i> ¿Quién está pagando?
                            </h3>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <div class="col-md-5 form-group mb-2">
                                    <label class="mb-1">Nombre de quien paga</label>
                                    <input type="text" id="payer_name" class="form-control form-control-sm"
                                           placeholder="Ej.: María Restrepo (hija)">
                                </div>
                                <div class="col-md-4 form-group mb-2">
                                    <label class="mb-1">Documento</label>
                                    <input type="text" id="payer_document" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3 form-group mb-2">
                                    <label class="mb-1">Teléfono</label>
                                    <input type="text" id="payer_phone" class="form-control form-control-sm">
                                </div>
                            </div>
                            <small class="text-muted">
                                Queda impreso en cada recibo. Cada contrato recibe el suyo por separado.
                            </small>
                        </div>
                    </div>

                    {{-- Líneas del cobro --}}
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-2" id="checkoutTable">
                            <thead class="thead-light">
                            <tr>
                                <th>Factura</th>
                                <th>Cliente / contrato</th>
                                <th class="text-right" style="width: 130px;">Saldo</th>
                                <th style="width: 160px;">A pagar</th>
                                <th class="text-right" style="width: 130px;">Retenciones</th>
                                <th style="width: 105px;"></th>
                            </tr>
                            </thead>
                            <tbody id="checkoutLines"></tbody>
                        </table>
                    </div>

                    {{-- Forma de pago y totales --}}
                    <div class="row">
                        <div class="col-md-7">
                            <div class="row">
                                <div class="col-md-5 form-group mb-2">
                                    <label class="mb-1">Método de pago</label>
                                    <select id="payment_method" class="form-control">
                                        <option value="Efectivo">Efectivo</option>
                                        <option value="Tarjeta">Tarjeta</option>
                                        <option value="Transferencia">Transferencia</option>
                                    </select>
                                </div>
                                <div class="col-md-7 form-group mb-2">
                                    <label class="mb-1">Número de referencia</label>
                                    <input type="text" id="reference_number" class="form-control"
                                           placeholder="Comprobante, voucher, transferencia...">
                                </div>
                                <div class="col-12 form-group mb-0">
                                    <label class="mb-1">Notas</label>
                                    <textarea id="notes" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5">
                            <div class="card bg-light mb-0">
                                <div class="card-body py-2">
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td>Total a aplicar</td>
                                            <td class="text-right"><strong id="sumApplied">$0</strong></td>
                                        </tr>
                                        <tr id="rowRetained" class="d-none">
                                            <td class="text-warning">(−) Retenciones</td>
                                            <td class="text-right text-warning"><strong id="sumRetained">$0</strong></td>
                                        </tr>
                                        <tr class="border-top">
                                            <td class="h5 mb-0">A recibir</td>
                                            <td class="text-right h5 mb-0">
                                                <strong class="text-success" id="sumCash">$0</strong>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <small class="text-muted d-block mt-1" id="retentionHint"></small>
                        </div>
                    </div>

                    <div class="alert alert-danger mt-3 d-none" id="checkoutError"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="confirmCheckout">
                        <i class="fas fa-check"></i> Confirmar cobro
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Editor de retenciones de UNA línea del cobro.

         Es un modal aparte y reutilizable: se abre indicando sobre
         qué factura trabaja y devuelve sus líneas al carrito. Así el
         mismo editor sirve para un cobro simple y para cada factura
         de un cobro múltiple.
         ============================================================ --}}
    <div class="modal fade" id="retentionModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-percent mr-1"></i> Aplicar retenciones</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body">
                    <div class="callout callout-info py-2">
                        <p class="mb-1" style="font-size: .9rem;">
                            Cuando el cliente es <strong>agente de retención</strong>, descuenta un
                            porcentaje del pago y lo consigna a la DIAN o al municipio
                            <strong>a nombre de la empresa</strong>.
                        </p>
                        <p class="mb-0" style="font-size: .9rem;">
                            La factura queda pagada igual; lo que baja es el efectivo que entra a la caja.
                            Copie los valores <strong>del certificado de retención</strong> que entrega el cliente.
                        </p>
                    </div>

                    <div class="mb-2 small text-muted" id="retentionContext"></div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-2">
                            <thead class="thead-light">
                            <tr>
                                <th style="width: 150px;">Tipo</th>
                                <th>Concepto</th>
                                <th style="width: 130px;">Base</th>
                                <th style="width: 95px;">Tarifa %</th>
                                <th style="width: 130px;">Valor</th>
                                <th style="width: 140px;">Certificado</th>
                                <th style="width: 40px;"></th>
                            </tr>
                            </thead>
                            <tbody id="retentionLines"></tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-outline-info btn-sm" id="addRetention">
                        <i class="fas fa-plus"></i> Agregar retención
                    </button>

                    <div class="float-right">
                        Total retenido:
                        <strong class="h5 text-warning" id="retentionTotal">$0</strong>
                    </div>

                    <div class="alert alert-danger mt-3 d-none" id="retentionError"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-info" id="saveRetentions">
                        <i class="fas fa-check"></i> Aplicar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Recibo: se ve DENTRO de la página, en un iframe con la
         tirilla real. Imprimir manda el HTML a la impresora (la
         térmica corta donde termina el contenido); guardar descarga
         el PDF.
         ============================================================ --}}
    <div class="modal fade" id="receiptModal" tabindex="-1" role="dialog" data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 420px;">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle mr-1"></i> Cobro registrado</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>

                <div class="modal-body p-0">
                    <div class="px-3 pt-3">
                        <div class="alert alert-success mb-2 py-2" id="receiptSummary"></div>
                    </div>
                    {{-- El iframe muestra la tirilla tal cual va a salir --}}
                    <iframe id="receiptFrame" title="Recibo de caja"
                            style="width: 100%; height: 420px; border: 0; border-top: 1px solid #dee2e6;"></iframe>
                </div>

                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-secondary" id="receiptClose">Cerrar</button>
                    <div>
                        <a href="#" class="btn btn-outline-primary" id="receiptDownload" target="_blank">
                            <i class="fas fa-download"></i> Guardar PDF
                        </a>
                        <button type="button" class="btn btn-primary" id="receiptPrint">
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
        /* ---------- Barra fija del carrito ---------- */
        #cartBar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1030;
            background: #343a40;
            color: #fff;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, .25);
        }

        #cartBar .cart-bar-inner {
            max-width: 1400px;
            margin: 0 auto;
            padding: .6rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem;
        }

        #cartBar .cart-total { font-size: 1.05rem; }

        /* La fila marcada se resalta para no perderla de vista al
           hacer scroll en un listado largo. */
        tr.picked { background-color: #e8f5e9 !important; }

        .dark-mode tr.picked { background-color: #245b2c !important; }
        .dark-mode #cartBar { background: #14181c; }

        /* Los importes editables del modal, alineados a la derecha:
           una columna de cifras se lee mucho mejor así. */
        #checkoutLines input.amount-input { text-align: right; }
    </style>
@endsection

@section('js')
    <script>
        /* ============================================================
           Cobro: carrito, retenciones y recibo

           El estado vive en un solo objeto (Carrito). Todo lo demás
           —la barra de abajo, el modal, los totales— se redibuja a
           partir de él. Así no hay dos fuentes de verdad que se
           puedan desincronizar.
           ============================================================ */
        (function () {
            'use strict';

            // Catálogo DIAN de retenciones (tipos, conceptos y tarifas)
            const CATALOGO = @json($retentionCatalog ?? []);

            const RUTAS = {
                simple: @json(route('payments.store')),
                lote: @json(route('payments.storeBatch')),
            };

            const CLAVE_CARRITO = 'gestisp.cobro.carrito';

            const pesos = new Intl.NumberFormat('es-CO', {
                style: 'currency',
                currency: 'COP',
                minimumFractionDigits: 0,
                maximumFractionDigits: 0,
            });

            /* ---------------------------------------------------------
               Estado
               --------------------------------------------------------- */

            /**
             * Facturas seleccionadas, indexadas por id.
             *
             * Se guarda en sessionStorage y no en memoria porque cada
             * búsqueda recarga la página: sin persistir, marcar la
             * factura de la mamá y luego buscar a la abuela perdería
             * lo primero, que es justo lo que hay que evitar.
             */
            const Carrito = {
                items: {},

                cargar() {
                    try {
                        this.items = JSON.parse(sessionStorage.getItem(CLAVE_CARRITO)) || {};
                    } catch (e) {
                        this.items = {};
                    }
                },

                guardar() {
                    sessionStorage.setItem(CLAVE_CARRITO, JSON.stringify(this.items));
                },

                agregar(datos) {
                    // Si ya estaba, se conserva lo que el cajero haya
                    // escrito (monto y retenciones).
                    if (!this.items[datos.id]) {
                        this.items[datos.id] = Object.assign({
                            monto: datos.saldo,
                            retenciones: [],
                        }, datos);
                    }
                    this.guardar();
                },

                quitar(id) {
                    delete this.items[id];
                    this.guardar();
                },

                vaciar() {
                    this.items = {};
                    this.guardar();
                },

                lista() {
                    return Object.values(this.items);
                },

                tiene(id) {
                    return Object.prototype.hasOwnProperty.call(this.items, id);
                },

                contratos() {
                    return new Set(this.lista().map(i => i.contrato_id)).size;
                },

                /** Suma de lo que se va a aplicar a las facturas. */
                totalAplicado() {
                    return this.lista().reduce((t, i) => t + Number(i.monto || 0), 0);
                },

                /** Suma de todas las retenciones de todas las líneas. */
                totalRetenido() {
                    return this.lista().reduce((t, i) => t + totalDeRetenciones(i.retenciones), 0);
                },
            };

            function totalDeRetenciones(lineas) {
                return (lineas || []).reduce((t, r) => t + Number(r.amount || 0), 0);
            }

            /* ---------------------------------------------------------
               Selección en la tabla de resultados
               --------------------------------------------------------- */

            function datosDeCheckbox($chk) {
                return {
                    id: String($chk.data('id')),
                    numero: $chk.data('numero'),
                    cliente: $chk.data('cliente'),
                    documento: $chk.data('documento'),
                    contrato_id: String($chk.data('contrato-id')),
                    contrato: $chk.data('contrato'),
                    periodo: $chk.data('periodo'),
                    saldo: Number($chk.data('saldo')),
                    total: Number($chk.data('total')),
                    subtotal: Number($chk.data('subtotal')),
                    iva: Number($chk.data('iva')),
                };
            }

            /** Marca en la tabla las filas que ya están en el carrito. */
            function sincronizarTabla() {
                $('.pick-invoice').each(function () {
                    const seleccionada = Carrito.tiene(String($(this).data('id')));
                    $(this).prop('checked', seleccionada);
                    $(this).closest('tr').toggleClass('picked', seleccionada);
                });
            }

            function pintarBarra() {
                const n = Carrito.lista().length;

                $('#cartBar').toggleClass('d-none', n === 0);
                // Se reserva el alto de la barra para que no tape la
                // última fila de la tabla.
                $('#cartSpacer').css('height', n === 0 ? 0 : '70px');

                $('#cartCount').text(n);
                $('#cartContracts').text(Carrito.contratos());
                $('#cartTotal').text(pesos.format(Carrito.totalAplicado()));
            }

            $(document).on('change', '.pick-invoice', function () {
                const id = String($(this).data('id'));

                if (this.checked) {
                    Carrito.agregar(datosDeCheckbox($(this)));
                } else {
                    Carrito.quitar(id);
                }

                $(this).closest('tr').toggleClass('picked', this.checked);
                pintarBarra();
            });

            $('#checkAll').on('change', function () {
                $('.pick-invoice').prop('checked', this.checked).trigger('change');
            });

            $('#cartClear').on('click', function () {
                Carrito.vaciar();
                sincronizarTabla();
                pintarBarra();
            });

            /* ---------------------------------------------------------
               Modal de cobro
               --------------------------------------------------------- */

            /** Cobrar una sola factura: se agrega y se abre el modal. */
            $(document).on('click', '.btn-cobrar-ya', function () {
                const $chk = $('.pick-invoice[data-id="' + $(this).data('id') + '"]');

                Carrito.agregar(datosDeCheckbox($chk));
                sincronizarTabla();
                pintarBarra();
                abrirCheckout();
            });

            $('#cartCheckout').on('click', abrirCheckout);

            function abrirCheckout() {
                if (Carrito.lista().length === 0) {
                    return;
                }

                pintarCheckout();
                $('#checkoutError').addClass('d-none').text('');
                $('#checkoutModal').modal('show');
            }

            function pintarCheckout() {
                const $cuerpo = $('#checkoutLines').empty();

                Carrito.lista().forEach(function (item) {
                    const retenido = totalDeRetenciones(item.retenciones);

                    $cuerpo.append(
                        '<tr data-id="' + item.id + '">' +
                        '  <td><strong>' + item.numero + '</strong>' +
                        '      <small class="d-block text-muted">' + (item.periodo || '') + '</small></td>' +
                        '  <td>' + item.cliente +
                        '      <small class="d-block text-muted">Contrato ' + item.contrato + '</small></td>' +
                        '  <td class="text-right">' + pesos.format(item.saldo) + '</td>' +
                        '  <td>' +
                        '    <input type="number" class="form-control form-control-sm amount-input"' +
                        '           step="0.01" min="0" max="' + item.saldo + '" value="' + item.monto + '">' +
                        '  </td>' +
                        '  <td class="text-right">' +
                        (retenido > 0
                            ? '<span class="badge badge-warning">' + pesos.format(retenido) + '</span>'
                            : '<span class="text-muted">—</span>') +
                        '  </td>' +
                        '  <td class="text-right">' +
                        '    <button type="button" class="btn btn-outline-info btn-sm btn-retenciones"' +
                        '            title="Aplicar retenciones"><i class="fas fa-percent"></i></button>' +
                        '    <button type="button" class="btn btn-outline-danger btn-sm btn-quitar"' +
                        '            title="Quitar del cobro"><i class="fas fa-trash"></i></button>' +
                        '  </td>' +
                        '</tr>'
                    );
                });

                // Los datos de quien paga solo tienen sentido cuando el
                // cobro abarca contratos de varias personas.
                $('#payerBlock').toggleClass('d-none', Carrito.contratos() < 2);

                recalcularTotales();
            }

            function recalcularTotales() {
                const aplicado = Carrito.totalAplicado();
                const retenido = Carrito.totalRetenido();
                const efectivo = aplicado - retenido;

                $('#sumApplied').text(pesos.format(aplicado));
                $('#sumRetained').text(pesos.format(retenido));
                $('#sumCash').text(pesos.format(efectivo));
                $('#rowRetained').toggleClass('d-none', retenido <= 0);

                $('#retentionHint').text(retenido > 0
                    ? 'De lo aplicado, ' + pesos.format(retenido) + ' los consigna el cliente al Estado: ' +
                      'a la caja entran ' + pesos.format(efectivo) + '.'
                    : '');

                // Una línea en cero no la acepta el servidor y haría
                // fallar el lote completo; se avisa antes de enviarlo.
                const enCero = Carrito.lista().filter(i => Number(i.monto || 0) <= 0);

                let problema = null;

                if (efectivo < 0) {
                    problema = 'Las retenciones superan el valor que se está aplicando. Revise las líneas.';
                } else if (enCero.length > 0) {
                    problema = 'La factura ' + enCero[0].numero + ' quedó en cero. ' +
                        'Escriba un valor o quítela del cobro.';
                }

                if (problema) {
                    mostrarError(problema);
                } else {
                    limpiarError();
                }

                $('#confirmCheckout').prop('disabled', problema !== null || aplicado <= 0);

                pintarBarra();
            }

            $(document).on('input', '#checkoutLines .amount-input', function () {
                const $fila = $(this).closest('tr');
                const item = Carrito.items[$fila.data('id')];

                if (!item) {
                    return;
                }

                let valor = Number($(this).val() || 0);

                // El tope es el saldo de la factura: cobrar de más
                // dejaría la factura sobrepagada y el servidor lo
                // rechazaría de todos modos.
                if (valor > item.saldo) {
                    valor = item.saldo;
                    $(this).val(valor);
                }

                item.monto = valor;
                Carrito.guardar();
                recalcularTotales();
            });

            $(document).on('click', '#checkoutLines .btn-quitar', function () {
                const $fila = $(this).closest('tr');

                Carrito.quitar($fila.data('id'));
                sincronizarTabla();

                if (Carrito.lista().length === 0) {
                    $('#checkoutModal').modal('hide');
                    pintarBarra();
                    return;
                }

                pintarCheckout();
            });

            /* ---------------------------------------------------------
               Editor de retenciones
               --------------------------------------------------------- */

            // Factura sobre la que está trabajando el editor
            let facturaEnEdicion = null;

            $(document).on('click', '#checkoutLines .btn-retenciones', function () {
                const id = $(this).closest('tr').data('id');

                facturaEnEdicion = Carrito.items[id];

                if (!facturaEnEdicion) {
                    return;
                }

                $('#retentionContext').html(
                    'Factura <strong>' + facturaEnEdicion.numero + '</strong> · ' +
                    facturaEnEdicion.cliente + ' · valor a aplicar ' +
                    pesos.format(facturaEnEdicion.monto)
                );

                $('#retentionLines').empty();
                (facturaEnEdicion.retenciones || []).forEach(agregarFilaRetencion);

                if (($('#retentionLines tr').length) === 0) {
                    agregarFilaRetencion();
                }

                $('#retentionError').addClass('d-none').text('');
                totalizarRetenciones();
                $('#retentionModal').modal('show');
            });

            $('#addRetention').on('click', function () {
                agregarFilaRetencion();
            });

            /** Añade una fila al editor, opcionalmente con datos. */
            function agregarFilaRetencion(datos) {
                datos = datos || {};

                let opcionesTipo = '<option value="">Seleccione…</option>';

                Object.keys(CATALOGO).forEach(function (clave) {
                    opcionesTipo += '<option value="' + clave + '"' +
                        (datos.type === clave ? ' selected' : '') + '>' +
                        CATALOGO[clave].short + '</option>';
                });

                const $fila = $(
                    '<tr>' +
                    '  <td><select class="form-control form-control-sm ret-type">' + opcionesTipo + '</select></td>' +
                    '  <td><select class="form-control form-control-sm ret-concept"></select>' +
                    '      <small class="text-muted ret-help"></small></td>' +
                    '  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm ret-base"' +
                    '             value="' + (datos.base || '') + '"></td>' +
                    '  <td><input type="number" step="0.001" min="0" max="100" class="form-control form-control-sm ret-rate"' +
                    '             value="' + (datos.rate || '') + '"></td>' +
                    '  <td><input type="number" step="0.01" min="0" class="form-control form-control-sm ret-amount"' +
                    '             value="' + (datos.amount || '') + '"></td>' +
                    '  <td><input type="text" class="form-control form-control-sm ret-cert"' +
                    '             value="' + (datos.certificate_number || '') + '"></td>' +
                    '  <td class="text-center">' +
                    '    <button type="button" class="btn btn-link text-danger p-0 ret-remove"><i class="fas fa-times"></i></button>' +
                    '  </td>' +
                    '</tr>'
                );

                $('#retentionLines').append($fila);

                // Cargar conceptos del tipo ya elegido (caso de edición)
                if (datos.type) {
                    cargarConceptos($fila, datos.concept_code);
                }
            }

            /** Llena el selector de conceptos según el tipo elegido. */
            function cargarConceptos($fila, seleccionado) {
                const tipo = $fila.find('.ret-type').val();
                const $conceptos = $fila.find('.ret-concept').empty();

                if (!tipo || !CATALOGO[tipo]) {
                    $fila.find('.ret-help').text('');
                    return;
                }

                const conceptos = CATALOGO[tipo].concepts;

                Object.keys(conceptos).forEach(function (codigo) {
                    $conceptos.append(
                        '<option value="' + codigo + '"' +
                        (seleccionado === codigo ? ' selected' : '') + '>' +
                        conceptos[codigo].label + '</option>'
                    );
                });

                $fila.find('.ret-help').text(CATALOGO[tipo].help);
            }

            // Al cambiar el tipo: se recargan los conceptos y se
            // precarga la base que corresponde (el valor del servicio
            // o el IVA, según el impuesto).
            $(document).on('change', '.ret-type', function () {
                const $fila = $(this).closest('tr');

                cargarConceptos($fila);
                precargarBase($fila);
                $fila.find('.ret-concept').trigger('change');
            });

            // Al cambiar el concepto se propone su tarifa de referencia
            $(document).on('change', '.ret-concept', function () {
                const $fila = $(this).closest('tr');
                const tipo = $fila.find('.ret-type').val();
                const codigo = $(this).val();

                if (tipo && CATALOGO[tipo] && CATALOGO[tipo].concepts[codigo]) {
                    const tarifa = CATALOGO[tipo].concepts[codigo].rate;

                    // Tarifa 0 significa "la fija el municipio o el
                    // decreto vigente": no se propone nada, la escribe
                    // quien tiene el certificado a la vista.
                    if (tarifa > 0) {
                        $fila.find('.ret-rate').val(tarifa);
                    }
                }

                calcularValor($fila);
            });

            /** Propone la base según lo que grava el impuesto. */
            function precargarBase($fila) {
                if (!facturaEnEdicion) {
                    return;
                }

                const tipo = $fila.find('.ret-type').val();

                if (!tipo || !CATALOGO[tipo]) {
                    return;
                }

                const base = CATALOGO[tipo].base === 'iva'
                    ? facturaEnEdicion.iva
                    : facturaEnEdicion.subtotal;

                $fila.find('.ret-base').val(base > 0 ? base : '');
            }

            // Base o tarifa: se recalcula el valor. El campo del valor
            // queda editable a propósito, porque manda el certificado.
            $(document).on('input', '.ret-base, .ret-rate', function () {
                calcularValor($(this).closest('tr'));
            });

            $(document).on('input', '.ret-amount', totalizarRetenciones);

            function calcularValor($fila) {
                const base = Number($fila.find('.ret-base').val() || 0);
                const tarifa = Number($fila.find('.ret-rate').val() || 0);

                if (base > 0 && tarifa > 0) {
                    $fila.find('.ret-amount').val((base * tarifa / 100).toFixed(2));
                }

                totalizarRetenciones();
            }

            $(document).on('click', '.ret-remove', function () {
                $(this).closest('tr').remove();
                totalizarRetenciones();
            });

            function totalizarRetenciones() {
                let total = 0;

                $('#retentionLines tr').each(function () {
                    total += Number($(this).find('.ret-amount').val() || 0);
                });

                $('#retentionTotal').text(pesos.format(total));
            }

            $('#saveRetentions').on('click', function () {
                if (!facturaEnEdicion) {
                    return;
                }

                const lineas = [];
                let error = null;

                $('#retentionLines tr').each(function () {
                    const $f = $(this);
                    const tipo = $f.find('.ret-type').val();
                    const base = Number($f.find('.ret-base').val() || 0);
                    const tarifa = Number($f.find('.ret-rate').val() || 0);
                    const valor = Number($f.find('.ret-amount').val() || 0);

                    // Una fila totalmente vacía se ignora: es la que
                    // queda al abrir el editor sin usarla.
                    if (!tipo && !base && !valor) {
                        return;
                    }

                    if (!tipo) {
                        error = 'Falta elegir el tipo de una de las retenciones.';
                        return;
                    }

                    if (base <= 0) {
                        error = 'La base de la retención debe ser mayor que cero.';
                        return;
                    }

                    if (valor <= 0) {
                        error = 'El valor de la retención debe ser mayor que cero.';
                        return;
                    }

                    if (valor > base) {
                        error = 'Una retención no puede ser mayor que su propia base.';
                        return;
                    }

                    lineas.push({
                        type: tipo,
                        concept_code: $f.find('.ret-concept').val() || null,
                        base: base,
                        rate: tarifa,
                        amount: valor,
                        certificate_number: $f.find('.ret-cert').val() || null,
                    });
                });

                if (error) {
                    $('#retentionError').removeClass('d-none').text(error);
                    return;
                }

                const total = totalDeRetenciones(lineas);

                if (total > facturaEnEdicion.monto) {
                    $('#retentionError').removeClass('d-none').text(
                        'Las retenciones (' + pesos.format(total) + ') superan el valor que se va a aplicar a esta factura (' +
                        pesos.format(facturaEnEdicion.monto) + ').'
                    );
                    return;
                }

                facturaEnEdicion.retenciones = lineas;
                Carrito.guardar();

                $('#retentionModal').modal('hide');
                pintarCheckout();
            });

            /* ---------------------------------------------------------
               Confirmar el cobro
               --------------------------------------------------------- */

            function mostrarError(mensaje) {
                $('#checkoutError').removeClass('d-none').text(mensaje);
            }

            function limpiarError() {
                $('#checkoutError').addClass('d-none').text('');
            }

            $('#confirmCheckout').on('click', function () {
                const items = Carrito.lista();

                if (items.length === 0) {
                    return;
                }

                const $boton = $(this);

                $boton.prop('disabled', true).html(
                    '<span class="spinner-border spinner-border-sm"></span> Procesando…'
                );
                $('#checkoutError').addClass('d-none').text('');

                // Una sola factura de un solo contrato va por la ruta
                // simple; varias, por la de cobro múltiple (que crea el
                // lote y emite un recibo por contrato).
                const esLote = items.length > 1;

                const comun = {
                    _token: $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}',
                    payment_method: $('#payment_method').val(),
                    reference_number: $('#reference_number').val() || null,
                    notes: $('#notes').val() || null,
                };

                const datos = esLote
                    ? Object.assign({}, comun, {
                        payer_name: $('#payer_name').val() || null,
                        payer_document: $('#payer_document').val() || null,
                        payer_phone: $('#payer_phone').val() || null,
                        items: items.map(function (i) {
                            return {
                                invoice_id: i.id,
                                // El efectivo es lo aplicado MENOS lo
                                // retenido: la retención no entra a caja.
                                amount: redondear(i.monto - totalDeRetenciones(i.retenciones)),
                                retentions: i.retenciones || [],
                            };
                        }),
                    })
                    : Object.assign({}, comun, {
                        invoice_id: items[0].id,
                        amount: redondear(items[0].monto - totalDeRetenciones(items[0].retenciones)),
                        retentions: items[0].retenciones || [],
                    });

                $.ajax({
                    url: esLote ? RUTAS.lote : RUTAS.simple,
                    method: 'POST',
                    data: datos,
                    dataType: 'json',
                    success: function (respuesta) {
                        if (!respuesta.success) {
                            mostrarError(respuesta.error || 'No se pudo registrar el cobro.');
                            return;
                        }

                        // El cobro salió: se limpia el carrito para que
                        // no se vuelva a cobrar por equivocación.
                        Carrito.vaciar();
                        sincronizarTabla();
                        pintarBarra();

                        // El recibo se abre CUANDO el modal de cobro ya
                        // terminó de cerrarse. Encadenar hide+show de
                        // golpe deja el fondo oscuro del primero encima
                        // del segundo y la pantalla queda bloqueada.
                        $('#checkoutModal').one('hidden.bs.modal', function () {
                            mostrarRecibo(respuesta, esLote);
                        });

                        $('#checkoutModal').modal('hide');
                    },
                    error: function (xhr) {
                        let mensaje = 'Ocurrió un error al registrar el cobro.';

                        if (xhr.responseJSON) {
                            if (xhr.responseJSON.error) {
                                mensaje = xhr.responseJSON.error;
                            } else if (xhr.responseJSON.errors) {
                                mensaje = Object.values(xhr.responseJSON.errors).flat().join(' ');
                            } else if (xhr.responseJSON.message) {
                                mensaje = xhr.responseJSON.message;
                            }
                        }

                        mostrarError(mensaje);
                    },
                    complete: function () {
                        $boton.prop('disabled', false).html('<i class="fas fa-check"></i> Confirmar cobro');
                    },
                });
            });

            function redondear(valor) {
                return Math.round(Number(valor) * 100) / 100;
            }

            /* ---------------------------------------------------------
               Recibo
               --------------------------------------------------------- */

            function mostrarRecibo(respuesta, esLote) {
                $('#receiptSummary').html(
                    esLote
                        ? '<i class="fas fa-check-circle"></i> ' + respuesta.message +
                          ' Total recibido: <strong>' + respuesta.batch.total + '</strong>.' +
                          ' Se emitió un recibo por cada contrato.'
                        : '<i class="fas fa-check-circle"></i> Cobro registrado por <strong>$' +
                          respuesta.payment.amount + '</strong>. Saldo de la factura: <strong>$' +
                          respuesta.new_balance + '</strong>.'
                );

                $('#receiptFrame').attr('src', respuesta.receipt_url);
                $('#receiptDownload').attr('href', respuesta.receipt_pdf);
                $('#receiptModal').modal('show');
            }

            /**
             * Imprimir manda el HTML del iframe a la impresora.
             *
             * Se imprime el HTML y no el PDF porque la tirilla no tiene
             * alto fijo: el navegador le entrega a la térmica justo el
             * papel que ocupa el recibo, mientras que un PDF tiene
             * página de alto fijo y sacaría papel en blanco.
             */
            $('#receiptPrint').on('click', function () {
                const marco = document.getElementById('receiptFrame');

                if (!marco || !marco.contentWindow) {
                    return;
                }

                marco.contentWindow.focus();
                marco.contentWindow.print();
            });

            // El botón Cerrar solo cierra el modal; la recarga se hace
            // en hidden.bs.modal, que cubre todas las formas de
            // cerrarlo (botón, aspa, Escape) sin recargar dos veces.
            $('#receiptClose').on('click', function () {
                $('#receiptModal').modal('hide');
            });

            // Al cerrarlo se recarga: los saldos de la tabla quedaron
            // viejos después del cobro.
            $('#receiptModal').on('hidden.bs.modal', function () {
                window.location.reload();
            });

            /* ---------------------------------------------------------
               Modales apilados

               El editor de retenciones se abre ENCIMA del modal de
               cobro. Bootstrap 4 no soporta eso de fábrica: el fondo
               oscuro del segundo modal se dibuja por encima del
               primero y de sí mismo, con lo que el editor aparece
               atenuado y no se deja usar. Se corrige subiendo el
               z-index de cada modal según cuántos haya abiertos.
               --------------------------------------------------------- */
            $(document).on('show.bs.modal', '.modal', function () {
                const nivel = 1040 + (10 * $('.modal:visible').length);

                $(this).css('z-index', nivel);

                // El backdrop se crea después del evento, por eso el
                // ajuste va en el siguiente ciclo del navegador.
                setTimeout(function () {
                    $('.modal-backdrop').not('.modal-stack')
                        .css('z-index', nivel - 1)
                        .addClass('modal-stack');
                }, 0);
            });

            $(document).on('hidden.bs.modal', '.modal', function () {
                // Al cerrar el de encima, Bootstrap le quita a <body>
                // la clase que bloquea el scroll, aunque siga abierto
                // el de abajo. Se devuelve.
                if ($('.modal:visible').length > 0) {
                    $(document.body).addClass('modal-open');
                }
            });

            /* ---------------------------------------------------------
               Arranque
               --------------------------------------------------------- */

            Carrito.cargar();
            sincronizarTabla();
            pintarBarra();
        })();
    </script>
@endsection
