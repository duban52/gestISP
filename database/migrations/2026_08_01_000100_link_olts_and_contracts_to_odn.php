<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engancha lo que ya existía con la red óptica documentada.
 *
 * OLT → RED
 * ---------
 * Una OLT pertenece a una red. Nullable porque las OLTs ya existen y
 * no se puede exigir que alguien las clasifique antes de poder abrir
 * el módulo.
 *
 * CONTRATO → PUERTO DE NAP
 * ------------------------
 * `contracts.nap_port` era TEXTO LIBRE ("NAP-PORT-161"): servía para
 * anotar, no para saber si un puerto estaba ocupado. Se añade el
 * vínculo real y **se conserva la columna de texto** como histórico:
 * lo que hay escrito ahí no se puede traducir a una caja concreta sin
 * adivinar, y adivinar en documentación de red es peor que no tener
 * el dato.
 *
 * El índice ÚNICO sobre nap_port_id es la regla de oro del módulo: un
 * puerto de la caja lo ocupa un solo cliente. Sin él, dos contratos
 * podrían apuntar al mismo puerto y el mapa de ocupación mentiría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->foreignId('optical_network_id')->nullable()->after('branch_id')
                ->constrained('optical_networks')->nullOnDelete();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->foreignId('nap_port_id')->nullable()->after('nap_port')
                ->constrained('nap_ports')->nullOnDelete();

            // Un puerto, un cliente. En MySQL un índice único admite
            // varios NULL, así que los contratos sin puerto asignado
            // no estorban.
            $table->unique('nap_port_id', 'contracts_nap_port_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropUnique('contracts_nap_port_unique');
            $table->dropForeign(['nap_port_id']);
            $table->dropColumn('nap_port_id');
        });

        Schema::table('olts', function (Blueprint $table) {
            $table->dropForeign(['optical_network_id']);
            $table->dropColumn('optical_network_id');
        });
    }
};
