<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corridas de importación de ONTs desde una OLT.
 *
 * Importar puede tomar varios minutos en una OLT con miles de
 * ONTs, así que el proceso corre en segundo plano (cola) y va
 * dejando su avance aquí: la pantalla consulta esta tabla para
 * mostrar la barra de progreso y el resultado final.
 *
 * Guardar cada corrida deja además trazabilidad: quién importó,
 * cuándo, cuántas ONTs se trajeron y cuántas se omitieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ont_import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->onDelete('cascade');
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            // pending → running → completed | failed
            $table->string('status', 20)->default('pending');

            // Avance y resultados
            $table->unsignedInteger('total_found')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped_existing')->default(0);
            $table->unsignedInteger('skipped_invalid')->default(0);
            $table->unsignedInteger('matched_contracts')->default(0);

            // Mensaje de estado o error para mostrar al usuario
            $table->text('message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['olt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ont_import_runs');
    }
};
