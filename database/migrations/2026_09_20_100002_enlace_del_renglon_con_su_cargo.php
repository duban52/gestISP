<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El renglón de la factura recuerda de qué cargo salió.
 *
 * Al facturar, un cargo de contado pasa a «Facturado» y uno diferido
 * sube su contador de cuotas. Si después se anula esa factura, ese
 * consumo no se devolvía: el cargo quedaba marcado como cobrado sin
 * que existiera ya una factura que lo cobrara, y no se volvía a
 * incluir nunca más. Dinero perdido, y en silencio.
 *
 * Para devolverlo hay que saber QUÉ cargo se consumió en QUÉ factura.
 * Deducirlo por el texto de la descripción sería adivinar —dos cargos
 * pueden llamarse igual—, así que se guarda el enlace.
 *
 * Nulo en los renglones de servicios del plan, que no vienen de un
 * cargo, y en los ya emitidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('aditional_charge_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('aditional_charges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['aditional_charge_id']);
            $table->dropColumn('aditional_charge_id');
        });
    }
};
