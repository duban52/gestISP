<?php

return [

    /*
    |--------------------------------------------------------------------------
    | El servicio de la DIAN
    |--------------------------------------------------------------------------
    |
    | La URL NO esta publicada en la documentacion de la DIAN: la expone
    | ella misma dentro de la cuenta del catalogo de cada facturador
    | (Participants -> Facturador), y es distinta en habilitacion y en
    | produccion.
    |
    | Mientras este vacia, el transporte por defecto es el SIMULADO: los
    | documentos se generan y se firman, pero no salen a ninguna parte.
    | Es deliberado — un transporte que dijera «aceptado» sin haber
    | hablado con nadie dejaria documentos marcados como validados por
    | la DIAN que la DIAN no ha visto nunca.
    |
    */

    'endpoint' => env('DIAN_ENDPOINT', ''),

    /*
    | Segundos que se espera una respuesta. El anexo (§12.4) considera
    | «demora» pasado un minuto, y esa demora tiene su propia cadencia de
    | reintentos.
    */
    'timeout' => env('DIAN_TIMEOUT', 60),

    /*
    | Con que se transmite. 'auto' usa el SOAP real si hay endpoint
    | configurado y el simulado si no; 'fake' fuerza el simulado incluso
    | habiendo endpoint, que es lo que se quiere en un entorno de pruebas
    | que apunta a una base copiada de produccion.
    */
    'transport' => env('DIAN_TRANSPORT', 'auto'),

];
