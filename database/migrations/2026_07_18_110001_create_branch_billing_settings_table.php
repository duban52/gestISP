<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de facturación por sucursal (fase 3).
 *
 * Externaliza las reglas que estaban como constantes en los
 * servicios de facturación. Los DEFAULTS reproducen exactamente el
 * comportamiento histórico, así que las sucursales sin fila (o
 * recién creadas) facturan igual que siempre; la fila se crea
 * perezosamente la primera vez que se consulta o edita.
 *
 * - proration_mode: 'prorated' (histórico) o 'full_month'
 * - due_days: días de plazo de pago desde la emisión
 * - suspension_threshold: facturas vencidas para suspender
 * - suspension_days: días hasta el corte cuando hay vencidas
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_billing_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->unique()->constrained()->onDelete('cascade');
            $table->string('proration_mode', 20)->default('prorated');
            $table->unsignedSmallInteger('due_days')->default(20);
            $table->unsignedSmallInteger('suspension_threshold')->default(2);
            $table->unsignedSmallInteger('suspension_days')->default(24);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_billing_settings');
    }
};
