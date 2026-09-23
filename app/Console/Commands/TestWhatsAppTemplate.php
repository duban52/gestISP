<?php

namespace App\Console\Commands;

use App\Notifications\Messages\WhatsAppMessage;
use App\Notifications\WhatsApp\WhatsAppGateway;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Envía una plantilla de prueba sin disparar eventos de negocio.
 *
 * Permite comprobar credenciales, Phone Number ID, plantilla,
 * parámetros e idioma de Meta antes de habilitar los avisos reales
 * de contratos, facturas u órdenes.
 */
class TestWhatsAppTemplate extends Command
{
    protected $signature = 'whatsapp:test
                            {phone : Celular destino, con o sin indicativo}
                            {--template=jaspers_market_order_confirm : Nombre exacto de la plantilla activa}
                            {--language=en_US : Idioma de la plantilla según Meta, por ejemplo en_US}
                            {--param=* : Valor de cada variable de la plantilla, en orden}';

    protected $description = 'Prueba una plantilla de WhatsApp sin ejecutar notificaciones reales';

    public function handle(WhatsAppGateway $gateway): int
    {
        if (config('notifications.whatsapp.driver') !== 'meta') {
            $this->error('WHATSAPP_DRIVER debe ser "meta" para enviar una prueba real. Actualmente está en "' . config('notifications.whatsapp.driver') . '".');

            return self::FAILURE;
        }

        // Sin credenciales el envío falla igual, pero el error de Meta
        // no llega a existir: era un «revise el log» que no explicaba
        // nada. Mejor decirlo aquí.
        $faltan = array_keys(array_filter([
            'WHATSAPP_META_PHONE_ID' => empty(config('notifications.whatsapp.meta.phone_number_id')),
            'WHATSAPP_META_TOKEN' => empty(config('notifications.whatsapp.meta.token')),
        ]));

        if ($faltan !== []) {
            $this->error('Falta en el .env de ESTE servidor: ' . implode(' y ', $faltan) . '.');
            $this->line('Sin eso no se puede enviar nada: este servidor no es el que manda los WhatsApp.');

            return self::FAILURE;
        }

        $phone = PhoneNumber::forWhatsApp($this->argument('phone'));

        if (!$phone) {
            $this->error('El número no es válido. Use un celular de 10 dígitos o formato internacional.');

            return self::FAILURE;
        }

        $template = trim((string) $this->option('template'));
        $language = trim((string) $this->option('language'));

        if ($template === '' || $language === '') {
            $this->error('La plantilla y el idioma son obligatorios.');

            return self::FAILURE;
        }

        $message = WhatsAppMessage::make('Prueba técnica de GestISP')
            ->template($template, $this->option('param'))
            ->templateLanguage($language);

        $this->line("Enviando <info>{$template}</info> ({$language}) a <info>{$phone}</info>...");

        if (!$gateway->send($phone, $message)) {
            $this->error('Meta no aceptó el envío. Revise storage/logs/laravel.log para el detalle exacto.');

            return self::FAILURE;
        }

        $this->info('Meta aceptó la solicitud de prueba. Revise WhatsApp en el celular destino.');

        return self::SUCCESS;
    }
}
