<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencia de tema (claro/oscuro) de cada usuario.
 *
 * AdminLTE guarda esta preferencia SOLO en la sesión, así que se
 * perdía en cada cierre —y con el cierre automático por inactividad
 * eso ocurre a diario—. Guardándola en el usuario, el tema elegido lo
 * acompaña siempre, incluso desde otro equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('dark_mode')->default(false)->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dark_mode');
        });
    }
};
