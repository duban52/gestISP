<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos datos que el contrato de servicios imprime y la empresa no tenía.
 *
 * El «Registro TIC» es el número con el que el operador está inscrito en
 * el registro del MinTIC: va en la cabecera del contrato porque es lo
 * que acredita que quien presta el servicio puede hacerlo.
 *
 * La página web se nombra tres veces en el articulado (política de
 * tratamiento de datos, condiciones técnicas, aparatos en desuso). Sin
 * ella el contrato remite al usuario a un sitio que no se dice cuál es.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tic_registry', 40)->nullable()->after('phone');
            $table->string('website', 255)->nullable()->after('tic_registry');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tic_registry', 'website']);
        });
    }
};
