<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Por qué se quedó «Desconectada» la última vez.
 *
 * El estado era un sí/no y la marca de tiempo decía cuándo se miró,
 * pero el motivo no se guardaba en ninguna parte: el controlador lo
 * atrapaba con un `catch` que no miraba la excepción. Quien abría el
 * panel veía «Desconectado» y no tenía ni un hilo del que tirar.
 *
 * Se guarda el último, no un histórico: lo que hace falta para
 * trabajar es «qué está pasando ahora», y una tabla de intentos de
 * una OLT que se consulta sola cada vez que alguien abre la pantalla
 * crece sin parar para contar siempre lo mismo. El histórico de
 * verdad, si algún día hace falta, es el log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('status_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });
    }
};
