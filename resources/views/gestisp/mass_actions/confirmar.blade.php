@extends('adminlte::page')

@section('title', 'Revertir acción masiva')

@section('content_header')
    <h1 class="mb-0 text-danger"><i class="fas fa-undo mr-2"></i>Revertir acción masiva #{{ $accion->id }}</h1>
@endsection

@section('content')
    {{--
        La pantalla de confirmación.

        No es un «¿está seguro?»: es la última oportunidad de que
        alguien vea EXACTAMENTE qué va a pasar, cuántos registros se
        van a poder deshacer y cuántos no. Nadie debería revertir
        ochocientos registros sin saber antes que ciento veinte van a
        quedarse como están.
    --}}
    <div class="row justify-content-center">
        <div class="col-12 col-lg-9">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white py-2">
                    <h3 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-1"></i> Advertencia</h3>
                </div>
                <div class="card-body">
                    <p class="lead mb-3">
                        Está a punto de revertir una acción masiva. Esta operación
                        <strong>modificará nuevamente los registros afectados</strong>.
                    </p>

                    <table class="table table-sm table-striped">
                        <tr><th style="width:30%">Tipo</th><td>{{ $accion->type->label() }}</td></tr>
                        <tr><th>Descripción</th><td>{{ $accion->description }}</td></tr>
                        <tr><th>Ejecutada el</th><td>{{ $accion->created_at->format('d/m/Y H:i') }}</td></tr>
                        <tr>
                            <th>Por</th>
                            <td>{{ $accion->user ? trim($accion->user->name . ' ' . $accion->user->last_name) : 'Sistema' }}</td>
                        </tr>
                        <tr><th>Registros afectados</th><td>{{ number_format($accion->ok_items) }}</td></tr>
                    </table>

                    @if($estrategia)
                        <div class="alert alert-warning">
                            <strong>Qué va a pasar:</strong> {{ $estrategia->advertencia() }}
                        </div>
                    @endif

                    <div class="row text-center my-3">
                        <div class="col">
                            <h3 class="mb-0 text-success">{{ number_format($revision['reversibles']) }}</h3>
                            <small class="text-muted">se pueden revertir</small>
                        </div>
                        <div class="col">
                            <h3 class="mb-0 text-warning">{{ number_format($revision['conflictos']) }}</h3>
                            <small class="text-muted">en conflicto</small>
                        </div>
                    </div>

                    {{-- Los conflictos, con su motivo. Es lo que impide
                         que la reversión pise el trabajo de alguien: se
                         enseñan ANTES de confirmar, no después. --}}
                    @if($revision['conflictos'] > 0)
                        <div class="alert alert-warning">
                            <strong>{{ number_format($revision['conflictos']) }} registro(s) no se van a tocar</strong>
                            porque cambiaron después de la acción. Estos son algunos:
                            <ul class="mb-0 mt-2">
                                @foreach($revision['motivos'] as $conflicto)
                                    <li><strong>{{ $conflicto['label'] ?? '—' }}</strong>: {{ $conflicto['motivo'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($revision['reversibles'] === 0)
                        <div class="alert alert-secondary mb-0">
                            No hay ningún registro que se pueda revertir.
                        </div>
                    @else
                        <form method="POST" action="{{ route('mass_actions.revert', $accion) }}"
                              data-procesando="Revirtiendo la acción masiva...">
                            @csrf
                            <div class="form-group">
                                <label for="confirmacion">
                                    Para continuar, escriba <strong>REVERTIR</strong>:
                                </label>
                                <input type="text" name="confirmacion" id="confirmacion"
                                       class="form-control @error('confirmacion') is-invalid @enderror"
                                       autocomplete="off" placeholder="REVERTIR" required>
                                @error('confirmacion')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="{{ route('mass_actions.show', $accion) }}" class="btn btn-secondary">
                                    Cancelar
                                </a>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-undo"></i> Revertir {{ number_format($revision['reversibles']) }} registro(s)
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @include('gestisp.partials.resultado-accion')
@endsection
