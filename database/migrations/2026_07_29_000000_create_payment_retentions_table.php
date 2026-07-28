<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones practicadas por el cliente al pagar una factura.
 *
 * Cada fila es un impuesto que el cliente NO nos entregó a nosotros
 * porque la ley lo obliga a consignarlo directamente a la DIAN o al
 * municipio a nombre nuestro (ver App\Billing\Enums\RetentionType).
 *
 * Se guarda base, tarifa Y valor —aunque el valor se pueda calcular—
 * porque las tarifas cambian con cada reforma: dentro de tres años
 * hay que poder reconstruir exactamente cómo se liquidó esta
 * retención, no cómo se liquidaría hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_retentions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            // El pago con el que llegó la retención. Es nullable
            // porque el pago puede anularse (soft delete) sin que la
            // retención deje de haber existido.
            $table->foreignId('payment_id')->nullable()
                ->constrained('payments')->nullOnDelete();

            // La factura que ayuda a saldar: es el dato que de verdad
            // importa para el saldo del cliente.
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();

            // Quién la registró
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // renta | iva | ica | timbre
            $table->string('type', 20);

            // Código del concepto dentro del tipo y su texto. Se
            // guardan los dos: si mañana cambia el catálogo, el
            // documento emitido conserva lo que decía.
            $table->string('concept_code', 60)->nullable();
            $table->string('concept_label', 180)->nullable();

            $table->decimal('base', 14, 2);
            // Tarifa en PORCENTAJE (un 6 por mil se guarda como 0,600)
            $table->decimal('rate', 8, 3);
            $table->decimal('amount', 14, 2);

            // Certificado que el cliente está obligado a expedir y que
            // es el soporte para descontar el impuesto.
            $table->string('certificate_number', 80)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // El reporte de retenciones filtra por sucursal y período
            $table->index(['branch_id', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_retentions');
    }
};
