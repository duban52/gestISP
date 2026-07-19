<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cargos adicionales diferidos a cuotas.
 *
 * Un cargo puede facturarse de contado (comportamiento histórico,
 * installments_total NULL) o diferirse a N cuotas: cada generación
 * mensual incluye una cuota en la factura ("Decodificador TDT
 * (cuota 2/6)") hasta completarlas; solo entonces el cargo pasa a
 * estado Facturado.
 *
 * - installments_total: número de cuotas (NULL = de contado)
 * - installments_billed: cuotas ya incluidas en facturas
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aditional_charges', function (Blueprint $table) {
            $table->unsignedSmallInteger('installments_total')->nullable()->after('amount');
            $table->unsignedSmallInteger('installments_billed')->default(0)->after('installments_total');
        });
    }

    public function down(): void
    {
        Schema::table('aditional_charges', function (Blueprint $table) {
            $table->dropColumn(['installments_total', 'installments_billed']);
        });
    }
};
