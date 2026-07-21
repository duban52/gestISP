<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de sesiones de los usuarios del sistema.
 *
 * Se guarda una fila por cada inicio de sesión, con la sucursal
 * elegida, la IP, el equipo desde el que se conectó y las marcas de
 * tiempo del ciclo de vida (entrada, última actividad, salida).
 *
 * Es una tabla propia y NO la tabla `sessions` de Laravel: el
 * proyecto usa el driver de sesión `file`, así que no existe esa
 * tabla; y aunque existiera, guarda solo la sesión viva y se borra
 * al cerrar. Aquí interesa el historial completo, que debe
 * conservarse aunque la sesión termine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sucursal con la que entró: un usuario puede tener
            // varias y elige una al iniciar sesión. Nullable y
            // con set null para no perder el historial si la
            // sucursal se elimina.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Identificador de la sesión de Laravel: correlaciona
            // esta fila con la sesión viva para tocar su actividad
            // y para poder cerrarla de forma remota.
            $table->string('session_id')->nullable()->index();

            $table->string('ip_address', 45)->nullable(); // 45 = IPv6
            $table->text('user_agent')->nullable();

            // Desglose del user-agent, ya interpretado, para no
            // reparsearlo en cada pantalla
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('device_type', 20)->nullable(); // Escritorio, Móvil, Tablet

            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('logout_at')->nullable();

            // Cómo terminó la sesión: manual (cerró él), expired
            // (caducó por inactividad), forced (la cerró un
            // administrador de forma remota).
            $table->string('logout_reason', 20)->nullable();

            $table->timestamps();

            // La pantalla del usuario ordena por actividad reciente
            // y filtra las que siguen abiertas: este índice cubre
            // ambas cosas.
            $table->index(['user_id', 'last_activity_at']);
            $table->index(['user_id', 'logout_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
