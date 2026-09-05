<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca los certificados que la DIAN no va a aceptar.
 *
 * POR QUÉ UNA COLUMNA Y NO MIRARLO AL VUELO
 * -----------------------------------------
 * Saber si un certificado es autofirmado exige abrir el .p12 con su
 * contraseña y leerlo. Hacerlo cada vez que se pinta el panel o se
 * corre el diagnóstico es caro y, peor, obliga a descifrar la
 * contraseña para una pregunta que no la necesita.
 *
 * Se averigua UNA vez —al cargarlo o al generarlo— y se anota.
 *
 * QUÉ EVITA
 * ---------
 * Que alguien genere un certificado de pruebas para ver el flujo
 * funcionando, se le olvide, y meses después la empresa crea que está
 * firmando de verdad. Todo lo que se firme con él la DIAN lo rechaza, y
 * sin esta marca no habría forma de verlo en pantalla: un autofirmado y
 * uno real se ven igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_certificates', function (Blueprint $table) {
            $table->boolean('self_signed')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('dian_certificates', function (Blueprint $table) {
            $table->dropColumn('self_signed');
        });
    }
};
