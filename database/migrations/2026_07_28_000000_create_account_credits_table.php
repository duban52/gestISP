<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saldo a favor del cliente.
 *
 * Hay dos situaciones en las que un contrato queda con dinero a
 * favor:
 *
 *  - Una NOTA CRÉDITO por más de lo que debía esa factura (por
 *    ejemplo, se anula una factura ya pagada).
 *  - Un ANTICIPO: el cliente paga varios meses por adelantado.
 *
 * Ese saldo NO se guarda como un número suelto en el contrato: se
 * lleva como un libro de movimientos. Cada entrada y cada aplicación
 * queda registrada con su origen, de modo que el saldo siempre se
 * puede explicar peso por peso — que es lo que exige una auditoría.
 *
 * El saldo disponible es la suma de las entradas menos las
 * aplicaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_credits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // entrada  → suma saldo a favor
            // aplicacion → lo consume al pagar una factura
            $table->string('movement', 15);

            // De dónde viene: anticipo | nota_credito | ajuste
            $table->string('origin', 20);

            // Siempre positivo: el signo lo da el tipo de movimiento
            $table->decimal('amount', 15, 2);

            // Trazabilidad del origen y del destino
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_debit_note_id')->nullable()
                ->constrained('credit_debit_notes')->nullOnDelete();

            $table->string('description');

            $table->timestamps();

            $table->index(['contract_id', 'movement']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_credits');
    }
};
