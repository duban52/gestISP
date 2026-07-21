<?php

/**
 * Configuración del sistema de notificaciones de GestISP.
 *
 * Controla por qué canales se avisa (correo, WhatsApp, navegador) y
 * cómo se conecta con el proveedor de WhatsApp. El proveedor es
 * intercambiable: hoy puede ir en modo "log" (simulado, no envía
 * nada real, solo lo registra) y cambiarse a Meta Cloud API cuando
 * estén las credenciales, sin tocar el resto del código.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Interruptor maestro por canal
    |--------------------------------------------------------------------------
    | Permite apagar un canal completo sin desenganchar nada. Útil,
    | por ejemplo, para dejar WhatsApp apagado hasta tener aprobadas
    | las plantillas en Meta.
    */
    'channels' => [
        'mail' => (bool) env('NOTIFY_MAIL_ENABLED', true),
        'whatsapp' => (bool) env('NOTIFY_WHATSAPP_ENABLED', true),
        'database' => (bool) env('NOTIFY_DATABASE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    | driver:
    |   'log'  → No envía nada; escribe el mensaje en storage/logs.
    |            Sirve para desarrollo y para dejar todo funcionando
    |            antes de contratar el proveedor.
    |   'meta' → WhatsApp Cloud API oficial de Meta.
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),

        // País por defecto para normalizar los números que se
        // guardan sin indicativo (Colombia = 57).
        'default_country_code' => env('WHATSAPP_COUNTRY_CODE', '57'),

        'meta' => [
            // Identificador del número emisor (Phone Number ID) que
            // da Meta, y el token permanente de la app.
            'phone_number_id' => env('WHATSAPP_META_PHONE_ID'),
            'token' => env('WHATSAPP_META_TOKEN'),
            'api_version' => env('WHATSAPP_META_API_VERSION', 'v21.0'),

            // Meta exige plantillas aprobadas para iniciar una
            // conversación. Si se deja en true, los mensajes se
            // envían como plantilla; el nombre y el idioma se toman
            // de cada notificación. En false se envía texto libre
            // (solo funciona dentro de la ventana de 24 h de una
            // conversación ya abierta por el cliente).
            'use_templates' => (bool) env('WHATSAPP_META_USE_TEMPLATES', true),
            'template_language' => env('WHATSAPP_META_TEMPLATE_LANG', 'es'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Avisos de facturación
    |--------------------------------------------------------------------------
    | Con cuántos días de anticipación se avisa del vencimiento de una
    | factura. Puede ser una lista ("5,2" avisa a 5 y a 2 días).
    */
    'invoice' => [
        'due_soon_days' => array_filter(array_map(
            'intval',
            explode(',', (string) env('NOTIFY_DUE_SOON_DAYS', '3'))
        )),
    ],
];
