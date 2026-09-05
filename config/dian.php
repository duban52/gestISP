<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Los servicios de la DIAN
    |--------------------------------------------------------------------------
    |
    | Son DOS, no uno: habilitacion y produccion. Y en un sistema
    | multiempresa conviven, porque una empresa puede estar todavia
    | pasando su set de pruebas mientras otra ya factura de verdad. Cual
    | se usa lo decide el AMBIENTE del documento, que queda congelado
    | cuando se emite.
    |
    | Vienen puestas de fabrica: son publicas y no cambian entre
    | contribuyentes, asi que nadie tiene que copiarlas de ningun sitio.
    | Estan aqui por si la DIAN las mueve algun dia.
    |
    | La de habilitacion esta confirmada. La de produccion sigue el mismo
    | patron que las URL de catalogo del anexo (vpfe-hab / vpfe), pero
    | CONVIENE CONFIRMARLA antes de encender produccion.
    |
    | Ojo: la direccion que publica la DIAN suele llevar «?wsdl» al
    | final. Esa devuelve la DEFINICION del servicio, no es el endpoint.
    | Se limpia sola, pero conviene saberlo.
    |
    */

    'endpoints' => [
        'habilitacion' => env(
            'DIAN_ENDPOINT_HABILITACION',
            'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc',
        ),

        'produccion' => env(
            'DIAN_ENDPOINT_PRODUCCION',
            'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc',
        ),
    ],

    /*
    | Override global. Gana sobre las dos de arriba y sirve para apuntar
    | a un intermediario o a un entorno propio de pruebas. Vacio en
    | condiciones normales.
    */
    'endpoint' => env('DIAN_ENDPOINT', ''),

    /*
    | Segundos que se espera una respuesta. El anexo (§12.4) considera
    | «demora» pasado un minuto, y esa demora tiene su propia cadencia de
    | reintentos.
    */
    'timeout' => env('DIAN_TIMEOUT', 60),

    /*
    | Con que se transmite. 'auto' usa el SOAP real; 'fake' fuerza el
    | simulado, que es lo que se quiere en un entorno de pruebas que
    | apunta a una copia de la base de produccion — para que no salga
    | nada de verdad.
    */
    'transport' => env('DIAN_TRANSPORT', 'auto'),

];
