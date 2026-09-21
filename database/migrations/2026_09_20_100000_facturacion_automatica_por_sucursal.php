<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada sucursal decide si factura a mano o sola.
 *
 * Hasta ahora la corrida mensual solo existía detrás de un botón:
 * si nadie lo pulsaba, no se facturaba, y como la mora se marca al
 * generar, tampoco vencía nada ni salían los avisos de cobro.
 *
 * Se añade el modo por sucursal, porque no todas lo quieren igual:
 * una sede con un administrativo que revisa antes de emitir sigue en
 * manual, y otra sin nadie que lo haga lo pone automático con su día.
 *
 * EL DEFECTO ES MANUAL, A PROPÓSITO: migrar no puede cambiarle el
 * comportamiento a quien ya está facturando. Quien quiera lo
 * automático entra y lo enciende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->string('billing_mode', 20)->default('manual')->after('proration_mode');

            // Día del mes en que corre. Nulo mientras sea manual.
            // Un 31 en un mes de 30 no se salta: corre el último día
            // (ver BranchBillingSetting::facturaHoy).
            $table->unsignedTinyInteger('billing_day')->nullable()->after('billing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'billing_day']);
        });
    }
};
