<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Esta OLT expone contadores de tráfico por ONT?
 *
 * EL PROBLEMA
 * -----------
 * El ancho de banda por ONT solo se puede medir si la OLT publica una
 * interfaz SNMP por cada ONT (una ifDescr tipo «GPON ONT 0/1/2:5»).
 * Muchas no lo hacen: las MA5608T y varias MA5800 con firmware de
 * fábrica solo exponen los puertos PON.
 *
 * Cuando no las expone pasaban dos cosas, las dos malas:
 *
 *  1. La ficha de la ONT reservaba media fila para una gráfica vacía
 *     y un recuadro con DOS COMANDOS DE ARTISAN. Eso es una nota para
 *     el que programa, no para quien atiende a un cliente por
 *     teléfono, y empujaba fuera de la pantalla la información que sí
 *     sirve.
 *
 *  2. El poller recorría la tabla de interfaces entera, en cada
 *     pasada, intentando resolver un índice que nunca iba a existir.
 *     Con mil ONT eso es un walk completo de ifDescr para nada.
 *
 * QUÉ GUARDA
 * ----------
 *   null  → todavía no se ha comprobado
 *   true  → la OLT los expone (hay tráfico que medir)
 *   false → se comprobó y NO los expone
 *
 * El `false` no es definitivo: `onts:poll --resolve-traffic` vuelve a
 * preguntar. Si mañana se actualiza el firmware o se corrige el
 * patrón de `config/olt_snmp.php`, una sola pasada lo reactiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->boolean('onts_traffic_supported')->nullable()->after('status_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn('onts_traffic_supported');
        });
    }
};
