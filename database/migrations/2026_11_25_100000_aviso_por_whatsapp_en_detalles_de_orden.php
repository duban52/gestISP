<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada detalle de orden decide si se avisa al cliente por WhatsApp.
 *
 * Hasta ahora todas las órdenes avisaban al cliente al crearse y al
 * cerrarse. Hay trabajos en los que ese mensaje sobra o confunde —una
 * revisión interna, un cambio administrativo, un corte que no conviene
 * anunciar— y no había forma de apagarlo para unos y no para otros.
 *
 * NACE ENCENDIDO EN TODOS: lo que ya existe sigue avisando exactamente
 * igual que antes. Apagarlo es una decisión que toma alguien, detalle
 * por detalle, desde el catálogo de órdenes.
 *
 * Solo afecta a WhatsApp. El correo sigue como estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technical_order_details', function (Blueprint $table) {
            $table->boolean('notify_whatsapp')->default(true)->after('ont_action');
        });
    }

    public function down(): void
    {
        Schema::table('technical_order_details', function (Blueprint $table) {
            $table->dropColumn('notify_whatsapp');
        });
    }
};
