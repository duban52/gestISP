<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Qué plantillas tiene Meta, en qué idioma y con cuántos huecos.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Meta elige la plantilla por NOMBRE + IDIOMA. Una cuenta puede tener
 * `factura_generada` en `es` con cuatro huecos y en `es_CO` con cinco;
 * si gestISP manda el idioma que no es, el cliente recibe el mensaje
 * viejo —sin el enlace— y nada falla a la vista. Eso es exactamente lo
 * que pasó, y adivinarlo desde los mensajes recibidos cuesta días.
 *
 * Este comando lo responde de una: nombre, idioma, estado, número de
 * variables del cuerpo y si lleva cabecera de documento.
 */
class ListWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:plantillas
                            {--filtro= : Solo las plantillas cuyo nombre contenga esto}
                            {--waba= : Identificador de la cuenta de WhatsApp Business, si no está en el .env}';

    protected $description = 'Lista las plantillas de WhatsApp aprobadas en Meta, con su idioma y sus variables';

    /**
     * El identificador de la cuenta, preguntándoselo al propio token.
     *
     * POR QUÉ: una plantilla puede estar aprobada en una cuenta y el
     * número que envía colgar de otra; entonces Meta resuelve la versión
     * vieja y el mensaje sale mal sin que nada lo explique. Pedirle el
     * dato a la persona es una vuelta más, y el token ya lo sabe: los
     * permisos de WhatsApp vienen marcados con las cuentas que alcanzan.
     *
     * @param  array<string, mixed>  $config
     */
    private function descubrirWaba(array $config): ?string
    {
        $respuesta = Http::acceptJson()->timeout(20)->get('https://graph.facebook.com/' . $config['api_version'] . '/debug_token', [
            'input_token' => $config['token'],
            'access_token' => $config['token'],
        ]);

        $cuentas = collect($respuesta->json('data.granular_scopes', []))
            ->whereIn('scope', ['whatsapp_business_messaging', 'whatsapp_business_management'])
            ->flatMap(fn ($permiso) => $permiso['target_ids'] ?? [])
            ->unique()
            ->values();

        if ($cuentas->isEmpty()) {
            // Un token de usuario no siempre puede inspeccionarse a sí
            // mismo. Si Meta explicó el motivo, se muestra: buscarlo a
            // ciegas cuesta más que leerlo.
            if ($motivo = $respuesta->json('error.message')) {
                $this->line('Meta no lo dijo: <comment>' . $motivo . '</comment>');
            }

            return null;
        }

        $this->line('Cuenta de WhatsApp Business encontrada en el token: <info>' . $cuentas->first() . '</info>');

        // Varias cuentas es justo el caso que enreda: la plantilla puede
        // estar aprobada en una y el número enviando desde otra.
        if ($cuentas->count() > 1) {
            $this->warn('El token alcanza ' . $cuentas->count() . ' cuentas: ' . $cuentas->implode(', ') . '.');
            $this->line('Se lista la primera. Repita con --waba= para ver las otras.');
        }

        return (string) $cuentas->first();
    }

    /**
     * Que el número que ENVÍA sea de la cuenta cuyas plantillas se
     * están mirando.
     *
     * LA TRAMPA: las plantillas pertenecen a una cuenta de WhatsApp
     * Business, no al negocio. Si el número cuelga de otra cuenta, Meta
     * resuelve la plantilla de ESA otra —vieja, con menos huecos— y
     * rechaza el envío, mientras en el manager se ve la versión buena y
     * aprobada. Costó media jornada.
     *
     * @param  array<string, mixed>  $config
     */
    private function verificarQueElNumeroPertenezca(array $config, string $waba): void
    {
        $numero = (string) ($config['phone_number_id'] ?? '');

        if ($numero === '') {
            return;
        }

        $respuesta = Http::withToken($config['token'])
            ->acceptJson()
            ->timeout(20)
            ->get(sprintf('https://graph.facebook.com/%s/%s/phone_numbers', $config['api_version'], $waba), [
                'fields' => 'id,display_phone_number,verified_name',
            ]);

        if (!$respuesta->successful()) {
            return;
        }

        $numeros = collect($respuesta->json('data', []));

        $this->newLine();

        if ($numeros->contains('id', $numero)) {
            $this->line('El número que envía (<info>' . $numero . '</info>) sí es de esta cuenta.');

            return;
        }

        $this->error('EL NÚMERO QUE ENVÍA NO ES DE ESTA CUENTA.');
        $this->line('WHATSAPP_META_PHONE_ID es <comment>' . $numero . '</comment>, y esta cuenta tiene: '
            . ($numeros->isEmpty() ? 'ninguno' : $numeros->map(fn ($n) => $n['display_phone_number'] . ' (' . $n['id'] . ')')->implode(', ')) . '.');
        $this->line('Las plantillas de arriba NO son las que usa ese número: Meta resuelve');
        $this->line('las de la cuenta a la que pertenece. Corrija la plantilla en esa otra');
        $this->line('cuenta, o apunte WHATSAPP_META_PHONE_ID a un número de esta.');
    }

    public function handle(): int
    {
        $config = config('notifications.whatsapp.meta');
        if (empty($config['token'])) {
            $this->error('Falta en el .env de ESTE servidor: WHATSAPP_META_TOKEN.');
            $this->line('Sin token no se le puede preguntar nada a Meta.');

            return self::FAILURE;
        }

        $waba = trim((string) $this->option('waba'))
            ?: ($config['business_account_id'] ?? null)
            ?: $this->descubrirWaba($config);

        if (empty($waba)) {
            $this->error('El token no dice a qué cuenta de WhatsApp Business pertenece.');
            $this->line('El WABA es el «Identificador de la cuenta de WhatsApp Business»:');
            $this->line('está junto al Phone Number ID, en la app de Meta for Developers →');
            $this->line('WhatsApp → Configuración de la API. Se puede pasar sin tocar el');
            $this->line('.env: <info>php artisan whatsapp:plantillas --waba=123456789</info>');
            $this->newLine();
            $this->line('Atajo: abra la plantilla en WhatsApp Manager y mire la dirección');
            $this->line('del navegador; lleva <info>waba_id=</info> con ese mismo número.');

            return self::FAILURE;
        }

        $respuesta = Http::withToken($config['token'])
            ->acceptJson()
            ->timeout(20)
            ->get(sprintf('https://graph.facebook.com/%s/%s/message_templates', $config['api_version'], $waba), [
                'fields' => 'name,language,status,category,components',
                'limit' => 200,
            ]);

        if (!$respuesta->successful()) {
            $this->error('Meta no respondió: ' . $respuesta->status());
            $this->line(json_encode($respuesta->json() ?? $respuesta->body(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $filtro = (string) $this->option('filtro');
        $filas = [];
        $cuerpos = [];

        foreach ($respuesta->json('data', []) as $plantilla) {
            if ($filtro !== '' && !str_contains($plantilla['name'], $filtro)) {
                continue;
            }

            $cuerpo = collect($plantilla['components'] ?? [])->firstWhere('type', 'BODY');
            $cabecera = collect($plantilla['components'] ?? [])->firstWhere('type', 'HEADER');

            $filas[] = [
                $plantilla['name'],
                $plantilla['language'],
                $plantilla['status'],
                // Los huecos son {{1}}, {{2}}…: se cuentan del propio texto.
                preg_match_all('/\{\{\d+\}\}/', $cuerpo['text'] ?? ''),
                $cabecera['format'] ?? '—',
                // Los botones también gastan variables, y no se ven en
                // el cuerpo: un botón de URL con sufijo dinámico pide su
                // propio parámetro aparte.
                collect($plantilla['components'] ?? [])->firstWhere('type', 'BUTTONS') ? 'sí' : '—',
            ];

            $cuerpos[$plantilla['name'] . ' (' . $plantilla['language'] . ')'] = $cuerpo['text'] ?? '';
        }

        if ($filas === []) {
            $this->warn('Meta no devolvió ninguna plantilla con ese filtro.');

            return self::SUCCESS;
        }

        $this->table(['Plantilla', 'Idioma', 'Estado', 'Variables', 'Cabecera', 'Botones'], $filas);

        // El texto aprobado, tal cual. Es lo único que dice si el
        // enlace quedó como variable {{5}} o escrito a mano.
        foreach ($cuerpos as $cual => $texto) {
            $this->newLine();
            $this->line('<comment>' . $cual . '</comment>');
            $this->line($texto);
        }

        $this->verificarQueElNumeroPertenezca($config, $waba);

        $this->newLine();
        $this->line('gestISP envía el idioma: <info>' . ($config['template_language'] ?? 'es') . '</info>'
            . ' (WHATSAPP_META_TEMPLATE_LANG).');
        $this->line('El enlace de la factura ocupa una variable más y está '
            . (($config['invoice_link_in_template'] ?? false) ? '<info>encendido</info>' : '<comment>apagado</comment>')
            . ' (WHATSAPP_META_INVOICE_LINK).');
        $this->line('El PDF adjunto exige cabecera DOCUMENT y está '
            . (($config['invoice_document_in_template'] ?? false) ? '<info>encendido</info>' : '<comment>apagado</comment>')
            . ' (WHATSAPP_META_INVOICE_DOC).');

        return self::SUCCESS;
    }
}
