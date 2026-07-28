<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite registrar ANTICIPOS: dinero que el cliente paga sin que
 * exista todavía una factura.
 *
 * Hasta ahora todo pago tenía que apuntar a una factura concreta. Un
 * cliente que quiere pagar seis meses por adelantado no tiene esas
 * facturas: aún no se han generado. Por eso invoice_id pasa a ser
 * opcional y se agrega el contrato al que pertenece el dinero.
 *
 * El anticipo sigue siendo un cobro normal en todo lo demás: exige
 * caja abierta y queda dentro del cuadre, como cualquier otro pago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // A qué contrato pertenece el dinero (imprescindible en
            // un anticipo, que no tiene factura)
            $table->foreignId('contract_id')->nullable()->after('invoice_id')
                ->constrained()->nullOnDelete();

            // factura | anticipo
            $table->string('type', 20)->default('factura')->after('contract_id');

            $table->index('contract_id');
        });

        // invoice_id pasa a opcional. Se hace con SQL directo para no
        // depender de doctrine/dbal.
        DB::statement('ALTER TABLE payments MODIFY invoice_id BIGINT UNSIGNED NULL');

        // Los pagos que ya existen quedan asociados al contrato de su
        // factura, para que el historial del contrato esté completo.
        DB::statement('
            UPDATE payments
            JOIN invoices ON invoices.id = payments.invoice_id
            SET payments.contract_id = invoices.contract_id
            WHERE payments.contract_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn('type');
        });

        DB::table('payments')->whereNull('invoice_id')->delete();

        DB::statement('ALTER TABLE payments MODIFY invoice_id BIGINT UNSIGNED NOT NULL');
    }
};
