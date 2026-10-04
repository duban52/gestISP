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
