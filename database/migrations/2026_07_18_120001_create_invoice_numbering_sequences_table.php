<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secuencias de numeración de facturas (fase 4).
 *
 * Cada sucursal tiene una secuencia activa con prefijo y
 * consecutivo. La estructura soporta desde ya los datos de una
 * resolución de facturación DIAN (número de resolución, vigencia
 * y rango autorizado), aunque inicialmente la numeración sea
 * interna: cuando llegue la habilitación DIAN solo se registra la
 * resolución en la secuencia, sin cambiar código.
 *
 * El consecutivo se incrementa con la fila bloqueada
 * (lockForUpdate en InvoiceNumerator): dos facturas generadas a la
 * vez jamás reciben el mismo número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->onDelete('cascade');
            $table->string('prefix', 10);
            $table->string('resolution_number')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedBigInteger('range_start')->default(1);
            $table->unsignedBigInteger('range_end')->nullable();
            $table->unsignedBigInteger('current_number')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_numbering_sequences');
    }
};
