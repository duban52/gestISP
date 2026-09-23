<?php

namespace App\Notifications\WhatsApp;

use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proveedor WhatsApp Cloud API oficial de Meta.
 *
 * Envía mensajes al endpoint de Meta usando el Phone Number ID y el
 * token de acceso de la app. Puede mandar:
 *
 *  - Plantillas aprobadas (obligatorio para iniciar la conversación
 *    con un cliente fuera de la ventana de 24 h).
 *  - Texto libre (solo llega si el cliente escribió en las últimas
 *    24 h; útil para respuestas).
 *
 * El modo se controla en config/notifications.php (use_templates).
 */
class MetaCloudGateway implements WhatsAppGateway
{
    public function send(string $to, WhatsAppMessage $message): bool
    {
        $config = config('notifications.whatsapp.meta');

        if (empty($config['phone_number_id']) || empty($config['token'])) {
            Log::warning('WhatsApp Meta: faltan credenciales (WHATSAPP_META_PHONE_ID / WHATSAPP_META_TOKEN). No se envió el mensaje.');

            return false;
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $config['api_version'],
            $config['phone_number_id'],
        );

        try {
            $response = Http::withToken($config['token'])
                ->acceptJson()
                ->timeout(15)
                ->post($url, $this->payload($to, $message, $config));

            if ($response->successful()) {
                return true;
            }

            // LA PLANTILLA CAMBIÓ Y NADIE SE ENTERÓ.
            //
            // Meta rechaza el envío entero si el número de parámetros no
            // es el que espera la plantilla aprobada, y el cliente se
            // queda SIN AVISO. Pasó de verdad: se encendió el enlace
            // —cinco parámetros— contra una plantilla de cuatro y cada
            // factura generada dejó de avisarse.
            //
            // Meta dice cuántos esperaba, así que se reintenta UNA vez
            // con esos, recortando por el final —el último es el añadido
            // opcional—. El aviso sale sin el enlace, que es mucho mejor
            // que no salir.
            if ($esperados = $this->parametrosQueEsperaba($response->json())) {
                return $this->reintentarConMenosParametros($to, $message, $config, $url, $esperados);
            }

            // LA PLANTILLA NO EXISTE EN ESE IDIOMA.
            //
            // Meta resuelve la plantilla por NOMBRE + IDIOMA exacto: con
            // el sistema en `es_CO`, una plantilla creada solo en `es`
            // no existe y el envío se rechaza. Le venía pasando a
            // `orden_rechazada_tecnico` desde que se cambió el idioma
            // general, en silencio.
            if ($idioma = $this->idiomaSinRegion($response->json(), $message, $config)) {
                return $this->reintentarEnOtroIdioma($to, $message, $config, $url, $idioma);
            }

            // Un fallo del proveedor no debe tumbar el flujo de
            // negocio: se registra con detalle y se devuelve false.
            Log::error('WhatsApp Meta: el envío falló.', [
                'para' => $to,
                'estado' => $response->status(),
                'respuesta' => $response->json() ?? $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WhatsApp Meta: excepción al enviar.', [
                'para' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Arma el cuerpo de la petición según se envíe plantilla o texto.
     *
     * @return array<string, mixed>
     */
    private function payload(string $to, WhatsAppMessage $message, array $config): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
        ];

        $usarPlantilla = ($config['use_templates'] ?? true) && $message->templateName;

        if ($usarPlantilla) {
            return $base + [
                'type' => 'template',
                'template' => [
                    'name' => $message->templateName,
                    'language' => [
                        'code' => $message->templateLanguage
                            ?? $config['template_language']
                            ?? 'es',
                    ],
                    'components' => $this->templateComponents($message, $config),
                ],
            ];
        }

        // FUERA DE PLANTILLA SÍ SE PUEDE MANDAR EL ARCHIVO. Un mensaje
        // de tipo documento lleva el texto como pie, así que el cliente
        // recibe el PDF y la explicación en uno solo. (Solo funciona
        // dentro de la ventana de 24 h, igual que el texto libre.)
        if ($message->documentUrl) {
            return $base + [
                'type' => 'document',
                'document' => array_filter([
                    'link' => $message->documentUrl,
                    'filename' => $message->documentName,
                    'caption' => $message->body,
                ]),
            ];
        }

        return $base + [
            'type' => 'text',
            'text' => ['body' => $message->body],
        ];
    }

    /**
     * Cuántos parámetros esperaba la plantilla, si el rechazo fue por
     * eso y mandamos de más. Null en cualquier otro caso.
     *
     * @param  array<string, mixed>|null  $respuesta
     */
    private function parametrosQueEsperaba(?array $respuesta): ?int
    {
        if (($respuesta['error']['code'] ?? null) !== 132000) {
            return null;
        }

        $detalle = (string) ($respuesta['error']['error_data']['details'] ?? '');

        return preg_match('/expected number of params \((\d+)\)/', $detalle, $m)
            ? (int) $m[1]
            : null;
    }

    /**
     * Reintenta con los parámetros que la plantilla admite.
     *
     * Solo RECORTA: si la plantilla espera más de los que hay, no se
     * puede inventar lo que falta y el fallo se registra como cualquier
     * otro.
     *
     * @param  array<string, mixed>  $config
     */
    private function reintentarConMenosParametros(
        string $to,
        WhatsAppMessage $message,
        array $config,
        string $url,
        int $esperados,
    ): bool {
        $enviados = count($message->templateParams);

        Log::warning('WhatsApp Meta: la plantilla no tiene los parámetros que se le mandan.', [
            'plantilla' => $message->templateName,
            'idioma' => $message->templateLanguage ?? $config['template_language'] ?? 'es',
            'enviados' => $enviados,
            'esperados' => $esperados,
            'accion' => $esperados < $enviados
                ? 'se reintenta sin los sobrantes; revise la plantilla en Meta'
                : 'no se puede completar: revise la plantilla en Meta',
        ]);

        if ($esperados >= $enviados) {
            return false;
        }

        $recortado = clone $message;
        $recortado->templateParams = array_slice($message->templateParams, 0, $esperados);

        return $this->reenviar($to, $recortado, $config, $url);
    }

    /**
     * El idioma sin región (`es_CO` → `es`), si el rechazo fue porque
     * la plantilla no existe en el idioma que se mandó. Null si el
     * fallo es otro o el idioma ya venía sin región.
     *
     * @param  array<string, mixed>|null  $respuesta
     * @param  array<string, mixed>  $config
     */
    private function idiomaSinRegion(?array $respuesta, WhatsAppMessage $message, array $config): ?string
    {
        if (($respuesta['error']['code'] ?? null) !== 132001) {
            return null;
        }

        $idioma = $message->templateLanguage ?? $config['template_language'] ?? 'es';

        return str_contains($idioma, '_') ? explode('_', $idioma)[0] : null;
    }

    /**
     * Reintenta la misma plantilla en el idioma base.
     *
     * @param  array<string, mixed>  $config
     */
    private function reintentarEnOtroIdioma(
        string $to,
        WhatsAppMessage $message,
        array $config,
        string $url,
        string $idioma,
    ): bool {
        Log::warning('WhatsApp Meta: la plantilla no existe en ese idioma; se reintenta en el idioma base.', [
            'plantilla' => $message->templateName,
            'idioma' => $message->templateLanguage ?? $config['template_language'] ?? 'es',
            'reintento' => $idioma,
            'accion' => 'cree la traducción en Meta o ajuste WHATSAPP_META_TEMPLATE_LANG',
        ]);

        $otro = clone $message;
        $otro->templateLanguage = $idioma;

        return $this->reenviar($to, $otro, $config, $url);
    }

    /**
     * El segundo —y último— intento. Nunca encadena otro reintento.
     *
     * @param  array<string, mixed>  $config
     */
    private function reenviar(string $to, WhatsAppMessage $message, array $config, string $url): bool
    {
        $respuesta = Http::withToken($config['token'])
            ->acceptJson()
            ->timeout(15)
            ->post($url, $this->payload($to, $message, $config));

        if ($respuesta->successful()) {
            return true;
        }

        Log::error('WhatsApp Meta: el reintento también falló.', [
            'para' => $to,
            'estado' => $respuesta->status(),
            'respuesta' => $respuesta->json() ?? $respuesta->body(),
        ]);

        return false;
    }

    /**
     * Los "components" de la plantilla: la cabecera con el documento,
     * si la plantilla la tiene, y el cuerpo con sus parámetros.
     *
     * LA CABECERA SOLO SI LA PLANTILLA APROBADA LA LLEVA. Mandar una
     * cabecera de documento a una plantilla que no la tiene hace que
     * Meta rechace el envío entero y el cliente se quede sin aviso, que
     * es peor que quedarse sin el archivo. Por eso va tras un
     * interruptor que se enciende DESPUÉS de aprobar la plantilla.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array>
     */
    private function templateComponents(WhatsAppMessage $message, array $config): array
    {
        $components = [];

        if ($message->documentUrl && ($config['invoice_document_in_template'] ?? false)) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => 'document',
                    'document' => array_filter([
                        'link' => $message->documentUrl,
                        'filename' => $message->documentName,
                    ]),
                ]],
            ];
        }

        if (!empty($message->templateParams)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($valor) => ['type' => 'text', 'text' => (string) $valor],
                    $message->templateParams,
                ),
            ];
        }

        return $components;
    }
}
