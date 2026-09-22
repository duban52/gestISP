<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada operación de almacén es UNA, aunque mueva mil equipos.
 *
 * Un equipo con serial genera un renglón por serial: es lo que permite
 * saber dónde está cada uno. Pero el historial los mostraba sueltos, así
 * que una entrada de mil ONT eran mil «movimientos» y la pantalla no
 * servía para nada.
 *
 * `operation_id` agrupa los renglones de una misma operación, y su valor
 * es EL ID DEL PRIMER RENGLÓN: no hace falta otra tabla ni otro
 * consecutivo, y el número que se ve —«Movimiento n.º 4471»— es un id
 * real que se puede buscar.
 *
 * Los renglones que ya existen se agrupan por lo que compartían: tipo,
 * almacenes, motivo, quién lo hizo y el instante en que se registró. Una
 * carga muy grande pudo cruzar el cambio de segundo y quedar partida en
 * dos operaciones; es historia y no se puede reconstruir mejor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('operation_id')->nullable()->after('id')->index();
        });

        DB::statement(<<<'SQL'
            UPDATE material_movements m
            JOIN (
                SELECT MIN(id) AS primero,
                       type,
                       COALESCE(warehouse_origin_id, 0) AS origen,
                       COALESCE(warehouse_destination_id, 0) AS destino,
                       COALESCE(reason, '') AS motivo,
                       COALESCE(user_id, 0) AS usuario,
                       created_at
                  FROM material_movements
                 GROUP BY type, origen, destino, motivo, usuario, created_at
            ) g
              ON g.type = m.type
             AND COALESCE(m.warehouse_origin_id, 0) = g.origen
             AND COALESCE(m.warehouse_destination_id, 0) = g.destino
             AND COALESCE(m.reason, '') = g.motivo
             AND COALESCE(m.user_id, 0) = g.usuario
             AND m.created_at = g.created_at
             SET m.operation_id = g.primero
        SQL);
    }

    public function down(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropColumn('operation_id');
        });
    }
};
