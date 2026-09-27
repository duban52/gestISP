<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué mes cobra la corrida: anticipado, en curso o vencido.
 *
 * Hasta ahora la respuesta estaba escrita en el código y era una
 * sola: el mes en el que se ejecuta la corrida. Un ISP que cobra por
 * adelantado —lo más común— no tenía forma de decirlo.
 *
 * NACE EN «current» A PROPÓSITO
 * -----------------------------
 * Es exactamente lo que el sistema venía haciendo. Quien ya está
 * facturando no puede encontrarse, al actualizar, con que este mes se
 * le cobró otro período a todos sus clientes. Cambiarlo es una
 * decisión que se toma en el formulario de la sucursal, a conciencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->default('current')->after('proration_mode');
        });
    }

    public function down(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }
};
