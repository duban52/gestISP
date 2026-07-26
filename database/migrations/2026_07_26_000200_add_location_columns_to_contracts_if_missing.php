<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repara un desfase entre la base de datos y las migraciones.
 *
 * Las columnas municipality y department de contracts existen en las
 * bases en uso (el formulario de contrato las escribe desde hace
 * tiempo), pero NINGUNA migración las creaba: se agregaron a mano en
 * su momento. Consecuencia: una instalación desde cero no las tenía y
 * cualquier alta de contrato fallaba con "Unknown column".
 *
 * Se crean solo si faltan, de modo que las bases que ya las tienen no
 * se ven afectadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'municipality')) {
                $table->string('municipality')->nullable()->after('address');
            }

            if (!Schema::hasColumn('contracts', 'department')) {
                $table->string('department')->nullable()->after('municipality');
            }
        });
    }

    public function down(): void
    {
        // No se eliminan: son columnas en uso desde antes de esta
        // migración y borrarlas destruiría datos reales.
    }
};
