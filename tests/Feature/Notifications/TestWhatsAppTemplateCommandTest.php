<?php

namespace Tests\Feature\Notifications;

use App\Notifications\WhatsApp\WhatsAppGateway;
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
