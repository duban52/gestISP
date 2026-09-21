{{-- Sí o no, de un vistazo. Un check verde y una raya gris se leen
     más rápido que dos palabras en una tabla de siete columnas. --}}
@if($valor)
    <i class="fas fa-check text-success" title="Sí"></i>
@else
    <i class="fas fa-minus text-muted" title="No"></i>
@endif
