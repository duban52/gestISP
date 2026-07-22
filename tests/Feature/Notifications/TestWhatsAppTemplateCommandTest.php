<?php

namespace Tests\Feature\Notifications;

use App\Notifications\WhatsApp\WhatsAppGateway;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TestWhatsAppTemplateCommandTest extends TestCase
{
    public function test_envia_la_plantilla_activa_con_sus_parametros(): void
    {
        config(['notifications.whatsapp.driver' => 'meta']);

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

    public function test_no_intenta_un_envio_real_si_el_driver_no_es_meta(): void
    {
        config(['notifications.whatsapp.driver' => 'log']);

        $this->artisan('whatsapp:test', ['phone' => '315 555 4433'])
            ->expectsOutputToContain('WHATSAPP_DRIVER debe ser "meta"')
            ->assertFailed();
    }
}
