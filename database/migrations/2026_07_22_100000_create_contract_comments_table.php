<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentarios/notas internas sobre un contrato.
 *
 * Bitácora libre que el personal de oficina y soporte deja sobre el
 * contrato (acuerdos con el cliente, incidencias, recordatorios...).
 * Cada comentario guarda quién lo escribió para dar trazabilidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            // El autor se conserva como referencia; si el usuario se
            // borra, el comentario queda sin autor pero no se pierde.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_comments');
    }
};
