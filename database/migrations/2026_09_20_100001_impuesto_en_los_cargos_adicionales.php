<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un cargo adicional puede llevar IVA.
 *
 * Hasta ahora no podía: el generador escribía `percentage_tax = 0`
 * en el renglón porque el cargo no tenía dónde guardar una tarifa.
 * En el XML esas líneas salían declaradas como EXCLUIDAS.
 *
 * Una reconexión o la venta de un equipo son gravados al 19%, así que
 * se estaban declarando sin IVA ante la DIAN. Eso no es un detalle de
 * pantalla: es subdeclaración.
 *
 * LOS CARGOS QUE YA EXISTEN SE QUEDAN EN CERO, y es lo correcto: se
 * emitieron declarados como excluidos y el documento no se reescribe
 * hacia atrás. La clasificación se deduce de la tarifa cuando no está
 * puesta (ver AditionalCharge::clasificacion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aditional_charges', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->default(0)->after('amount');
            $table->string('tax_classification', 20)->nullable()->after('tax_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('aditional_charges', function (Blueprint $table) {
            $table->dropColumn(['tax_percentage', 'tax_classification']);
        });
    }
};
