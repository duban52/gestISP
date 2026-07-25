{{-- ============================================================
     Avisos de las pantallas de autenticación

     Bloque único para los tres estados posibles:
       · session('status')  → confirmación (verde)
       · $errors            → error (rojo, con la lista de motivos)

     Se usa en login, recuperar y restablecer contraseña para que el
     mensaje se vea igual en todas.

     Los iconos van en SVG dentro del propio HTML: el layout de
     autenticación no carga Font Awesome, así que un <i class="fas">
     no se vería.
     ============================================================ --}}

<style>
    /* Entrada suave: llama la atención sin resultar molesta */
    .auth-alert {
        border: 0;
        border-left: 5px solid;
        border-radius: .5rem;
        box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .08);
        animation: authAlertIn .35s ease-out;
    }

    @keyframes authAlertIn {
        from { opacity: 0; transform: translateY(-6px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .auth-alert-danger  { border-left-color: #dc3545; background-color: #fdf2f3; color: #842029; }
    .auth-alert-success { border-left-color: #198754; background-color: #f0f9f4; color: #0f5132; }

    .auth-alert .auth-alert-icon { flex: 0 0 auto; margin-right: .75rem; }
    .auth-alert .auth-alert-title { font-weight: 700; margin-bottom: .15rem; }
    .auth-alert ul { margin: .35rem 0 0; padding-left: 1.1rem; }
    .auth-alert li + li { margin-top: .2rem; }
</style>

{{-- ---------- Confirmación ---------- --}}
@if (session('status'))
    <div class="alert auth-alert auth-alert-success d-flex align-items-start" role="alert">
        <svg class="auth-alert-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
        </svg>
        <div>
            <div class="auth-alert-title">Todo listo</div>
            {{ session('status') }}
        </div>
    </div>
@endif

{{-- ---------- Errores ---------- --}}
@if ($errors->any())
    <div class="alert auth-alert auth-alert-danger d-flex align-items-start" role="alert">
        <svg class="auth-alert-icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
             viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
            <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767L8.982 1.566zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5zm.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/>
        </svg>
        <div>
            <div class="auth-alert-title">
                {{ $errors->count() > 1 ? 'Revise lo siguiente' : 'No pudimos continuar' }}
            </div>
            @if ($errors->count() > 1)
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @else
                {{ $errors->first() }}
            @endif
        </div>
    </div>
@endif
