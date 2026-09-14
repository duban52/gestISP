<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baja de contratos y órdenes administrativas.
 *
 * QUÉ FALTABA
 * -----------
 * No había forma de decir que un cliente se fue. «Retirado» existía en
 * los datos heredados y en los informes (`ContractStatusMap`), pero no
 * en el enum ni en ningún flujo: un contrato dado de baja seguía
 * figurando como activo y seguía facturándose.
 *
 * Y faltaba distinguir dos bajas que no son lo mismo:
 *
 *   · RETIRADO  — tuvo servicio y lo dejó. Hubo instalación, hubo
 *     facturas, y en algún momento se retiró el servicio.
 *   · ANULADO   — firmó el contrato y nunca tomó el servicio. No hubo
 *     instalación ni nada que cobrar.
 *
 * Contarlos juntos mentiría en los informes de bajas: una anulación no
 * es un cliente perdido, es un contrato que nunca empezó.
 *
 * LA ORDEN ADMINISTRATIVA
 * -----------------------
 * Los estados los movía únicamente la automatización de cobranza y el
 * cierre de órdenes de campo. No había forma de corregir un contrato mal
 * puesto, ni de registrar una baja, sin tocar la base de datos.
 *
 * `type` pasa a admitir «Administrativa»: una orden que no es trabajo de
 * campo sino un cambio de papeles. `target_contract_status` guarda a qué
 * estado se lleva el contrato, y queda abierto a lo que haga falta
 * mañana sin volver a tocar el esquema.
 *
 * SQL CRUDO PARA EL ENUM
 * ----------------------
 * Laravel 10 sin doctrine/dbal: `->change()` lanza excepción sobre una
 * columna enum. Es el mismo camino que se usó para el `branch_id` del
 * catálogo de almacén.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE technical_orders MODIFY type "
            . "ENUM('Servicio','Incidencia','Administrativa') NOT NULL"
        );

        Schema::table('technical_orders', function (Blueprint $table) {
            $table->string('target_contract_status', 30)
                ->nullable()
                ->after('detail')
                ->comment('Solo en órdenes administrativas: a qué estado se lleva el contrato al cerrarla.');
        });
    }

    public function down(): void
    {
        Schema::table('technical_orders', function (Blueprint $table) {
            $table->dropColumn('target_contract_status');
        });

        // Las administrativas que existieran pasarían a «Servicio»: el
        // enum no las admitiría y MySQL las dejaría en cadena vacía.
        DB::table('technical_orders')->where('type', 'Administrativa')->update(['type' => 'Servicio']);

        DB::statement(
            "ALTER TABLE technical_orders MODIFY type ENUM('Servicio','Incidencia') NOT NULL"
        );
    }
};
