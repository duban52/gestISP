{{-- ============================================================
     Página de error de GestISP

     Reemplaza la pantalla negra y genérica de Laravel ("NO TIENES
     PERMISO PARA REALIZAR ESTA ACCIÓN") por una que explique qué
     pasó, por qué y qué puede hacer el usuario a continuación.

     Las vistas concretas (403, 404, 419, 500...) definen:
       $codigo, $titulo, $mensaje, $sugerencias (array, opcional)
     ============================================================ --}}
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo ?? 'Error' }} — GestISP</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: 'Roboto', system-ui, sans-serif;
            background: linear-gradient(135deg, #1F4E79 0%, #16324f 100%);
            color: #333;
        }
        .card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, .25);
            max-width: 560px;
            width: 100%;
            padding: 2.25rem 2rem;
            text-align: center;
            animation: aparecer .35s ease-out;
        }
        @keyframes aparecer {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .logo { max-width: 190px; margin-bottom: 1.25rem; }
        .icono {
            width: 64px; height: 64px;
            margin: 0 auto .85rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
            background: #fdf2f3;
            color: #dc3545;
        }
        .codigo {
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #8a94a6;
            margin-bottom: .3rem;
        }
        h1 {
            font-size: 1.45rem;
            font-weight: 700;
            color: #1F4E79;
            margin: 0 0 .6rem;
        }
        p.mensaje { font-size: 1rem; line-height: 1.55; color: #4a5568; margin: 0 0 1.1rem; }
        ul.sugerencias {
            text-align: left;
            display: inline-block;
            margin: 0 0 1.35rem;
            padding-left: 1.15rem;
            color: #4a5568;
            font-size: .93rem;
            line-height: 1.6;
        }
        .acciones { display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-block;
            padding: .6rem 1.15rem;
            border-radius: 7px;
            text-decoration: none;
            font-size: .95rem;
            font-weight: 500;
            transition: opacity .15s ease;
        }
        .btn:hover { opacity: .87; }
        .btn-primario { background: #1F4E79; color: #fff; }
        .btn-secundario { background: #eef1f5; color: #33475b; }
    </style>
</head>
<body>
    <div class="card">
        <img src="{{ asset('img/Logo-gestisp-full.png') }}" alt="GestISP" class="logo">

        <div class="icono">
            @yield('icono')
        </div>

        <div class="codigo">Error {{ $codigo ?? '' }}</div>
        <h1>{{ $titulo ?? 'Algo no salió como esperábamos' }}</h1>
        <p class="mensaje">{{ $mensaje ?? '' }}</p>

        @if(!empty($sugerencias))
            <ul class="sugerencias">
                @foreach($sugerencias as $sugerencia)
                    <li>{{ $sugerencia }}</li>
                @endforeach
            </ul>
        @endif

        <div class="acciones">
            <a href="{{ url('/') }}" class="btn btn-primario">Ir al inicio</a>
            <a href="javascript:history.back()" class="btn btn-secundario">Volver atrás</a>
        </div>
    </div>
</body>
</html>
