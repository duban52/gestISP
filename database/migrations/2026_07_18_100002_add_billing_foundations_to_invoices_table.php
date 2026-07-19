<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fundaciones de facturación en la tabla invoices (fase 1).
 *
 * - branch_id: la sucursal de la factura como columna propia. Antes
 *   se derivaba con joins por contracts o por clients (dos fuentes
 *   distintas según el punto del código); con miles de facturas los
 *   joins para filtrar por sucursal son el camino lento y ambiguo.
 *   Nullable porque el backfill corre en la migración siguiente.
 *
 * - period_start / period_end: el período facturado como fechas
 *   consultables. Las columnas string existentes (billed_period,
 *   billed_period_short...) son solo presentación y se conservan
 *   por compatibilidad con vistas y PDFs.
 *
 * - subtotal / discount: base para desglosar la factura
 *   (subtotal - descuento + impuestos = total), requisito de
 *   cualquier factura formal y de la facturación electrónica.
 *
 * - Índices en status y due_date: las consultas de cobranza
 *   (vencidas, pendientes por sucursal) filtran por estos campos
 *   en cada generación y en cada vista del índice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('contract_id')
                ->constrained();

            $table->date('period_start')->nullable()->after('billed_year_month');
            $table->date('period_end')->nullable()->after('period_start');

            $table->decimal('subtotal', 15, 2)->default(0)->after('due_date');
            $table->decimal('discount', 15, 2)->default(0)->after('subtotal');

            $table->index('status');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['due_date']);
            $table->dropColumn([
                'branch_id',
                'period_start',
                'period_end',
                'subtotal',
                'discount',
            ]);
        });
    }
};
