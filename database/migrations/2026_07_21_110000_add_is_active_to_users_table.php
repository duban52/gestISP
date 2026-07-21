<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del usuario: habilitado o inhabilitado.
 *
 * Un usuario inhabilitado no puede iniciar sesión y, si ya estaba
 * dentro, se le expulsa en su siguiente petición. Sirve para
 * suspender el acceso de un empleado sin borrar su cuenta (con lo
 * que se conserva todo su rastro: pagos, órdenes, trazabilidad).
 *
 * Por defecto true para que los usuarios existentes sigan pudiendo
 * entrar tras la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
