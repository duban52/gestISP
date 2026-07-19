<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría polimórfica (fase 4).
 *
 * Generaliza el patrón de payment_audits a cualquier modelo que
 * use el trait App\Billing\Concerns\Auditable: usuario, IP,
 * acción y valores antes/después de cada cambio.
 *
 * payment_audits se conserva tal cual por compatibilidad con el
 * historial existente; los modelos nuevos usan esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('ip', 45)->nullable();
            $table->string('action', 20);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
