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
            'notifications.whatsapp.meta.phone_number_id' => null,
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
                ['Plantilla', 'Idioma', 'Estado', 'Variables', 'Cabecera', 'Botones'],
                [
                    ['factura_generada', 'es', 'APPROVED', 4, '—', '—'],
                    ['factura_generada', 'es_CO', 'APPROVED', 5, 'DOCUMENT', '—'],
                ],
            )
            ->assertSuccessful();
    }

    /**
     * Las plantillas son de una CUENTA, no del negocio. Con el número
     * colgando de otra cuenta, Meta resuelve la plantilla vieja de esa
     * otra mientras en el manager se ve la buena y aprobada.
     */
    public function test_avisa_si_el_numero_que_envia_no_es_de_esa_cuenta(): void
    {
        $this->configurar(['notifications.whatsapp.meta.phone_number_id' => '1248998741624435']);

        Http::fake([
            'graph.facebook.com/*/phone_numbers*' => Http::response(['data' => [
                ['id' => '999000111', 'display_phone_number' => '+57 300 0000000'],
            ]], 200),
            'graph.facebook.com/*' => Http::response(['data' => [[
                'name' => 'factura_generada',
                'language' => 'es_CO',
                'status' => 'APPROVED',
                'components' => [['type' => 'BODY', 'text' => 'Hola {{1}} {{2}} {{3}} {{4}} {{5}}']],
            ]]], 200),
        ]);

        $this->artisan('whatsapp:plantillas --filtro=factura --waba=1753691595833926')
            ->expectsOutputToContain('EL NÚMERO QUE ENVÍA NO ES DE ESTA CUENTA')
            ->assertSuccessful();
    }

    public function test_la_cuenta_de_negocio_se_puede_pasar_por_parametro(): void
    {
        // Para no tener que editar el .env solo para mirar.
        $this->configurar(['notifications.whatsapp.meta.business_account_id' => null]);

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

        $this->artisan('whatsapp:plantillas --waba=123456789')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/123456789/message_templates'));
    }

    /**
     * El token sabe a qué cuentas alcanza, así que no hay por qué
     * pedirle el dato a nadie. Importa cuando la plantilla está
     * aprobada en una cuenta y el número envía desde otra: ahí Meta
     * resuelve la versión vieja y nada lo explica.
     */
    public function test_si_no_esta_configurada_se_la_pregunta_al_token(): void
    {
        $this->configurar(['notifications.whatsapp.meta.business_account_id' => null]);

        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['777888999']],
                ],
            ]], 200),
            'graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('whatsapp:plantillas')
            ->expectsOutputToContain('777888999')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/777888999/message_templates'));
    }

    public function test_sin_token_ni_cuenta_dice_que_falta(): void
    {
        $this->configurar([
            'notifications.whatsapp.meta.token' => null,
            'notifications.whatsapp.meta.business_account_id' => null,
        ]);

        $this->artisan('whatsapp:plantillas')
            ->expectsOutputToContain('WHATSAPP_META_TOKEN')
            ->assertFailed();
    }
}
