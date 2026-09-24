@extends('adminlte::page')
@section('title', $empresa->nombreVisible())

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0">
            <i class="fas fa-building mr-2"></i>{{ $empresa->nombreVisible() }}
            <small class="text-muted">{{ $empresa->identificacion() }}</small>
        </h1>
        <div class="acciones-movil">
            @can('dian.index')
                <a href="{{ route('dian.panel', $empresa) }}" class="btn btn-outline-primary">
                    <i class="fas fa-file-invoice-dollar"></i> Facturación DIAN
                </a>
            @endcan
            <a href="{{ route('companies.edit', $empresa) }}" class="btn btn-primary">
                <i class="fas fa-edit"></i> Editar
            </a>
            <a href="{{ route('companies.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
@endsection

@section('content')
    @includeWhen(session('success'), 'gestisp.companies._alerta', ['tipo' => 'success', 'texto' => session('success')])

    <div class="row">
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-file-invoice mr-1"></i> Datos fiscales</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <tr><th style="width:45%">Razón social</th><td>{{ $empresa->legal_name }}</td></tr>
                            <tr><th>Nombre comercial</th><td>{{ $empresa->trade_name ?: '—' }}</td></tr>
                            <tr><th>Identificación</th><td><code>{{ $empresa->identificacion() }}</code></td></tr>
                            <tr><th>Dirección</th><td>{{ $empresa->address ?: '—' }}</td></tr>
                            <tr><th>Correo</th><td>{{ $empresa->email ?: '—' }}</td></tr>
                            <tr><th>Teléfono</th><td>{{ $empresa->phone ?: '—' }}</td></tr>
                            {{-- El registro ante el MinTIC. Un ISP lo tiene que
                                 poder enseñar, y sale impreso en el contrato de
                                 servicios del cliente: si está vacío, el
                                 contrato sale sin él y nadie se entera hasta
                                 que alguien lo reclama. --}}
                            <tr><th>Registro TIC</th><td>{{ $empresa->tic_registry ?: '—' }}</td></tr>
                            <tr>
                                <th>Página web</th>
                                <td>
                                    @if($empresa->website)
                                        <a href="{{ $empresa->website }}" target="_blank" rel="noopener">
                                            {{ $empresa->website }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Modalidad</th>
                                <td>
                                    @if($empresa->esConsolidada())
                                        <span class="badge badge-info">Panel consolidado</span>
                                    @else
                                        <span class="badge badge-secondary">Sucursales independientes</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th>Facturación electrónica</th>
                                <td>
                                    {{-- Todavía no se activa desde aquí: hace falta el
                                         certificado, la resolución y la habilitación ante
                                         la DIAN. Se muestra el estado, no se toca. --}}
                                    <span class="badge badge-{{ $empresa->electronic_invoicing_enabled ? 'success' : 'secondary' }}">
                                        {{ $empresa->electronic_invoicing_enabled ? 'Activada' : 'No activada' }}
                                    </span>
                                    @unless($empresa->electronic_invoicing_enabled)
                                        <small class="d-block text-muted mt-1">
                                            Requiere certificado digital, resolución y habilitación DIAN.
                                        </small>
                                    @endunless
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ============================================================
             Configuración de facturación de la empresa.

             No se guarda aparte: la que manda sigue siendo la de cada
             sucursal, que es la que leen los servicios de facturación.
             Esto es un «aplicar a todas» para no tener que configurar
             cinco sedes una por una, y para VER cuáles quedaron
             distintas — una sede con otro plazo no se nota hasta que
             un cliente reclama.
             ============================================================ --}}
        @can('companies.edit')
            @if($sucursales->isNotEmpty())
                @php
                    $distintas = $configuraciones->filter(fn ($config) => $config->proration_mode !== $facturacion->proration_mode
                        || $config->billing_mode !== $facturacion->billing_mode
                        || (int) $config->billing_day !== (int) $facturacion->billing_day
                        || (int) $config->due_days !== (int) $facturacion->due_days
                        || (int) $config->suspension_threshold !== (int) $facturacion->suspension_threshold
                        || (int) $config->suspension_days !== (int) $facturacion->suspension_days);
                @endphp

                <div class="col-12">
                    <div class="card border-primary">
                        <div class="card-header py-2">
                            <h3 class="card-title mb-0">
                                <i class="fas fa-file-invoice-dollar mr-1"></i> Facturación de las sucursales
                            </h3>
                        </div>
                        <div class="card-body">
                            @if($distintas->isNotEmpty())
                                <div class="alert alert-warning py-2">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    {{ $distintas->count() }} de {{ $total }} sucursal(es) tienen una
                                    configuración distinta a la de
                                    <strong>{{ $sucursales->first()->name }}</strong>:
                                    {{ $sucursales->whereIn('id', $distintas->keys())->pluck('name')->implode(', ') }}.
                                </div>
                            @endif

                            <form method="POST" action="{{ route('companies.billing', $empresa) }}">
                                @csrf
                                <div class="row">
                                    <div class="form-group col-12 col-md-4">
                                        <label for="c_proration_mode">Facturación del primer mes</label>
                                        <select name="proration_mode" id="c_proration_mode" class="form-control" required>
                                            @foreach($prorationModes as $modo)
                                                <option value="{{ $modo->value }}"
                                                    {{ old('proration_mode', $facturacion->proration_mode?->value) === $modo->value ? 'selected' : '' }}>
                                                    {{ $modo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group col-12 col-md-4">
                                        <label for="c_billing_mode">Cómo se factura</label>
                                        <select name="billing_mode" id="c_billing_mode" class="form-control" required>
                                            @foreach($billingModes as $modo)
                                                <option value="{{ $modo->value }}"
                                                    {{ old('billing_mode', $facturacion->billing_mode?->value ?? 'manual') === $modo->value ? 'selected' : '' }}>
                                                    {{ $modo->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group col-6 col-md-2" id="c_grupo_dia">
                                        <label for="c_billing_day">Día del mes</label>
                                        <input type="number" min="1" max="31" class="form-control"
                                               id="c_billing_day" name="billing_day"
                                               value="{{ old('billing_day', $facturacion->billing_day) }}">
                                    </div>
                                    <div class="form-group col-6 col-md-2">
                                        <label for="c_due_days">Días de plazo</label>
                                        <input type="number" min="1" max="90" class="form-control"
                                               id="c_due_days" name="due_days"
                                               value="{{ old('due_days', $facturacion->due_days) }}" required>
                                    </div>
                                    <div class="form-group col-6 col-md-3">
                                        <label for="c_suspension_threshold">Umbral de corte</label>
                                        <input type="number" min="1" max="12" class="form-control"
                                               id="c_suspension_threshold" name="suspension_threshold"
                                               value="{{ old('suspension_threshold', $facturacion->suspension_threshold) }}" required>
                                    </div>
                                    <div class="form-group col-6 col-md-3">
                                        <label for="c_suspension_days">Días hasta el corte</label>
                                        <input type="number" min="1" max="90" class="form-control"
                                               id="c_suspension_days" name="suspension_days"
                                               value="{{ old('suspension_days', $facturacion->suspension_days) }}" required>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-copy mr-1"></i>
                                    Aplicar a las {{ $total }} sucursales
                                </button>
                                <small class="text-muted d-block mt-1">
                                    Pisa la configuración de todas. Después cada sucursal puede volver a
                                    cambiar la suya desde su propia pantalla.
                                </small>
                            </form>
                        </div>
                    </div>
                </div>

                @push('js')
                    <script>
                        (function () {
                            const modo = document.getElementById('c_billing_mode');
                            const grupo = document.getElementById('c_grupo_dia');

                            if (!modo) return;

                            const pintar = () => {
                                grupo.style.display = modo.value === 'automatic' ? '' : 'none';
                            };

                            modo.addEventListener('change', pintar);
                            pintar();
                        })();
                    </script>
                @endpush
            @endif
        @endcan

        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-store mr-1"></i> Sucursales
                        <span class="badge badge-secondary ml-1">{{ $total }}</span>
                    </h3>
                    <a href="{{ route('branches.create', ['company' => $empresa->id]) }}"
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Añadir sucursal
                    </a>
                </div>
                <div class="card-body">
                    @if($sucursales->isEmpty())
                        {{-- Sin sucursales nadie puede entrar a esta empresa: el acceso
                             se concede POR SUCURSAL (user_branch), no por empresa. --}}
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle"></i>
                            Esta empresa no tiene ninguna sucursal, así que <strong>nadie puede
                            entrar a ella</strong>: el acceso se concede por sucursal.
                        </div>
                    @else
                        @php
                            $sinAcceso = $sucursales->where('users_count', 0);
                        @endphp

                        @if($sinAcceso->isNotEmpty())
                            <div class="alert alert-warning py-2">
                                <i class="fas fa-user-slash"></i>
                                <strong>{{ $sinAcceso->count() }}</strong> sucursal(es) sin
                                ningún usuario asignado: nadie puede entrar a ellas ni
                                editarlas. Asigne usuarios desde
                                <a href="{{ route('users.index') }}">Usuarios</a>.
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>Sucursal</th>
                                    <th>Municipio</th>
                                    <th>Prefijo</th>
                                    <th class="text-center">Con acceso</th>
                                    <th class="text-center">Acciones</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($sucursales as $sucursal)
                                    <tr>
                                        <td><strong>{{ $sucursal->name }}</strong></td>
                                        <td>{{ $sucursal->municipality ?: '—' }}</td>
                                        <td><code>{{ $sucursal->contract_prefix ?: '—' }}</code></td>
                                        <td class="text-center">
                                            {{-- El acceso se concede POR SUCURSAL. Una
                                                 sucursal sin usuarios es invisible: no
                                                 sale en los listados, nadie puede
                                                 entrar a ella y no se puede ni
                                                 editar. --}}
                                            @if($sucursal->users_count === 0)
                                                <span class="badge badge-warning" title="Nadie puede entrar a esta sucursal">
                                                    Nadie
                                                </span>
                                            @else
                                                {{ $sucursal->users_count }}
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <a href="{{ route('branches.edit', $sucursal) }}"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($empresa->esConsolidada())
                            <p class="text-muted small mt-2 mb-0">
                                <i class="fas fa-layer-group"></i>
                                En panel consolidado estas sucursales se trabajan desde una sola
                                pantalla, pero cada una conserva sus prefijos y consecutivos.
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="{{ asset('css/gestisp-movil.css') }}">
@endsection
