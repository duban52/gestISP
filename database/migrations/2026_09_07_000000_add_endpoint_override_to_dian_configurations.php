<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La URL del servicio, tambien en la configuracion de la empresa.
 *
 * POR QUE, SI VIENE PUESTA DE FABRICA
 * -----------------------------------
 * Porque «viene puesta» y «no se puede ver ni corregir» no son lo mismo,
 * y lo segundo es un problema:
 *
 *   · Si la DIAN mueve la URL —ya lo ha hecho—, hoy haria falta un
 *     despliegue para arreglarlo. Para una instalacion con varias
 *     empresas contratantes, eso es dejar a todo el mundo parado.
 *   · Una empresa podria decidir pasar por un proveedor tecnologico en
 *     vez de emitir directo. Ese es otro endpoint, y es DE ESA empresa.
 *   · Y lo mas simple: si no esta en la pantalla, cuando algo falla no
 *     hay donde mirar.
 *
 * Nace VACIA y sigue siendo opcional: lo normal es no tocarla y que el
 * sistema use la URL publica del ambiente de la empresa. Esto es el
 * escape, no el camino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_configurations', function (Blueprint $table) {
            $table->string('endpoint_override', 255)
                ->nullable()
                ->after('test_set_id');
        });
    }

    public function down(): void
    {
        Schema::table('dian_configurations', function (Blueprint $table) {
            $table->dropColumn('endpoint_override');
        });
    }
};
