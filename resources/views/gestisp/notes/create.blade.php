@extends('adminlte::page')

@section('title', 'Emitir nota')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-file-invoice mr-2"></i>Emitir nota sobre una factura</h1>
        <a href="{{ route('invoices.show', $factura->id) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver a la factura
        </a>
    </div>
@stop

@section('content')
    @if(session('error'))
        <div class="alert alert-danger shadow-sm">
            <i class="fas fa-exclamation-triangle mr-1"></i> {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger shadow-sm">
            <strong>Revise lo siguiente:</strong>
            <ul class="mb-0 mt-1">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        {{-- ---------- Factura que se corrige ---------- --}}
        <div class="col-lg-4">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-receipt mr-1"></i> Factura a corregir</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width:45%">Número</th>
                            <td><strong>{{ $factura->displayNumber() }}</strong></td>
                        </tr>
                        <tr>
                            <th>Cliente</th>
                            <td>{{ $factura->contract?->client?->name }} {{ $factura->contract?->client?->last_name }}</td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $factura->contract?->client?->identity_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>N.º de contrato</th>
                            <td>{{ $factura->contract?->contract_number ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Emitida</th>
                            <td>{{ $factura->issue_date?->format('d/m/Y') }}</td>
                        </tr>
                        <tr>
                            <th>Total facturado</th>
                            <td>${{ number_format((float) $factura->total, 2, ',', '.') }}</td>
                        </tr>
                        <tr class="bg-light">
                            <th>Saldo pendiente</th>
                            <td><strong>${{ number_format((float) $factura->pending_invoice_amount, 2, ',', '.') }}</strong></td>
                        </tr>
                        <tr>
                            <th>Estado</th>
                            <td>{{ $factura->status }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            @if($factura->notes->isNotEmpty())
                <div class="card shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title">Notas ya emitidas</h3>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach($factura->notes as $previa)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <a href="{{ route('notes.show', $previa) }}">{{ $previa->full_number }}</a>
                                    <small class="d-block text-muted">{{ $previa->etiqueta_tipo }}</small>
                                </span>
                                <span class="{{ $previa->vigente ? '' : 'text-muted' }}">
                                    {{ $previa->tipo()->disminuye() ? '−' : '+' }}${{ number_format((float) $previa->total, 0, ',', '.') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- ---------- Datos de la nota ---------- --}}
        <div class="col-lg-8">
            <form action="{{ route('notes.store') }}" method="POST"
                  onsubmit="return confirm('¿Emitir la nota? El saldo de la factura se ajustará y quedará registrado.');">
                @csrf
                <input type="hidden" name="invoice_id" value="{{ $factura->id }}">

                <div class="card card-outline card-warning shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-edit mr-1"></i> Datos de la nota</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label for="type">Tipo de nota <span class="text-danger">*</span></label>
                                <select name="type" id="type" class="form-control" required>
                                    <option value="">Seleccione...</option>
                                    @foreach($tipos as $valor => $etiqueta)
                                        <option value="{{ $valor }}" @selected(old('type') === $valor)>{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                                <small class="form-text text-muted" id="ayudaTipo">
                                    La crédito disminuye el saldo; la débito lo aumenta.
                                </small>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="concept_code">Concepto (DIAN) <span class="text-danger">*</span></label>
                                <select name="concept_code" id="concept_code" class="form-control" required disabled>
                                    <option value="">Elija primero el tipo de nota</option>
                                </select>
                                <small class="form-text text-muted">
                                    Motivo normativo que clasifica el ajuste.
                                </small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="form-group col-md-4">
                                <label for="subtotal">Valor base <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" step="0.01" min="0.01" name="subtotal" id="subtotal"
                                           class="form-control" value="{{ old('subtotal') }}" required>
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="tax">Impuestos</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" step="0.01" min="0" name="tax" id="tax"
                                           class="form-control" value="{{ old('tax', 0) }}">
                                </div>
                            </div>
                            <div class="form-group col-md-4">
                                <label for="issue_date">Fecha de emisión</label>
                                <input type="date" name="issue_date" id="issue_date" class="form-control"
                                       value="{{ old('issue_date', now()->toDateString()) }}">
                            </div>
                        </div>

                        <div class="alert alert-light border" id="resumenNota">
                            Total de la nota: <strong id="totalNota">$0</strong>
                            <span id="efectoNota" class="ml-2 text-muted"></span>
                        </div>

                        <div class="form-group">
                            <label for="reason">Motivo <span class="text-danger">*</span></label>
                            <textarea name="reason" id="reason" rows="3" class="form-control"
                                      minlength="10" maxlength="1000" required
                                      placeholder="Explique por qué se corrige la factura. Este texto es el sustento del ajuste ante una revisión.">{{ old('reason') }}</textarea>
                        </div>

                        <div class="callout callout-warning py-2 mb-0">
                            <p class="mb-0">
                                La factura original <strong>no se modifica</strong>: la nota queda como
                                documento aparte y ajusta el saldo. La operación queda registrada en la
                                trazabilidad del sistema.
                            </p>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="{{ route('invoices.show', $factura->id) }}" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check mr-1"></i> Emitir nota
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@stop

@section('js')
    <script>
        // Conceptos oficiales por tipo de nota
        const CONCEPTOS = @json($conceptos);
        const SALDO_FACTURA = {{ (float) $factura->pending_invoice_amount }};

        const selTipo = document.getElementById('type');
        const selConcepto = document.getElementById('concept_code');

        /** Carga los conceptos que corresponden al tipo elegido */
        function cargarConceptos() {
            const tipo = selTipo.value;
            selConcepto.innerHTML = '';

            if (!tipo) {
                selConcepto.disabled = true;
                selConcepto.innerHTML = '<option value="">Elija primero el tipo de nota</option>';
                return;
            }

            selConcepto.disabled = false;
            selConcepto.appendChild(new Option('Seleccione el concepto...', ''));

            Object.entries(CONCEPTOS[tipo]).forEach(function ([codigo, texto]) {
                selConcepto.appendChild(new Option(codigo + '. ' + texto, codigo));
            });

            actualizarResumen();
        }

        /** Muestra el total y el efecto que tendrá sobre la factura */
        function actualizarResumen() {
            const base = parseFloat(document.getElementById('subtotal').value) || 0;
            const imp = parseFloat(document.getElementById('tax').value) || 0;
            const total = base + imp;
            const tipo = selTipo.value;

            document.getElementById('totalNota').textContent =
                '$' + total.toLocaleString('es-CO', { minimumFractionDigits: 2 });

            const efecto = document.getElementById('efectoNota');

            if (!tipo || total <= 0) {
                efecto.textContent = '';
                return;
            }

            if (tipo === 'credito') {
                const resultante = Math.max(SALDO_FACTURA - total, 0);
                efecto.textContent = '· El saldo de la factura pasaría a $'
                    + resultante.toLocaleString('es-CO', { minimumFractionDigits: 2 });
                efecto.className = total > SALDO_FACTURA ? 'ml-2 text-danger' : 'ml-2 text-muted';

                if (total > SALDO_FACTURA) {
                    efecto.textContent = '· Supera el saldo pendiente ($'
                        + SALDO_FACTURA.toLocaleString('es-CO', { minimumFractionDigits: 2 })
                        + '): la nota crédito no puede devolver más de lo que se debe.';
                }
            } else {
                efecto.textContent = '· El saldo de la factura pasaría a $'
                    + (SALDO_FACTURA + total).toLocaleString('es-CO', { minimumFractionDigits: 2 });
                efecto.className = 'ml-2 text-muted';
            }
        }

        selTipo.addEventListener('change', cargarConceptos);
        document.getElementById('subtotal').addEventListener('input', actualizarResumen);
        document.getElementById('tax').addEventListener('input', actualizarResumen);

        // Si el formulario vuelve con datos, se restauran
        @if(old('type'))
            selTipo.value = @json(old('type'));
            cargarConceptos();
            selConcepto.value = @json(old('concept_code'));
        @endif
    </script>
@stop
