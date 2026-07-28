@extends('adminlte::page')

@section('title', 'Pago por adelantado')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-piggy-bank mr-2"></i>Pago por adelantado</h1>
        <a href="{{ route('contracts.show', $contrato) }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Volver al contrato
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
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-5">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-user mr-1"></i> Contrato</h3>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width:45%">N.º de contrato</th>
                            <td><strong>{{ $contrato->numero_visible }}</strong></td>
                        </tr>
                        <tr>
                            <th>Cliente</th>
                            <td>{{ $contrato->client?->name }} {{ $contrato->client?->last_name }}</td>
                        </tr>
                        <tr>
                            <th>Identificación</th>
                            <td>{{ $contrato->client?->identity_number }}</td>
                        </tr>
                        <tr>
                            <th>Plan</th>
                            <td>{{ $contrato->plan?->name ?? '—' }}</td>
                        </tr>
                        <tr class="{{ $deuda > 0 ? 'table-warning' : '' }}">
                            <th>Debe hoy</th>
                            <td><strong>${{ number_format($deuda, 2, ',', '.') }}</strong></td>
                        </tr>
                        <tr class="{{ $saldoAFavor > 0 ? 'table-success' : '' }}">
                            <th>Saldo a favor</th>
                            <td><strong>${{ number_format($saldoAFavor, 2, ',', '.') }}</strong></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="callout callout-info">
                <p class="mb-0">
                    El dinero entra a su caja como cualquier cobro. Primero se abona a
                    <strong>lo que el cliente deba hoy</strong> (de la factura más antigua a la
                    más reciente) y lo que sobre queda <strong>a favor</strong>, aplicándose solo
                    a las facturas de los meses siguientes.
                </p>
            </div>
        </div>

        <div class="col-lg-7">
            <form action="{{ route('advance.store', $contrato) }}" method="POST"
                  onsubmit="return confirm('¿Registrar el anticipo? El dinero quedará en su caja y a favor del cliente.');">
                @csrf
                <div class="card card-outline card-success shadow-sm">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-cash-register mr-1"></i> Datos del pago</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label for="amount">Valor recibido <span class="text-danger">*</span></label>
                            <div class="input-group input-group-lg">
                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                <input type="number" step="0.01" min="0.01" name="amount" id="amount"
                                       class="form-control" value="{{ old('amount') }}" required autofocus>
                            </div>

                            @if($mensualidad > 0)
                                {{-- Atajos: lo más común es adelantar meses completos --}}
                                <div class="mt-2">
                                    <small class="text-muted d-block mb-1">
                                        Mensualidad aproximada: ${{ number_format($mensualidad, 2, ',', '.') }}
                                    </small>
                                    @foreach([3, 6, 12] as $meses)
                                        <button type="button" class="btn btn-outline-secondary btn-sm mr-1 atajo-meses"
                                                data-valor="{{ round($mensualidad * $meses, 2) }}">
                                            {{ $meses }} meses
                                        </button>
                                    @endforeach
                                </div>
                                <small class="form-text text-muted" id="equivalencia"></small>
                            @endif
                        </div>

                        <div class="row">
                            <div class="form-group col-md-6">
                                <label for="payment_method">Medio de pago <span class="text-danger">*</span></label>
                                <select name="payment_method" id="payment_method" class="form-control" required>
                                    <option value="">Seleccione...</option>
                                    @foreach(['Efectivo', 'Transferencia', 'Tarjeta', 'Consignación', 'Otro'] as $medio)
                                        <option value="{{ $medio }}" @selected(old('payment_method') === $medio)>{{ $medio }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="reference_number">Referencia</label>
                                <input type="text" name="reference_number" id="reference_number"
                                       class="form-control" value="{{ old('reference_number') }}"
                                       placeholder="N.º de transacción o consignación">
                            </div>
                        </div>

                        <div class="form-group mb-0">
                            <label for="notes">Observaciones</label>
                            <textarea name="notes" id="notes" rows="2" class="form-control"
                                      placeholder="Por ejemplo: el cliente adelanta el servicio de junio a noviembre.">{{ old('notes') }}</textarea>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="{{ route('contracts.show', $contrato) }}" class="btn btn-outline-secondary">Cancelar</a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check mr-1"></i> Registrar anticipo
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@stop

@section('js')
    <script>
        const MENSUALIDAD = {{ (float) $mensualidad }};
        const campoValor = document.getElementById('amount');

        // Atajos de meses completos
        document.querySelectorAll('.atajo-meses').forEach(function (boton) {
            boton.addEventListener('click', function () {
                campoValor.value = this.dataset.valor;
                mostrarEquivalencia();
            });
        });

        /** Cuántos meses de servicio cubre el valor escrito */
        function mostrarEquivalencia() {
            const etiqueta = document.getElementById('equivalencia');

            if (!etiqueta || !MENSUALIDAD) {
                return;
            }

            const valor = parseFloat(campoValor.value) || 0;

            if (valor <= 0) {
                etiqueta.textContent = '';
                return;
            }

            const meses = valor / MENSUALIDAD;
            etiqueta.textContent = 'Equivale aproximadamente a '
                + meses.toFixed(1).replace('.0', '') + ' mes(es) de servicio.';
        }

        campoValor.addEventListener('input', mostrarEquivalencia);
    </script>
@stop
