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

    /*
    |----------------------------------------------------------------
    | Algoritmos de WS-Security
    |----------------------------------------------------------------
    |
    | Los del SOBRE, no los del documento. El documento va firmado con
    | XAdES y SHA-256 porque lo dice el anexo; esto es otra cosa: la
    | firma que autentica la LLAMADA, y la decide el binding de WCF que
    | corre la DIAN, no el anexo.
    |
    | WCF valida el mensaje contra la «algorithm suite» de su binding, y
    | la suite por defecto (Basic256) usa SHA-1 para los resumenes y
    | RSA-SHA1 para la firma. Si no coinciden, contesta
    | `wsse:InvalidSecurity` — y no dice que el problema sea el
    | algoritmo.
    |
    | POR QUE ESTO ES CONFIGURABLE Y NO UNA CONSTANTE
    | ----------------------------------------------
    | Porque no se puede confirmar: la guia de consumo de la DIAN
    | muestra estos valores EN UNA IMAGEN, y del PDF no se extrae texto
    | (se intento: 2 MB de flujos y la palabra «Algorithm» aparece una
    | vez). Asi que es una hipotesis, y una hipotesis se prueba — no se
    | fija en una constante y se despliega.
    |
    | Con esto se cambia en el `.env` y se reintenta con
    | `dian:transmitir`, sin tocar codigo ni volver a desplegar.
    |
    | Valores: 'sha1' (Basic256, el de WCF por defecto) o 'sha256'.
    |
    */

    'ws_security_hash' => env('DIAN_WS_SECURITY_HASH', 'sha1'),

];
