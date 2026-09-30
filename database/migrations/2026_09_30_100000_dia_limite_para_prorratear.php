<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta qué día del mes se prorratea.
 *
 * LO QUE SE HACE EN LA CALLE
 * --------------------------
 * «Si el cliente entra antes del 16 le cobro los días; si entra
 * después, se los regalo.» No es una excentricidad: cobrar cinco días
 * cuesta más —la visita, el recibo, la fricción con alguien que acaba
 * de entrar— de lo que se recauda.
 *
 * NACE EN 31, QUE ES «SIEMPRE PRORRATEAR»
 * ---------------------------------------
 * Es exactamente lo que el sistema venía haciendo. Regalar días es una
 * decisión comercial y tiene que tomarla alguien a conciencia, no
 * heredarla de una actualización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('proration_day')->default(31)->after('proration_mode');
        });
    }

    public function down(): void
    {
        Schema::table('branch_billing_settings', function (Blueprint $table) {
            $table->dropColumn('proration_day');
        });
    }
};
