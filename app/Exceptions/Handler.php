<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manejo central de errores.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Cuando algo reventaba, el usuario veía una pantalla de error sin
 * nada que decir y quien tenía que arreglarlo se encontraba con un
 * log en el que no se podía buscar: miles de líneas y ninguna forma
 * de atar «lo que vio el usuario a las 3 de la tarde» con «la
 * excepción que quedó registrada».
 *
 * EL IDENTIFICADOR DE INCIDENTE
 * -----------------------------
 * Cada error inesperado recibe un código corto (ERR-XXXXXXXX) que se
 * ESCRIBE EN EL LOG y se ENSEÑA EN PANTALLA. Es lo único que hace
 * falta para pasar de «me salió un error» a la línea exacta del
 * servidor:
 *
 *     grep ERR-A1B2C3D4 storage/logs/laravel.log
 *
 * Es corto a propósito: alguien lo va a leer por teléfono o copiarlo
 * de una captura. Un UUID completo no se dicta.
 *
 * QUÉ NO SE REGISTRA
 * ------------------
 * Nunca contraseñas, tokens ni la entrada completa del formulario.
 * Se guarda el CONTEXTO de la operación —quién, dónde, qué ruta— y
 * la excepción; los datos del usuario no hacen falta para
 * diagnosticar y convierten el log en un depósito de información
 * personal que nadie vigila.
 */
class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /** El identificador de este incidente, para log y pantalla. */
    private ?string $incidente = null;

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Las excepciones «de guion» —un 404, una validación, un
            // 403— no son incidentes: pasan todos los días y llenarían
            // el log de ruido hasta tapar lo que sí importa.
            if (!$this->shouldReport($e)) {
                return;
            }

            Log::error($this->incidente() . ' ' . $e->getMessage(), $this->contexto($e));

            // Ya queda registrado aquí: devolver false evita que el
            // manejador de Laravel lo escriba una segunda vez.
            return false;
        });
    }

    /**
     * El identificador del incidente en curso.
     *
     * Se calcula una sola vez por petición: el mismo código tiene que
     * aparecer en el log y en la pantalla, que es justo lo que permite
     * atarlos.
     */
    private function incidente(): string
    {
        return $this->incidente ??= 'ERR-' . strtoupper(Str::random(8));
    }

    /**
     * El contexto técnico del fallo.
     *
     * @return array<string, mixed>
     */
    private function contexto(Throwable $e): array
    {
        $request = request();

        $contexto = [
            'incidente' => $this->incidente(),
            'excepcion' => $e::class,
            'archivo' => $e->getFile() . ':' . $e->getLine(),
            'ruta' => $request?->route()?->getName() ?: $request?->path(),
            'metodo' => $request?->method(),
            'url' => $request?->fullUrl(),
            'usuario' => Auth::id(),
            'ip' => $request?->ip(),
        ];

        // El SQL de una QueryException es lo que de verdad explica el
        // fallo (una clave foránea, una columna que no admite nulo).
        // Los VALORES no se guardan: ahí van cédulas, direcciones y
        // cualquier cosa que llevara el formulario.
        if ($e instanceof QueryException) {
            $contexto['sql'] = $e->getSql();
            $contexto['sql_codigo'] = $e->errorInfo[1] ?? null;
        }

        return $contexto;
    }

    /**
     * Fuerza la traducción de los mensajes de acción no autorizada.
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof AuthorizationException) {
            abort(403, __('auth.unauthorized'));
        }

        // LARAVEL RESUELVE PRIMERO. SOLO SE ENRIQUECE EL 500.
        //
        // La primera versión de esto interceptaba ANTES de llamar al
        // padre, y se tragaba las excepciones que saben responder por
        // su cuenta: `SucursalNoIndicada` define un `render()` que
        // devuelve un 422 con el error colgado del campo `branch_id`,
        // y mi atajo lo convertía en un 500 genérico. Es decir, la
        // «mejora» del manejo de errores destruía los errores bien
        // hechos que ya había. Lo cazó la suite completa.
        //
        // Ahora se deja que el padre haga su trabajo —excepciones con
        // `render()` propio, validaciones, 403, 404— y solo se toca lo
        // que de verdad acabó siendo un fallo del servidor.
        $respuesta = parent::render($request, $exception);

        // Una petición AJAX que recibe la PÁGINA de error completa deja
        // al JavaScript sin poder leer la respuesta: el usuario se
        // queda mirando un indicador girando sin fin. El error ocurrió
        // y en pantalla no pasó nada — la misma avería que esconder el
        // motivo, solo que disfrazada.
        if ($this->esperaJson($request) && $respuesta->getStatusCode() >= 500) {
            return response()->json([
                'ok' => false,
                'incidente' => $this->incidente(),
                'message' => 'Se presentó un inconveniente al procesar la solicitud. '
                    . 'El incidente quedó registrado con el código ' . $this->incidente() . '.',
            ], 500);
        }

        return $respuesta;
    }

    /** ¿Quien llama espera JSON y no una página? */
    private function esperaJson($request): bool
    {
        return $request
            && ($request->expectsJson() || $request->ajax() || $request->is('api/*'));
    }

    /**
     * El identificador se pasa a la vista de error para que el usuario
     * pueda dictarlo a quien le vaya a ayudar.
     *
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     */
    protected function renderHttpException(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e)
    {
        view()->share('incidente', $this->incidente);

        return parent::renderHttpException($e);
    }
}
