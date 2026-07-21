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
                    'language' => ['code' => $config['template_language'] ?? 'es'],
                    'components' => $this->templateComponents($message->templateParams),
                ],
            ];
        }

        return $base + [
            'type' => 'text',
            'text' => ['body' => $message->body],
        ];
    }

    /**
     * Convierte los parámetros en la estructura de "components" que
     * Meta espera para el cuerpo de una plantilla.
     *
     * @param  array<int, string>  $params
     * @return array<int, array>
     */
    private function templateComponents(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(
                fn ($valor) => ['type' => 'text', 'text' => (string) $valor],
                $params,
            ),
        ]];
    }
}
