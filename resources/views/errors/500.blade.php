@extends('errors.layout', [
    'codigo' => 500,
    'titulo' => 'Tuvimos un problema interno',
    'mensaje' => 'Algo falló de nuestro lado al procesar su solicitud. El error quedó registrado para que el equipo técnico lo revise.',
    'sugerencias' => [
        'Espere un momento e intente de nuevo.',
        'Si vuelve a ocurrir, avise al administrador contando qué estaba haciendo.',
    ],
])

@section('icono')
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 16 16" fill="currentColor">
        <path d="M7.938 2.016a.13.13 0 0 1 .125 0l6.857 11.856c.05.087-.012.196-.114.196H1.192c-.102 0-.164-.11-.114-.196L7.938 2.016zM8 5a.905.905 0 0 0-.9.995l.35 3.507a.552.552 0 0 0 1.1 0l.35-3.507A.905.905 0 0 0 8 5zm.002 6a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/>
    </svg>
@endsection
