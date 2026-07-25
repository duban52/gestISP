@extends('errors.layout', [
    'codigo' => 404,
    'titulo' => 'No encontramos esta página',
    'mensaje' => 'La dirección que abrió no existe, o el registro que buscaba fue eliminado.',
    'sugerencias' => [
        'Revise que el enlace esté completo y bien escrito.',
        'Si llegó desde un enlace guardado, es posible que la página haya cambiado de dirección.',
    ],
])

@section('icono')
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 16 16" fill="currentColor">
        <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242.656a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
    </svg>
@endsection
