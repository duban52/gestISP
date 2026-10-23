<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo rechazó el equipo nuestras credenciales.
 *
 * POR QUÉ HACE FALTA GUARDARLO
 * ----------------------------
 * `onts:autofind-cache` abre una sesión SSH contra cada OLT cada
 * cinco minutos, y la ficha y el listado abren otra cada vez que
 * alguien los mira. Mientras la contraseña sea correcta eso es
 * telemetría normal.
 *
 * Cuando NO lo es, se convierte en un intento de autenticación
 * fallido cada cinco minutos, indefinidamente, siempre desde la misma
 * IP. Las OLT Huawei tienen defensa contra ataques: a partir de unos
 * cuantos fallos bloquean el origen, y entonces dejan de responder
 * incluso al saludo SSH. El sistema se encierra fuera él solo, y el
 * síntoma que ve el operador —«la OLT dejó de responder del todo»— no
 * se parece en nada a la causa —«la contraseña está mal».
 *
 * Con esta marca, un rechazo de credencial pausa los reintentos
 * AUTOMÁTICOS. Reintentar una contraseña que el equipo acaba de
 * rechazar no puede funcionar: lo único que consigue es el bloqueo.
 * El diagnóstico manual sigue pudiendo probar cuando quiera, porque
 * ahí hay una persona esperando una respuesta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->timestamp('auth_failed_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn('auth_failed_at');
        });
    }
};
