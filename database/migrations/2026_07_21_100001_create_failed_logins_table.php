<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intentos de inicio de sesión fallidos.
 *
 * Parte de la trazabilidad de seguridad: deja ver si alguien está
 * intentando entrar a una cuenta con la contraseña equivocada, y
 * desde qué IP.
 *
 * user_id es nullable a propósito: un intento contra un correo que
 * no existe también se registra (email quedará guardado aunque no
 * corresponda a ningún usuario), porque es justo la señal que
 * interesa vigilar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_logins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // El correo con el que se intentó entrar, aunque no
            // exista un usuario con él
            $table->string('email')->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('attempted_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_logins');
    }
};
