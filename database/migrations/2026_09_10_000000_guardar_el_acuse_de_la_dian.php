<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el acuse de la DIAN y la fecha en que se entregó al cliente.
 *
 * QUÉ ES EL ACUSE
 * ---------------
 * El `ApplicationResponse`: el XML firmado por la DIAN que acredita que
 * validó el documento. Llega dentro de la respuesta SOAP, en base64, y
 * hasta ahora no se desempaquetaba.
 *
 * Hace falta porque emitir ante la DIAN no es entregar al adquiriente:
 * al cliente hay que mandarle la factura firmada Y este acuse. Sin él no
 * tiene con qué comprobar por su cuenta que su factura fue aceptada.
 *
 * POR QUÉ AQUÍ Y NO SE RELEE DE `document_transmissions`
 * ------------------------------------------------------
 * Porque esa tabla guarda una fila por INTENTO, con el sobre entero
 * dentro, y es candidata a purga —igual que lo fue `audits`—. El acuse
 * es del documento, no del intento, y tiene que sobrevivir a la limpieza.
 *
 * `delivered_at` ES LA GUARDA CONTRA ENVIAR DOS VECES
 * ---------------------------------------------------
 * La transmisión se reintenta, los jobs se reencolan y `dian:transmitir`
 * se puede correr a mano. Nada de eso puede acabar en dos correos con la
 * misma factura para el mismo cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->longText('dian_response_xml')->nullable()->after('signed_xml');
            $table->timestamp('delivered_at')->nullable()->after('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropColumn(['dian_response_xml', 'delivered_at']);
        });
    }
};
