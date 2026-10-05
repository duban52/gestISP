<?php

namespace App\Http\Controllers;

use App\Mail\CorreoDelSistema;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Envío de correos: configuración, interruptor y bitácora.
 *
 * SOLO EL SUPERADMINISTRADOR, y por el middleware de la ruta — el
 * mismo criterio que la trazabilidad, las copias de seguridad y las
 * acciones masivas. Desde aquí se apaga TODO el correo saliente de un
 * sistema que factura: no puede depender de un permiso marcable.
 *
 * LA CONTRASEÑA NO VUELVE A LA PANTALLA
 * -------------------------------------
 * Se guarda cifrada y el formulario la enseña vacía. Dejarla en
 * blanco al guardar significa «no la cambies», no «bórrala»: es la
 * única forma de poder corregir el puerto sin tener que volver a
 * teclear la contraseña del SMTP, y de que no viaje al navegador en
 * cada carga de la página.
 */
class MailSettingController extends Controller
{
    public function __construct(
        private readonly CorreoDelSistema $correo,
        private readonly AuditLogger $auditoria,
    ) {
    }

    public function index(Request $request): View
    {
        $ajustes = MailSetting::vigente();

        $logs = MailLog::query()
            ->when($request->filled('estado'), fn ($q) => $q->where('status', $request->estado))
            ->when($request->filled('buscar'), function ($q) use ($request) {
                $like = '%' . trim($request->buscar) . '%';
                $q->where(fn ($s) => $s->where('to', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('context', 'like', $like));
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('gestisp.system.mail', [
            'ajustes' => $ajustes,
            'logs' => $logs,
            'resumen' => $this->resumen(),
            'preajustes' => MailSetting::PREAJUSTES,
            // Lo que hay en el .env, para que se vea con qué está
            // enviando ahora mismo quien no ha configurado nada.
            'delEntorno' => [
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'from' => config('mail.from.address'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'enabled' => 'nullable|boolean',
            'preset' => 'nullable|string|max:20',
            'host' => 'nullable|string|max:150',
            'port' => 'nullable|integer|min:1|max:65535',
            'encryption' => 'nullable|in:tls,ssl,',
            'username' => 'nullable|string|max:190',
            'password' => 'nullable|string|max:190',
            'from_address' => 'nullable|email|max:190',
            'from_name' => 'nullable|string|max:150',
            'per_minute' => 'nullable|integer|min:1|max:6000',
        ], [
            'from_address.email' => 'El remitente tiene que ser una dirección de correo válida.',
            'port.integer' => 'El puerto es un número: 587 para TLS, 465 para SSL.',
        ]);

        $ajustes = MailSetting::first() ?? new MailSetting();

        $estabaEncendido = $ajustes->exists ? $ajustes->enabled : true;

        // Vacía significa «no la cambies». Ver la nota de la clase.
        if (blank($datos['password'] ?? null)) {
            unset($datos['password']);
        } else {
            $datos['password'] = $this->limpiarContrasena($datos['password']);
        }

        $datos['enabled'] = $request->boolean('enabled');
        $datos['updated_by'] = auth()->id();

        $ajustes->fill($datos)->save();

        CorreoDelSistema::olvidarCache();

        $this->auditoria->action(
            'mail.settings_updated',
            $estabaEncendido !== $ajustes->enabled
                ? ($ajustes->enabled
                    ? 'Encendió el envío de correos del sistema'
                    : 'APAGÓ el envío de correos del sistema')
                : 'Cambió la configuración de envío de correos',
            [
                'habilitado' => $ajustes->enabled,
                'servidor' => $ajustes->host ?: '(el del .env)',
                'puerto' => $ajustes->port,
                'remitente' => $ajustes->from_address,
                'por_minuto' => $ajustes->per_minute,
                // La contraseña NUNCA, ni para decir que cambió su
                // longitud. Solo si se tocó.
                'cambio_la_clave' => array_key_exists('password', $datos),
            ],
            $ajustes,
            'sistema',
        );

        return back()->with('success', $ajustes->enabled
            ? 'Configuración guardada. Use «Enviar correo de prueba» para comprobar que funciona.'
            : 'Configuración guardada. EL ENVÍO DE CORREOS ESTÁ APAGADO: no saldrá ninguno.');
    }

    /**
     * La contraseña del SMTP, tal como la pega una persona.
     *
     * DOS COSAS, Y LAS DOS HAN COSTADO UN RATO
     * ----------------------------------------
     * 1. El middleware `TrimStrings` NO toca los campos que se llaman
     *    `password` —y hace bien: una contraseña de acceso puede
     *    terminar en espacio a propósito—. Pero esta no es de acceso,
     *    es de un servidor, y un espacio pegado de más al copiar hace
     *    que el servidor la rechace con un error que no menciona
     *    ningún espacio.
     *
     * 2. Google enseña las contraseñas de aplicación en cuatro grupos
     *    de cuatro letras: «abcd efgh ijkl mnop». Son dieciséis
     *    letras, los espacios son de adorno, y pegarlas con espacios
     *    da exactamente el mismo «535 Username and Password not
     *    accepted» que una contraseña equivocada. Es la causa número
     *    uno de que esto no funcione a la primera.
     *
     * Solo se quitan los espacios de en medio cuando lo que queda son
     * dieciséis caracteres: es la forma de una contraseña de Google y
     * de nada más. Una contraseña de otro servidor que lleve un
     * espacio de verdad se respeta.
     */
    private function limpiarContrasena(string $clave): string
    {
        $clave = trim($clave);

        $sinEspacios = preg_replace('/\s+/u', '', $clave);

        return strlen($sinEspacios) === 16 ? $sinEspacios : $clave;
    }

    /**
     * Prueba de envío.
     *
     * Es la mitad del valor de esta pantalla: sin ella, una contraseña
     * mal escrita significa facturas que no llegan en silencio hasta
     * que un cliente reclama semanas después.
     */
    public function probar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'destino' => 'required|email',
        ], [
            'destino.required' => 'Indique a qué dirección se manda la prueba.',
            'destino.email' => 'Esa no es una dirección de correo válida.',
        ]);

        $resultado = $this->correo->enviarPrueba($datos['destino']);

        $this->auditoria->action(
            'mail.test_sent',
            sprintf('Envió un correo de prueba a %s (%s)', $datos['destino'], $resultado['ok'] ? 'salió' : 'falló'),
            ['destino' => $datos['destino'], 'ok' => $resultado['ok'], 'mensaje' => $resultado['mensaje']],
            null,
            'sistema',
        );

        return back()->with(
            $resultado['ok'] ? 'success' : 'error',
            $resultado['ok']
                ? $resultado['mensaje'] . ' Si no llega en unos minutos, revise la carpeta de no deseados.'
                : 'No se pudo enviar: ' . $resultado['mensaje'],
        );
    }

    /**
     * El servidor de salida de una SUCURSAL.
     *
     * Al cliente le escribe su operador: la factura y los avisos salen
     * de la sede que lo atiende, con su dominio y con un buzón al que
     * pueda responder. Lo de Gestión del sistema es otra cosa —correo
     * interno de GestISP— y sigue siendo el respaldo de las sedes que
     * no tengan el suyo.
     *
     * Ruta aparte y no dentro de BranchController::update(): ese
     * método arrastra reglas delicadas de facturación con `sometimes`,
     * y meterle once campos más es la forma de romper el guardado de
     * una sucursal por un campo de correo.
     */
    public function updateSucursal(Request $request, \App\Models\Branch $branch): RedirectResponse
    {
        $this->authorizeSucursal($branch);

        $datos = $request->validate([
            'enabled' => 'nullable|boolean',
            'preset' => 'nullable|string|max:20',
            'host' => 'nullable|string|max:150',
            'port' => 'nullable|integer|min:1|max:65535',
            'encryption' => 'nullable|in:tls,ssl,',
            'username' => 'nullable|string|max:190',
            'password' => 'nullable|string|max:190',
            'from_address' => 'nullable|email|max:190',
            'from_name' => 'nullable|string|max:150',
        ], [
            'from_address.email' => 'El remitente tiene que ser una dirección de correo válida.',
            'port.integer' => 'El puerto es un número: 587 para TLS, 465 para SSL.',
        ]);

        $ajustes = MailSetting::deLaSucursal($branch->id)
            ?? new MailSetting(['branch_id' => $branch->id, 'enabled' => true]);

        if (blank($datos['password'] ?? null)) {
            unset($datos['password']);
        } else {
            $datos['password'] = $this->limpiarContrasena($datos['password']);
        }

        $estabaEncendido = $ajustes->exists ? $ajustes->enabled : true;

        $datos['enabled'] = $request->boolean('enabled');
        $datos['updated_by'] = auth()->id();

        $ajustes->fill($datos)->save();

        CorreoDelSistema::olvidarCache();

        $this->auditoria->action(
            'mail.branch_settings_updated',
            match (true) {
                $estabaEncendido && !$ajustes->enabled
                    => sprintf('APAGÓ el envío de correos de la sucursal %s', $branch->name),
                !$estabaEncendido && $ajustes->enabled
                    => sprintf('Encendió el envío de correos de la sucursal %s', $branch->name),
                $ajustes->tieneServidorPropio()
                    => sprintf('Configuró el correo de la sucursal %s (%s)', $branch->name, $ajustes->host),
                default
                    => sprintf('Dejó la sucursal %s sin servidor propio: usará el del sistema', $branch->name),
            },
            [
                'sucursal' => $branch->name,
                'habilitado' => $ajustes->enabled,
                'servidor' => $ajustes->host ?: '(el del sistema)',
                'remitente' => $ajustes->from_address,
                'cambio_la_clave' => array_key_exists('password', $datos),
            ],
            $branch,
            'sistema',
        );

        if (!$ajustes->enabled) {
            return back()->with('success',
                'Guardado. ESTA SUCURSAL YA NO ENVÍA CORREOS: ni facturas, ni avisos, ni órdenes.');
        }

        return back()->with('success', $ajustes->tieneServidorPropio()
            ? 'Correo de la sucursal guardado. Pruébelo antes de confiarle una corrida.'
            : 'La sucursal enviará por el servidor del sistema.');
    }

    /**
     * Prueba el servidor de una sucursal, sin tocar el del sistema.
     */
    public function probarSucursal(Request $request, \App\Models\Branch $branch): RedirectResponse
    {
        $this->authorizeSucursal($branch);

        $datos = $request->validate(['destino' => 'required|email']);

        $mailer = app(\App\Mail\CorreoDeLaSucursal::class)->mailerDe($branch);

        if (!$mailer) {
            return back()->with('error',
                'Esta sucursal no tiene servidor propio: sus correos salen por el del sistema. '
                . 'Pruebe aquel desde Gestión del sistema → Envío de correos.');
        }

        $resultado = $this->correo->enviarPrueba($datos['destino'], $mailer);

        return back()->with(
            $resultado['ok'] ? 'success' : 'error',
            $resultado['ok']
                ? 'Salió desde el servidor de ' . $branch->name . ' hacia ' . $datos['destino'] . '.'
                : 'No se pudo enviar: ' . $resultado['mensaje'],
        );
    }

    /**
     * Solo quien pueda editar la sucursal toca su correo.
     *
     * Aquí NO se exige superadministrador: el correo comercial de una
     * sede lo configura quien la administra. Lo que sigue reservado es
     * el del sistema, que es el que manda los restablecimientos de
     * contraseña de todo el panel.
     */
    private function authorizeSucursal(\App\Models\Branch $branch): void
    {
        abort_unless(auth()->user()?->can('branches.edit'), 403);

        abort_unless(
            in_array((int) $branch->id, array_map('intval', app(\App\Tenancy\CurrentContext::class)->branchIds()), true),
            403,
            'Esa sucursal no es de su contexto.',
        );
    }

    /**
     * Poda la bitácora.
     *
     * Una corrida de facturación son mil filas. Sin poda esto crece
     * igual que creció `audits`, y el día que estorbe nadie se acuerda
     * de por qué.
     */
    public function podar(Request $request): RedirectResponse
    {
        $dias = (int) $request->input('dias', 90);
        $dias = max(7, min($dias, 365));

        $borradas = MailLog::where('created_at', '<', now()->subDays($dias))->delete();

        $this->auditoria->action(
            'mail.log_pruned',
            sprintf('Podó la bitácora de correos: %d registro(s) de más de %d días', $borradas, $dias),
            ['borradas' => $borradas, 'dias' => $dias],
            null,
            'sistema',
        );

        return back()->with('success', "Se borraron {$borradas} registro(s) con más de {$dias} días.");
    }

    /** Las cifras de la cabecera: las de los últimos 30 días. */
    private function resumen(): array
    {
        $desde = now()->subDays(30);

        return [
            'enviados' => MailLog::where('status', MailLog::ENVIADO)->where('created_at', '>=', $desde)->count(),
            'fallidos' => MailLog::where('status', MailLog::FALLIDO)->where('created_at', '>=', $desde)->count(),
            'omitidos' => MailLog::where('status', MailLog::OMITIDO)->where('created_at', '>=', $desde)->count(),
            'hoy' => MailLog::where('created_at', '>=', now()->startOfDay())->count(),
        ];
    }
}
