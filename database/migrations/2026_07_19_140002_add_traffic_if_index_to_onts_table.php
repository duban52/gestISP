<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ifIndex propio de la ONT, para las estadísticas de tráfico.
 *
 * El campo if_index existente es el del PUERTO PON (compartido por
 * todas las ONTs de ese puerto) y sirve para las métricas ópticas.
 * Los contadores de tráfico, en cambio, viven en la interfaz
 * individual de cada ONT, que tiene su propio ifIndex.
 *
 * Lo resuelve el poller recorriendo ifDescr; queda null si el
 * modelo de OLT no expone interfaces por ONT (en ese caso
 * simplemente no hay gráfica de tráfico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_if_index')->nullable()->after('if_index');
        });
    }

    public function down(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->dropColumn('traffic_if_index');
        });
    }
};
