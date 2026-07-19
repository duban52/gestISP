@extends('adminlte::page')

@section('title', 'Editar Sucursal')
@section('content_header')
    <div class="card p-3"><h2>EDITAR SUCURSAL</h2></div>
@endsection
@section('content')
    <div class="card">
        <div class="card-body">
            <form action="{{ route('branches.update', $branch->id) }}" method="POST" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="form-group col-md-6">
                        <label for="nit">Nit</label>
                        <input type="text" name="nit" class="form-control" value="{{ $branch->nit }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="name">Nombre</label>
                        <input type="text" name="name" class="form-control" value="{{ $branch->name }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="country">País</label>
                        <input type="text" name="country" class="form-control" value="{{ $branch->country }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="department">Departamento</label>
                        <input type="text" name="department" class="form-control" value="{{ $branch->department }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="municipality">Municipio</label>
                        <input type="text" name="municipality" class="form-control" value="{{ $branch->municipality }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="address">Dirección</label>
                        <input type="text" name="address" class="form-control" value="{{ $branch->address }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="number_phone">Teléfono</label>
                        <input type="text" name="number_phone" class="form-control" value="{{ $branch->number_phone }}" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="additional_number">Teléfono Adicional</label>
                        <input type="text" name="additional_number" class="form-control" value="{{ $branch->additional_number }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="image">Cambiar Imagen</label>
                        <input type="file" name="image" class="form-control">
                        <div class="rounded mx-auto d-block text-center mt-2">
                            <img src="{{ asset('storage/'.$branch->image) }}" style="width: 250px">
                        </div>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="moving_price">Precio de Traslado</label>
                        <input type="number" step="0.01" name="moving_price" class="form-control" value="{{ $branch->moving_price }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="reconnection_price">Precio de Reconexión</label>
                        <input type="number" step="0.01" name="reconnection_price" class="form-control" value="{{ $branch->reconnection_price }}">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="message_custom_invoice">Mensaje Personalizado</label>
                        <textarea name="message_custom_invoice" class="form-control">{{ $branch->message_custom_invoice }}</textarea>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="observation">Observaciones</label>
                        <textarea name="observation" class="form-control">{{ $branch->observation }}</textarea>
                    </div>

                    {{-- ============================================================
                         Configuración de facturación de la sucursal

                         Reglas que consumen los servicios de facturación
                         (app/Billing/Services) — modificables sin tocar código.
                         ============================================================ --}}
                    <div class="col-12">
                        <div class="card border-primary mt-2">
                            <div class="card-header py-2">
                                <strong><i class="fas fa-file-invoice-dollar"></i> Configuración de facturación</strong>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="form-group col-md-6">
                                        <label for="proration_mode">Facturación del primer mes</label>
                                        <select name="proration_mode" id="proration_mode" class="form-control" required>
                                            @foreach($prorationModes as $mode)
                                                <option value="{{ $mode->value }}"
                                                    {{ old('proration_mode', $billingSettings->proration_mode->value) === $mode->value ? 'selected' : '' }}>
                                                    {{ $mode->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <small class="form-text text-muted">
                                            Prorratear: un contrato activado el día 20 paga solo los días
                                            restantes del mes. Mes completo: paga el mes entero.
                                        </small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="due_days">Días de plazo</label>
                                        <input type="number" name="due_days" id="due_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('due_days', $billingSettings->due_days) }}" required>
                                        <small class="form-text text-muted">Vencimiento desde la emisión</small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="suspension_threshold">Umbral de corte</label>
                                        <input type="number" name="suspension_threshold" id="suspension_threshold" class="form-control"
                                               min="1" max="12"
                                               value="{{ old('suspension_threshold', $billingSettings->suspension_threshold) }}" required>
                                        <small class="form-text text-muted">Facturas vencidas para suspender</small>
                                    </div>
                                    <div class="form-group col-md-2">
                                        <label for="suspension_days">Días hasta el corte</label>
                                        <input type="number" name="suspension_days" id="suspension_days" class="form-control"
                                               min="1" max="90"
                                               value="{{ old('suspension_days', $billingSettings->suspension_days) }}" required>
                                        <small class="form-text text-muted">Con facturas vencidas</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 text-center mt-3">
                        <button  type="submit" class="btn btn-primary col-md-3">Actualizar</button>
                    </div>

                </div>

            </form>
        </div>
    </div>
@endsection
