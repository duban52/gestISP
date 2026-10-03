<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La observación de un movimiento de material.
 *
 * NO ES EL MOTIVO
 * ---------------
 * `reason` es una lista cerrada —Compra, Deterioro, Venta, Orden
 * técnica…— y por eso sirve para filtrar y para agrupar. Pero el
 * motivo no cuenta lo que pasó: «Salida por deterioro» no dice que
 * fueron cuatro ONT quemadas por un rayo en la torre del Alto, ni
 * «Entrada por compra» que el proveedor mandó dos cajas de más que
 * hay que devolver.
 *
 * Eso es lo que se escribía en un cuaderno, y lo que nadie encontraba
 * seis meses después cuando el inventario no cuadraba.
 *
 * EN TODOS LOS TIPOS
 * ------------------
 * A diferencia de los datos de compra —proveedor y factura, que solo
 * tienen sentido en una entrada—, la observación vale igual en una
 * entrada, una salida o un traslado. Es opcional en los tres: obligar
 * a escribir algo produce «ninguna» cien veces y una nota de verdad
 * cada mil.
 *
 * Se repite en cada renglón de la operación, igual que el motivo y los
 * datos de compra: es el precio de que cada serial sea su propio
 * movimiento, y es lo que permite que el renglón solo lo explique sin
 * depender de otra tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            // TEXT y no string: es prosa, y recortarla a 255 obliga a
            // resumir justo lo que vale la pena contar.
            $table->text('observations')->nullable()->after('invoice_date');
        });
    }

    public function down(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropColumn('observations');
        });
    }
};
