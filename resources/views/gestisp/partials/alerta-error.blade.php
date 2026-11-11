{{-- ============================================================
     El motivo de un fallo, en pantalla

     POR QUÉ EXISTE ESTE PARCIAL
     ---------------------------
     29 controladores del sistema rechazan operaciones con
     `back()->with('error', ...)`, y había doce módulos donde NINGUNA
     vista pintaba ese mensaje. El motivo viajaba en la sesión hasta
     la pantalla y ahí se evaporaba: la página recargaba, no pasaba
     nada, y desde fuera eso es indistinguible de un botón roto.

     Se descubrió al eliminar una ONT —el motivo estaba, nadie lo
     mostraba— y la medición posterior encontró el mismo silencio en
     otros once módulos.

     CÓMO SE USA
     -----------
         @include('gestisp.partials.alerta-error')

     Va al principio del contenido y SIEMPRE fuera de las cadenas de
     `@elseif` que pintan los avisos de éxito: un fallo no puede
     quedar tapado por el «correcto» de otra acción.
     ============================================================ --}}
@if(session('error'))
    <div class="alert alert-danger alert-dismissible shadow-sm">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <h6 class="mb-1">
            <i class="fas fa-exclamation-triangle mr-1"></i> No se pudo completar la operación
        </h6>
        <span>{{ session('error') }}</span>
    </div>
@endif

{{-- Los errores de validación que llegan por redirección. Las
     pantallas con formulario los pintan junto a cada campo, pero
     cuando el formulario está en un modal el usuario no ve nada: el
     modal ya se cerró. --}}
@if($errors->any())
    <div class="alert alert-warning alert-dismissible shadow-sm">
        <button type="button" class="close" data-dismiss="alert">&times;</button>
        <h6 class="mb-2"><i class="fas fa-exclamation-circle mr-1"></i> Revise estos datos</h6>
        <ul class="mb-0 pl-3">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
