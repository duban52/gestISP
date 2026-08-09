<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de sesión en la propia cuenta, en vez de deducirlo cada vez.
 *
 * EL PROBLEMA
 * -----------
 * El listado sacaba «¿está conectada?» y «¿cuándo fue la última vez?»
 * de la tabla de métricas, con una subconsulta correlacionada POR
 * CUENTA. Esa tabla crece con una fila por cuenta cada cinco minutos:
 * dos mil cuentas con treinta días de historial son diecisiete
 * millones de filas. La pantalla tardaba veinte segundos.
 *
 * POR QUÉ SE DUPLICA EL DATO
 * --------------------------
 * En este proyecto la regla es NO guardar lo que se puede deducir —los
 * puertos de una caja, los hilos de un cable— porque un dato duplicado
 * acaba mintiendo. Aquí se hace la excepción a propósito, y conviene
 * entender por qué no es lo mismo:
 *
 *   · Aquellos se deducen de OTRAS TABLAS del propio sistema, que
 *     cambian por muchos caminos distintos.
 *   · Esto se deduce de un historial que escribe UN SOLO proceso, el
 *     muestreador, cada cinco minutos. Hay una única fuente de verdad y
 *     un único sitio que la actualiza.
 *
 * Y no es «el estado en vivo»: es el estado de la última pasada del
 * muestreador. La pantalla lo dice, para que nadie lo confunda con
 * consultar el router.
 *
 * El historial se conserva intacto: las gráficas siguen saliendo de él.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pppoe_accounts', function (Blueprint $table) {
            // Estado en la última pasada del muestreador
            $table->boolean('connected')->default(false)->after('disabled');
            $table->string('last_address', 45)->nullable()->after('connected');
            // Última vez que se la VIO conectada (puede ser muy anterior
            // a la última pasada, y es justo el dato que interesa
            // cuando alguien pregunta desde cuándo está caído)
            $table->timestamp('last_seen_at')->nullable()->after('last_address');
            // Cuándo miró el muestreador por última vez: sin esto no se
            // puede distinguir «desconectada» de «nadie ha mirado»
            $table->timestamp('last_polled_at')->nullable()->after('last_seen_at');

            // El listado filtra por estas dos
            $table->index(['branch_id', 'connected'], 'pppoe_accounts_estado_index');
        });

        // ---------- Relleno con lo que ya hay en el historial ----------

        // Última vez conectada
        DB::statement(<<<'SQL'
            UPDATE pppoe_accounts a
            SET a.last_seen_at = (
                SELECT MAX(m.measured_at)
                FROM pppoe_session_metrics m
                WHERE m.pppoe_account_id = a.id AND m.connected = 1
            )
        SQL);

        // Estado y dirección de la última muestra, sea cual sea
        DB::statement(<<<'SQL'
            UPDATE pppoe_accounts a
            JOIN (
                SELECT m1.pppoe_account_id, m1.connected, m1.address, m1.measured_at
                FROM pppoe_session_metrics m1
                JOIN (
                    SELECT pppoe_account_id, MAX(measured_at) AS ultima
                    FROM pppoe_session_metrics
                    GROUP BY pppoe_account_id
                ) m2 ON m2.pppoe_account_id = m1.pppoe_account_id
                    AND m2.ultima = m1.measured_at
            ) ultima ON ultima.pppoe_account_id = a.id
            SET a.connected = ultima.connected,
                a.last_address = ultima.address,
                a.last_polled_at = ultima.measured_at
        SQL);
    }

    public function down(): void
    {
        Schema::table('pppoe_accounts', function (Blueprint $table) {
            $table->dropIndex('pppoe_accounts_estado_index');
            $table->dropColumn(['connected', 'last_address', 'last_seen_at', 'last_polled_at']);
        });
    }
};
