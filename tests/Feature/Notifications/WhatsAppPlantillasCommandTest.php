<?php

namespace Tests\Feature\Notifications;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El comando que dice qué plantillas tiene Meta.
 *
 * POR QUÉ EXISTE: Meta elige la plantilla por NOMBRE + IDIOMA. Con
 * `factura_generada` en `es` (cuatro huecos) y en `es_CO` (cinco), el
 * cliente recibe el mensaje viejo —sin enlace— y nada falla a la vista.
 * Este comando lo enseña de una.
 */
class WhatsAppPlantillasCommandTest extends TestCase
{
    private function configurar(array $extra = []): void
    {
        config(array_merge([
            'notifications.whatsapp.meta.token' => 'un-token',
            'notifications.whatsapp.meta.business_account_id' => '999',
            'notifications.whatsapp.meta.api_version' => 'v21.0',
            'notifications.whatsapp.meta.template_language' => 'es',
            'notifications.whatsapp.meta.invoice_link_in_template' => true,
        ], $extra));
    }

    public function test_muestra_cada_plantilla_con_su_idioma_y_sus_variables(): void
    {
        $this->configurar();

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            [
                'name' => 'factura_generada',
                'language' => 'es',
                'status' => 'APPROVED',
                'components' => [
                    ['type' => 'BODY', 'text' => 'Hola {{1}}, su factura {{2}} por {{3}}. Vence el {{4}}.'],
                ],
            ],
            [
                'name' => 'factura_generada',
                'language' => 'es_CO',
                'status' => 'APPROVED',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'DOCUMENT'],
                    ['type' => 'BODY', 'text' => 'Hola {{1}}, su factura {{2}} por {{3}}. Vence el {{4}}. Descárgala aquí: {{5}}'],
                ],
            ],
        ]], 200)]);

        $this->artisan('whatsapp:plantillas --filtro=factura')
            ->expectsTable(
                ['Plantilla', 'Idioma', 'Estado', 'Variables', 'Cabecera'],
                [
                    ['factura_generada', 'es', 'APPROVED', 4, '—'],
                    ['factura_generada', 'es_CO', 'APPROVED', 5, 'DOCUMENT'],
                ],
            )
            ->assertSuccessful();
    }

    public function test_sin_la_cuenta_de_negocio_dice_donde_encontrarla(): void
    {
        $this->configurar(['notifications.whatsapp.meta.business_account_id' => null]);

        $this->artisan('whatsapp:plantillas')
            ->expectsOutputToContain('WHATSAPP_META_WABA_ID')
            ->assertFailed();
    }
}
