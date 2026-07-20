{{--
    Ficha de indicador.

    Parámetros:
      $titulo, $valor
      $icono      icono FontAwesome
      $color      sufijo de color de AdminLTE (info, success, warning...)
      $pie        texto auxiliar bajo el valor (opcional)
      $variacion  porcentaje contra el período anterior (opcional, null si no aplica)
      $invertir   true cuando subir es malo (bajas, cartera vencida)
--}}
@php
    $variacion = $variacion ?? null;
    $invertir = $invertir ?? false;

    // Sin período previo con datos no hay porcentaje que mostrar:
    // se omite la flecha en vez de dibujar un 0% engañoso
    $hayVariacion = $variacion !== null;
    $sube = $hayVariacion && $variacion > 0;
    $bueno = $invertir ? !$sube : $sube;
@endphp

<div class="small-box bg-{{ $color ?? 'light' }}">
    <div class="inner">
        <h3 style="font-size: 1.7rem;">{{ $valor }}</h3>
        <p class="mb-1">{{ $titulo }}</p>

        @if ($hayVariacion)
            <span class="badge {{ $bueno ? 'badge-success' : 'badge-danger' }}">
                <i class="fas fa-arrow-{{ $sube ? 'up' : 'down' }}"></i>
                {{ number_format(abs($variacion), 1) }}%
            </span>
            <span class="small">vs. período anterior</span>
        @elseif (!empty($pie))
            <span class="small">{{ $pie }}</span>
        @endif
    </div>
    <div class="icon">
        <i class="fas {{ $icono ?? 'fa-chart-bar' }}"></i>
    </div>
</div>
