<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lote de cobro: varios pagos recibidos en una sola operación.
 *
 * Caso real: llega una persona y paga el servicio de su mamá, su
 * hermana y su abuela. Son tres contratos distintos, tres facturas
 * distintas y tres recibos distintos —cada cliente tiene derecho a su
 * comprobante—, pero una sola entrega de dinero.
 *
 * El lote es lo que amarra esos pagos: permite cuadrar el efectivo
 * entregado contra lo aplicado, reimprimir los recibos juntos y saber
 * QUIÉN pagó, que no es ninguno de los tres titulares.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_register_id')->nullable()
                ->constrained('cash_registers')->nullOnDelete();

            // Quién entregó el dinero. No es un cliente del sistema:
            // puede ser un familiar, un vecino o un mensajero, así que
            // se guarda como texto libre.
            $table->string('payer_name', 150)->nullable();
            $table->string('payer_document', 40)->nullable();
            $table->string('payer_phone', 40)->nullable();

            $table->string('payment_method', 40);
            $table->string('reference_number', 80)->nullable();

            // Totales del lote, para cuadrar sin recorrer los pagos
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('total_retentions', 14, 2)->default(0);
            $table->unsignedSmallInteger('payments_count')->default(0);
            $table->unsignedSmallInteger('contracts_count')->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_batch_id')->nullable()->after('contract_id')
                ->constrained('payment_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_batch_id']);
            $table->dropColumn('payment_batch_id');
        });

        Schema::dropIfExists('payment_batches');
    }
};
