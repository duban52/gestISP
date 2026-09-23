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
    protected $signature = 'whatsapp:plantillas {--filtro= : Solo las plantillas cuyo nombre contenga esto}';

    protected $description = 'Lista las plantillas de WhatsApp aprobadas en Meta, con su idioma y sus variables';

    public function handle(): int
    {
        $config = config('notifications.whatsapp.meta');
        $waba = $config['business_account_id'] ?? null;

        $faltan = array_keys(array_filter([
            'WHATSAPP_META_TOKEN' => empty($config['token']),
            'WHATSAPP_META_WABA_ID' => empty($waba),
        ]));

        if ($faltan !== []) {
            $this->error('Falta en el .env de ESTE servidor: ' . implode(' y ', $faltan) . '.');
            $this->line('El WABA es el «Identificador de la cuenta de WhatsApp Business»,');
            $this->line('en Meta Business → WhatsApp Manager → Configuración de la cuenta.');

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
            ];
        }

        if ($filas === []) {
            $this->warn('Meta no devolvió ninguna plantilla con ese filtro.');

            return self::SUCCESS;
        }

        $this->table(['Plantilla', 'Idioma', 'Estado', 'Variables', 'Cabecera'], $filas);

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
