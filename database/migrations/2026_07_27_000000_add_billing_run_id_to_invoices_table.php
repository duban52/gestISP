<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enlaza cada factura con la corrida que la generó.
 *
 * Hasta ahora billing_runs solo guardaba los CONTEOS de cada
 * generación (cuántas facturas, cuánto se facturó), pero no había
 * forma de saber CUÁLES facturas salieron de una corrida concreta.
 * Sin ese enlace no se puede auditar una generación ni entregar el
 * detalle de lo facturado.
 *
 * Las facturas anteriores a este cambio quedan sin corrida asociada;
 * el reporte las recupera por sucursal y período, que es el criterio
 * con el que se agrupaban antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('billing_run_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('billing_runs')
                ->nullOnDelete();

            $table->index('billing_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_run_id');
        });
    }
};
