@php
    // Si el 403 trae un mensaje propio (por ejemplo el del permiso que
    // falta), se muestra ese; si no, uno general.
    $propio = trim((string) ($exception?->getMessage() ?? ''));
@endphp

@extends('errors.layout', [
    'codigo' => 403,
    'titulo' => 'No tiene permiso para entrar aquí',
    'mensaje' => $propio !== '' ? $propio : 'Su rol actual no incluye el permiso necesario para ver esta pantalla.',
    'sugerencias' => [
        'Verifique que esté trabajando en la sucursal correcta: su rol puede cambiar de una a otra.',
        'Si necesita este acceso, pídale a un administrador que lo agregue a su rol.',
    ],
])

@section('icono')
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 16 16" fill="currentColor">
        <path d="M8 1a2 2 0 0 1 2 2v4H6V3a2 2 0 0 1 2-2zm3 6V3a3 3 0 0 0-6 0v4a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
    </svg>
@endsection
