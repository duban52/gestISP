<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * company_id en las tablas que pertenecen a un contribuyente (fase 2).
 *
 * POR QUÉ SE DUPLICA UN DATO QUE YA SE PODÍA DEDUCIR
 * --------------------------------------------------
 * `branch_id` ya determina la empresa: bastaría un JOIN. Se
 * desnormaliza igualmente por dos razones concretas:
 *
 *  1. COSTE. El aislamiento se aplica como global scope, es decir, en
 *     TODAS las consultas del sistema. Un `where company_id = X` es
 *     gratis; un `whereHas('branch', ...)` mete una subconsulta en
 *     cada listado, cada informe y cada exportación.
 *
 *  2. INDEPENDENCIA. La barrera de empresa tiene que sostenerse aunque
 *     el filtro de sucursal se olvide o se desactive a propósito. Si
 *     la empresa se dedujera de la sucursal, apagar uno apagaría el
 *     otro — y hay sitios donde apagar el de sucursal es legítimo (ver
 *     PppoeMassCutoff, que busca en OTRAS sucursales adrede).
 *
 * EL RIESGO DE DESNORMALIZAR, Y CÓMO SE CIERRA
 * --------------------------------------------
 * Un dato duplicado puede divergir: un contrato de la sucursal de la
 * empresa A con company_id de la B. Eso NO se deja al criterio de
 * quien escriba el código: el trait BelongsToCompany deriva company_id
 * de branch_id en cada guardado. No hay ninguna vía de escritura
 * normal que pueda descuadrarlos.
 *
 * QUÉ TABLAS NO LA LLEVAN, Y POR QUÉ
 * ----------------------------------
 *  · Las tablas HIJAS (invoice_items, payments, nap_ports,
 *    technical_order_materials, ont_metrics...) heredan el contexto de
 *    su padre. Añadírselo sería duplicar dos veces el mismo dato.
 *  · `user_branch` es un pivote hacia branches: se acota por ahí.
 *  · `branch_billing_settings` es 1:1 con la sucursal y solo se
 *    consulta por branch_id.
 */
return new class extends Migration
{
    /**
     * Tablas que pertenecen a un contribuyente y se consultan directamente.
     *
     * @var array<int, string>
     */
    private const TABLAS = [
        'account_credits',
        'audits',
        'billing_runs',
        'cash_registers',
        'categories',
        'clients',
        'contracts',
        'credit_debit_notes',
        'invoice_numbering_sequences',
        'invoices',
        'materials',
        'note_numbering_sequences',
        'olts',
        'ont_import_runs',
        'onts',
        'optical_networks',
        'payment_batches',
        'payment_retentions',
        'pdf_reports',
        'plans',
        'pppoe_accounts',
        'routers',
        'services',
        'technical_orders',
        'user_sessions',
        'warehouses',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                // Nullable a propósito: hay filas legítimas sin
                // sucursal (acciones de sistema en `audits`, por
                // ejemplo) y forzar NOT NULL las dejaría fuera o haría
                // fallar la migración.
                $table->foreignId('company_id')
                    ->nullable()
                    ->after('branch_id')
                    ->constrained()
                    ->nullOnDelete();

                // El índice compuesto es el que sirve de verdad: casi
                // toda consulta filtra por empresa Y por sucursal.
                $table->index(['company_id', 'branch_id']);
            });

            // Relleno desde la sucursal, que es la única fuente que hay.
            DB::statement("
                UPDATE {$tabla} t
                JOIN branches b ON b.id = t.branch_id
                SET t.company_id = b.company_id
                WHERE t.company_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'branch_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
