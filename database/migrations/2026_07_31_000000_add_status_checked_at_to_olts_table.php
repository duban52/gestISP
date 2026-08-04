<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se comprobó por última vez el estado de la OLT.
 *
 * El listado ahora se dibuja al instante con lo último que se sabe
 * (guardado en `status`, `temperature` y `uptime`) y consulta el
 * equipo después, en segundo plano. Eso obliga a poder responder una
 * pregunta que antes no existía: **¿de cuándo son estos datos?**
 *
 * Sin esta columna, una OLT apagada hace tres horas se vería igual
 * que una recién consultada, y `updated_at` no sirve porque cambia
 * también al editar el nombre o la contraseña del equipo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->timestamp('status_checked_at')->nullable()->after('uptime');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn('status_checked_at');
        });
    }
};
