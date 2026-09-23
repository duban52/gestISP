<?php

namespace Tests\Feature\Notifications;

use App\Notifications\WhatsApp\MetaCloudGateway;
use App\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TestWhatsAppTemplateCommandTest extends TestCase
{
    public function test_envia_la_plantilla_activa_con_sus_parametros(): void
    {
        config([
            'notifications.whatsapp.driver' => 'meta',
            // El comando avisa antes de intentar nada si faltan: este
            // servidor podría no ser el que manda los WhatsApp.
            'notifications.whatsapp.meta.phone_number_id' => '123456',
            'notifications.whatsapp.meta.token' => 'un-token',
        ]);

        $gateway = $this->mock(WhatsAppGateway::class);
        $gateway->shouldReceive('send')
            ->once()
            ->withArgs(function (string $phone, $message): bool {
                return $phone === '573155554433'
                    && $message->templateName === 'jaspers_market_order_confirm'
                    && $message->templateLanguage === 'en_US'
                    && $message->templateParams === ['Duban'];
            })
            ->andReturnTrue();

        $this->artisan('whatsapp:test', [
            'phone' => '315 555 4433',
            '--param' => ['Duban'],
        ])
            ->expectsOutputToContain('Meta aceptó')
            ->assertSuccessful();
    }

    /**
     * «Meta aceptó» mientras el cliente recibe el mensaje sin enlace es
     * la peor salida posible: se probó media mañana contra una plantilla
     * de 4 variables creyendo que el fallo estaba en gestISP.
     */
    public function test_si_hubo_que_recortar_parametros_la_prueba_no_pasa(): void
    {
        config([
            'notifications.whatsapp.driver' => 'meta',
            'notifications.whatsapp.meta.phone_number_id' => '123456',
            'notifications.whatsapp.meta.token' => 'un-token',
            'notifications.whatsapp.meta.api_version' => 'v21.0',
            'notifications.whatsapp.meta.use_templates' => true,
            'notifications.whatsapp.meta.template_language' => 'es_CO',
        ]);

        Http::fakeSequence()
            ->push(['error' => [
                'code' => 132000,
                'error_data' => ['details' => 'body: number of localizable_params (5) does not match the expected number of params (4)'],
            ]], 400)
            ->push(['messages' => [['id' => 'wamid.x']]], 200);

        $this->app->singleton(WhatsAppGateway::class, fn () => new MetaCloudGateway());

        $this->artisan('whatsapp:test', [
            'phone' => '315 555 4433',
            '--template' => 'factura_generada',
            '--language' => 'es_CO',
            '--param' => ['Ana', 'FAC-1', '$80.000', '13/10/2026', 'https://gestisp.test/f/abc'],
        ])
            ->expectsOutputToContain('solo acepta 4 variables')
            ->assertFailed();
    }

    public function test_avisa_si_a_este_servidor_le_faltan_las_credenciales(): void
    {
        // Pasó de verdad: se probaba desde una máquina sin credenciales
        // y el «revise el log» no explicaba nada.
        config([
            'notifications.whatsapp.driver' => 'meta',
            'notifications.whatsapp.meta.phone_number_id' => null,
            'notifications.whatsapp.meta.token' => null,
        ]);

        $this->artisan('whatsapp:test', ['phone' => '315 555 4433'])
            ->expectsOutputToContain('WHATSAPP_META_PHONE_ID')
            ->assertFailed();
    }

    public function test_no_intenta_un_envio_real_si_el_driver_no_es_meta(): void
    {
        config(['notifications.whatsapp.driver' => 'log']);

        $this->artisan('whatsapp:test', ['phone' => '315 555 4433'])
            ->expectsOutputToContain('WHATSAPP_DRIVER debe ser "meta"')
            ->assertFailed();
    }
}
