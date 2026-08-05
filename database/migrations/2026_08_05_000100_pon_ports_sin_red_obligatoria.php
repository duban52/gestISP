<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un puerto PON puede existir sin pertenecer a ninguna red.
 *
 * POR QUÉ CAMBIA
 * --------------
 * La versión anterior exigía que la OLT estuviera asignada a una red
 * óptica para poder descubrir sus puertos. Está al revés: los puertos
 * son un HECHO FÍSICO del equipo —están ahí, con o sin papeleo—, y la
 * red es documentación que se hace después. Obligar a documentar antes
 * de poder mirar el equipo impedía justo lo que se quería: enchufar una
 * OLT nueva y ver qué trae.
 *
 * Un puerto sin red se ve en la ficha de la OLT y en su modal, pero no
 * puede alojar cajas NAP ni entrar en una zona: eso sí es documentación
 * y vive dentro de una red. Al asignar la OLT a una red, sus puertos la
 * adoptan.
 *
 * La clave foránea pasa a nullOnDelete: borrar una red no puede borrar
 * puertos que existen en el equipo, solo dejarlos sin documentar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pon_ports', function (Blueprint $table) {
            $table->dropForeign(['optical_network_id']);
        });

        // Se hace con SQL directo y no con ->change(): cambiar el tipo
        // de una columna con el constructor de esquemas exige
        // doctrine/dbal, que este proyecto no tiene instalado.
        DB::statement('ALTER TABLE pon_ports MODIFY optical_network_id BIGINT UNSIGNED NULL');

        Schema::table('pon_ports', function (Blueprint $table) {
            $table->foreign('optical_network_id')
                ->references('id')->on('optical_networks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Los puertos sin red no caben en el esquema viejo: se les
        // quita antes, porque si no la columna no puede volver a ser
        // obligatoria.
        DB::table('pon_ports')->whereNull('optical_network_id')->delete();

        Schema::table('pon_ports', function (Blueprint $table) {
            $table->dropForeign(['optical_network_id']);
        });

        DB::statement('ALTER TABLE pon_ports MODIFY optical_network_id BIGINT UNSIGNED NOT NULL');

        Schema::table('pon_ports', function (Blueprint $table) {
            $table->foreign('optical_network_id')
                ->references('id')->on('optical_networks')
                ->cascadeOnDelete();
        });
    }
};
