<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Messages\WhatsAppMessage;
use App\Notifications\WhatsApp\MetaCloudGateway;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conector de WhatsApp con Meta Cloud API y normalización de números.
 */
class WhatsAppGatewayTest extends TestCase
{
    // ==================== Normalización ====================

    public function test_normaliza_numeros_colombianos(): void
    {
        $this->assertSame('573155554433', PhoneNumber::forWhatsApp('315 555 4433'));
        $this->assertSame('573155554433', PhoneNumber::forWhatsApp('+57 315 555 4433'));
        $this->assertSame('573155554433', PhoneNumber::forWhatsApp('(315) 555-4433'));
        $this->assertSame('573155554433', PhoneNumber::forWhatsApp('573155554433'));
    }

    public function test_descarta_numeros_invalidos(): void
    {
        $this->assertNull(PhoneNumber::forWhatsApp('123'));
        $this->assertNull(PhoneNumber::forWhatsApp(''));
        $this->assertNull(PhoneNumber::forWhatsApp(null));
    }

    // ==================== Gateway Meta ====================

    private function configurarMeta(): void
    {
        config([
            'notifications.whatsapp.meta.phone_number_id' => '123456',
            'notifications.whatsapp.meta.token' => 'un-token',
            'notifications.whatsapp.meta.api_version' => 'v21.0',
            'notifications.whatsapp.meta.use_templates' => true,
            'notifications.whatsapp.meta.template_language' => 'es',
        ]);
    }

    public function test_envia_una_plantilla_a_la_api_de_meta(): void
    {
        $this->configurarMeta();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);

        $mensaje = WhatsAppMessage::make('texto de respaldo')
            ->template('bienvenida_cliente', ['Ana', 'EasyNet']);

        $ok = (new MetaCloudGateway())->send('573155554433', $mensaje);

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return str_contains($request->url(), '/v21.0/123456/messages')
                && $request->hasHeader('Authorization', 'Bearer un-token')
                && $cuerpo['to'] === '573155554433'
                && $cuerpo['type'] === 'template'
                && $cuerpo['template']['name'] === 'bienvenida_cliente'
                && $cuerpo['template']['components'][0]['parameters'][0]['text'] === 'Ana';
        });
    }

    public function test_un_fallo_de_meta_no_lanza_excepcion(): void
    {
        $this->configurarMeta();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'x']], 400),
        ]);

        // No debe lanzar: un WhatsApp caído no puede tumbar el flujo
        // de negocio que lo disparó.
        $ok = (new MetaCloudGateway())->send('573155554433', WhatsAppMessage::make('hola'));

        $this->assertFalse($ok);
    }

    public function test_sin_credenciales_no_intenta_enviar(): void
    {
        config([
            'notifications.whatsapp.meta.phone_number_id' => null,
            'notifications.whatsapp.meta.token' => null,
        ]);

        Http::fake();

        $ok = (new MetaCloudGateway())->send('573155554433', WhatsAppMessage::make('hola'));

        $this->assertFalse($ok);
        Http::assertNothingSent();
    }
}
