<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El valor unitario de compra del material.
 *
 * PARA QUÉ
 * --------
 * Para poder decir cuánto vale, en dinero, el inventario de un almacén.
 * Hasta ahora el módulo solo sabía CUÁNTO material había, no lo que
 * costó; con eso no se puede cuantificar lo que hay parado en una
 * bodega ni lo que se lleva cada furgoneta.
 *
 * POR QUÉ EN TRES SITIOS Y NO EN UNO
 * ----------------------------------
 * Son tres cosas distintas, y confundirlas es lo que hace que un
 * inventario valorado mienta:
 *
 * · `materials`  — valor de REFERENCIA del catálogo. Lo que suele
 *   costar ese material. Sirve para proponerlo al registrar una
 *   entrada; no vale para valorar nada, porque el precio de hace un año
 *   no es el de hoy.
 *
 * · `inventories` — lo que se pagó DE VERDAD por las existencias que
 *   hay ahora en ese almacén. Es la única cifra con la que se puede
 *   totalizar. En los equipos es exacta (una fila por serial); en los
 *   consumibles es el promedio ponderado de las compras, porque todas
 *   se acumulan en una sola fila.
 *
 * · `material_movements` — lo que se pagó en ESE ingreso concreto. Es
 *   el histórico: sin él, recalcular o auditar un promedio ponderado es
 *   imposible, porque la fila de inventario solo guarda el resultado.
 *
 * TODO NULABLE, A PROPÓSITO
 * -------------------------
 * El dato es opcional —así se pidió— y además no existe para nada de lo
 * que ya está cargado. Rellenarlo con 0 sería peor que dejarlo vacío:
 * un cero se suma y hace creer que ese material no costó nada, mientras
 * que un nulo se puede distinguir y avisar de que falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('purchase_unit_value', 14, 2)
                ->nullable()
                ->after('is_equipment');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->decimal('purchase_unit_value', 14, 2)
                ->nullable()
                ->after('unit_of_measurement');
        });

        Schema::table('material_movements', function (Blueprint $table) {
            $table->decimal('purchase_unit_value', 14, 2)
                ->nullable()
                ->after('unit_of_measurement');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('purchase_unit_value');
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('purchase_unit_value');
        });

        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropColumn('purchase_unit_value');
        });
    }
};
