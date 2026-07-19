<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de corridas de facturación (reporte gerencial).
 *
 * Cada vez que se ejecuta la generación de facturas de una
 * sucursal queda un registro con los totales de la corrida:
 * cuántas facturas se generaron/omitieron y cuánto se facturó
 * (subtotal, IVA, total). Es la base del reporte de facturación
 * por período.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('billed_year_month', 6);
            $table->unsignedInteger('contracts_count')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->decimal('total_subtotal', 15, 2)->default(0);
            $table->decimal('total_tax', 15, 2)->default(0);
            $table->decimal('total_billed', 15, 2)->default(0);
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['branch_id', 'billed_year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
    }
};
