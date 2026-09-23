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

    public function test_una_plantilla_puede_definir_su_propio_idioma(): void
    {
        $this->configurarMeta();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200),
        ]);

        $mensaje = WhatsAppMessage::make('Prueba')
            ->template('jaspers_market_order_confirm', ['Duban'])
            ->templateLanguage('en_US');

        (new MetaCloudGateway())->send('573155554433', $mensaje);

        Http::assertSent(fn ($request) => $request->data()['template']['language']['code'] === 'en_US');
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

    // ============ La plantilla cambió y nadie se enteró ============

    /**
     * El rechazo por número de parámetros deja al cliente SIN AVISO.
     *
     * Pasó de verdad: se encendió el enlace de la factura —cinco
     * parámetros— contra una plantilla aprobada de cuatro, y Meta
     * rechazó cada envío. Mejor el aviso sin el enlace que nada.
     */
    public function test_si_la_plantilla_tiene_menos_huecos_reintenta_sin_los_sobrantes(): void
    {
        $this->configurarMeta();

        Http::fakeSequence()
            ->push(['error' => [
                'code' => 132000,
                'message' => '(#132000) Number of parameters does not match the expected number of params',
                'error_data' => ['details' => 'body: number of localizable_params (5) does not match the expected number of params (4)'],
            ]], 400)
            ->push(['messages' => [['id' => 'wamid.x']]], 200);

        $mensaje = WhatsAppMessage::make('texto')
            ->template('factura_generada', ['Ana', 'FAC-1', '$80.000', '13/10/2026', 'https://gestisp.test/f/abc']);

        $ok = (new MetaCloudGateway())->send('573155554433', $mensaje);

        $this->assertTrue($ok);

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $parametros = $request->data()['template']['components'][0]['parameters'] ?? [];

            // El segundo intento va sin el enlace, que es el último.
            return count($parametros) === 4
                && end($parametros)['text'] === '13/10/2026';
        });
    }

    public function test_si_la_plantilla_pide_mas_huecos_no_se_inventa_ninguno(): void
    {
        $this->configurarMeta();

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'code' => 132000,
            'error_data' => ['details' => 'body: number of localizable_params (2) does not match the expected number of params (4)'],
        ]], 400)]);

        $mensaje = WhatsAppMessage::make('texto')->template('factura_generada', ['Ana', 'FAC-1']);

        $this->assertFalse((new MetaCloudGateway())->send('573155554433', $mensaje));

        // Un solo intento: rellenar huecos a ciegas mandaría basura.
        Http::assertSentCount(1);
    }

    /**
     * Con el sistema en `es_CO`, una plantilla creada solo en `es` no
     * existe para Meta. Le pasaba a `orden_rechazada_tecnico`: el
     * técnico nunca se enteraba de que le rechazaron la orden.
     */
    public function test_si_la_plantilla_no_existe_en_es_co_se_reintenta_en_es(): void
    {
        $this->configurarMeta();
        config(['notifications.whatsapp.meta.template_language' => 'es_CO']);

        Http::fakeSequence()
            ->push(['error' => [
                'code' => 132001,
                'message' => '(#132001) Template name does not exist in the translation',
                'error_data' => ['details' => 'template name (orden_rechazada_tecnico) does not exist in es_CO'],
            ]], 400)
            ->push(['messages' => [['id' => 'wamid.x']]], 200);

        $mensaje = WhatsAppMessage::make('texto')->template('orden_rechazada_tecnico', ['Pedro']);

        $this->assertTrue((new MetaCloudGateway())->send('573155554433', $mensaje));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->data()['template']['language']['code'] === 'es');
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
