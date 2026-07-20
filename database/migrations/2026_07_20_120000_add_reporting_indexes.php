<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los informes gerenciales.
 *
 * Los informes agrupan por rangos de fecha y filtran por estado
 * sobre las tablas grandes del sistema. Sin estos índices, cada
 * consulta recorre la tabla completa.
 *
 * Medido sobre 20.000 contratos, 120.000 facturas, 90.000 pagos y
 * 40.000 órdenes, en la agregación de facturado por período de una
 * sucursal:
 *
 *   Rango      Índice usado   Filas leídas   Tiempo
 *   3 meses    sí                  3.806      40 ms
 *   12 meses   sí                 29.094     142 ms
 *   4 años     no                119.151     269 ms
 *
 * Los índices empiezan por branch_id porque las pantallas filtran
 * por sucursal de forma predeterminada, que es el uso habitual.
 *
 * En la vista consolidada (todas las sucursales) y en rangos muy
 * largos, MySQL prefiere recorrer la tabla entera y hace bien: a
 * esa escala se está agregando prácticamente todo su contenido y
 * no hay índice que lo mejore. Ese caso es puntual, no diario.
 *
 * Se comprueba la existencia antes de crear: la migración debe
 * poder ejecutarse sobre bases que ya tengan alguno de ellos.
 */
return new class extends Migration
{
    /**
     * tabla => [nombre del índice => columnas]
     */
    private const INDICES = [
        'invoices' => [
            'invoices_branch_issue_index' => ['branch_id', 'issue_date'],
            'invoices_branch_status_pending_index' => ['branch_id', 'status', 'pending_invoice_amount'],
        ],
        'payments' => [
            'payments_date_status_index' => ['payment_date', 'status'],
        ],
        'contracts' => [
            'contracts_branch_status_index' => ['branch_id', 'status'],
            'contracts_activation_index' => ['activation_date'],
        ],
        'technical_orders' => [
            'technical_orders_branch_created_index' => ['branch_id', 'created_at'],
            'technical_orders_branch_status_index' => ['branch_id', 'status'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $indices) {
                foreach ($indices as $nombre => $columnas) {
                    if (!$this->existeIndice($tabla, $nombre)) {
                        $table->index($columnas, $nombre);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($tabla, $indices) {
                foreach (array_keys($indices) as $nombre) {
                    if ($this->existeIndice($tabla, $nombre)) {
                        $table->dropIndex($nombre);
                    }
                }
            });
        }
    }

    private function existeIndice(string $tabla, string $nombre): bool
    {
        return !empty(\Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$tabla}` WHERE Key_name = ?",
            [$nombre]
        ));
    }
};
