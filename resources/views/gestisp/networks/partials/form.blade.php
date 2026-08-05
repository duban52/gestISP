{{-- Formulario compartido por crear y editar una red. --}}
<div class="card card-outline card-primary shadow-sm">
    <div class="card-body">
        <div class="form-group">
            <label for="name">Nombre de la red <span class="text-danger">*</span></label>
            <input type="text" name="name" id="name" class="form-control"
                   value="{{ old('name', $network->name ?? '') }}"
                   placeholder="Ej.: Red Gómez Plata" required autofocus>
        </div>

        <div class="form-group">
            <label for="description">Descripción</label>
            <input type="text" name="description" id="description" class="form-control"
                   value="{{ old('description', $network->description ?? '') }}"
                   placeholder="Zona que cubre, notas de la planta...">
        </div>

        <div class="row">
            <div class="col-md-6 form-group">
                <label for="nap_prefix">
                    Prefijo de las cajas <span class="text-danger">*</span>
                </label>
                <input type="text" name="nap_prefix" id="nap_prefix" class="form-control text-uppercase"
                       value="{{ old('nap_prefix', $network->nap_prefix ?? 'NAP') }}" maxlength="10" required>
                <small class="form-text text-muted">
                    {{-- Igual que la numeración de contratos: el técnico
                         pide "la NAP-014", no un identificador interno. --}}
                    Las cajas se numerarán solas: <code id="ejemploCodigo">NAP001</code>, <code>NAP002</code>…
                </small>
            </div>

            @isset($network)
                <div class="col-md-6 form-group">
                    <label>Siguiente número</label>
                    <input type="text" class="form-control" readonly
                           value="{{ $network->nap_prefix }}{{ str_pad($network->nap_next_number, 3, '0', STR_PAD_LEFT) }}">
                    <small class="form-text text-muted">
                        Es el código que recibirá la próxima caja que se cree.
                    </small>
                </div>
            @endisset
        </div>

        <div class="custom-control custom-switch">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" class="custom-control-input" id="active" name="active" value="1"
                   @checked(old('active', $network->active ?? true))>
            <label class="custom-control-label" for="active">Red activa</label>
        </div>
    </div>

    <div class="card-footer text-right">
        <a href="{{ route('networks.index') }}" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Guardar
        </button>
    </div>
</div>

@section('js')
    <script>
        // El ejemplo se actualiza mientras se escribe el prefijo: así
        // se ve el código real antes de guardar.
        $('#nap_prefix').on('input', function () {
            $('#ejemploCodigo').text(($(this).val() || 'NAP').toUpperCase() + '001');
        });
    </script>
@endsection
