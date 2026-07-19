<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de CATV y estado administrativo de la ONT.
 *
 * Estos dos estados solo se pueden leer de la OLT por CLI (SSH),
 * y esa consulta tarda ~40 segundos. Para que la pantalla sea
 * instantánea se guarda el último estado conocido:
 *
 *  - Se actualiza cada vez que se cambia desde GestISP (que es
 *    cuando el sistema sabe con certeza en qué estado quedó).
 *  - Se puede refrescar contra la OLT bajo demanda con el botón
 *    de verificar, que actualiza también catv_checked_at.
 *
 * catv_enabled y admin_enabled quedan en null cuando nunca se han
 * verificado: la vista lo muestra como "sin verificar" en lugar
 * de inventar un estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->boolean('catv_enabled')->nullable()->after('vlan');
            $table->timestamp('catv_checked_at')->nullable()->after('catv_enabled');
            $table->boolean('admin_enabled')->nullable()->default(true)->after('catv_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->dropColumn(['catv_enabled', 'catv_checked_at', 'admin_enabled']);
        });
    }
};
