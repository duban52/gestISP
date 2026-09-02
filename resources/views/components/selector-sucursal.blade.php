{{--
    Selector de sucursal para los formularios de creación.

    POR QUÉ EXISTE
    --------------
    Hasta ahora todo se creaba en `session('branch_id')`: había una
    sucursal activa y no había nada que preguntar. El panel consolidado
    rompe ese supuesto — el usuario trabaja varias sedes a la vez y no
    hay una activa —, así que en ese modo hay que preguntar en cuál se
    guarda lo que se está creando.

    CUÁNDO APARECE
    --------------
    Solo cuando hay algo que elegir: panel consolidado Y más de una
    sucursal alcanzable. En modo independiente, o cuando el usuario
    solo llega a una sede, el bloque no se pinta y el controlador la
    asume — que es exactamente lo que pidió el usuario.

    Quien decide es CurrentContext::hayQueElegirSucursal(), el mismo
    que usa branchParaEscritura() en el controlador para aceptar o
    rechazar lo que llegue. Un solo criterio, no dos.

    El componente consulta el contexto por su cuenta a propósito: así
    se puede añadir a cualquier formulario sin tocar su controlador ni
    acordarse de pasarle variables.

    USO
    ---
        <x-selector-sucursal />
        <x-selector-sucursal titulo="Sucursal del servicio"
                             ayuda="Determina el consecutivo del contrato." />
--}}
@props([
    'titulo' => 'Sucursal',
    'ayuda' => null,
    'campo' => 'branch_id',
    // Sin la tarjeta alrededor, para barras de botones donde una
    // tarjeta entera desencuadra la fila.
    'compacto' => false,
    'clase' => '',
])

@php
    $contexto = app(\App\Tenancy\CurrentContext::class);
    $hayQueElegir = $contexto->hayQueElegirSucursal();
    $sucursales = $hayQueElegir ? $contexto->sucursalesElegibles() : collect();
@endphp

@if($hayQueElegir && $compacto)
    <div class="form-group {{ $clase }}">
        <label for="{{ $campo }}" class="mb-1 small text-muted">
            {{ $titulo }} <span class="text-danger">*</span>
        </label>
        <select name="{{ $campo }}" id="{{ $campo }}"
                class="form-control form-control-sm @error($campo) is-invalid @enderror" required>
            <option value="">Seleccione…</option>
            @foreach($sucursales as $sucursal)
                <option value="{{ $sucursal->id }}" @selected(old($campo) == $sucursal->id)>
                    {{ $sucursal->name }}
                </option>
            @endforeach
        </select>
        @error($campo)<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
@elseif($hayQueElegir)
    <div class="card card-outline card-warning">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-store mr-1"></i> {{ $titulo }}
            </h3>
        </div>
        <div class="card-body">
            <div class="form-group mb-0">
                <label for="{{ $campo }}">
                    Sucursal <span class="text-danger">*</span>
                </label>
                <select name="{{ $campo }}" id="{{ $campo }}"
                        class="form-control @error($campo) is-invalid @enderror" required>
                    <option value="">Seleccione la sucursal</option>
                    @foreach($sucursales as $sucursal)
                        <option value="{{ $sucursal->id }}" @selected(old($campo) == $sucursal->id)>
                            {{ $sucursal->name }}
                        </option>
                    @endforeach
                </select>
                @error($campo)<div class="invalid-feedback">{{ $message }}</div>@enderror
                <small class="form-text text-muted">
                    {{ $ayuda ?? 'Está trabajando en panel consolidado: indique en qué sucursal se guarda.' }}
                </small>
            </div>
        </div>
    </div>
@endif
