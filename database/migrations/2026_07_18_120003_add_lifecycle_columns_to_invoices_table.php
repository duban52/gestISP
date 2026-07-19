<?php

use App\Billing\Enums\InvoiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida y numeración en invoices (fase 4).
 *
 * - type: clasifica el origen del cobro (mensualidad, instalación,
 *   reconexión, equipos, manual). Las facturas históricas quedan
 *   como Mensualidad (todas nacieron de la generación mensual).
 *
 * - prefix / number / full_number / numbering_sequence_id: la
 *   numeración formal. Las facturas históricas quedan sin número
 *   (nullable) — la numeración arranca desde ahora; las vistas
 *   muestran full_number ?? id.
 *
 * - voided_at / voided_by / void_reason: anulación formal. Nunca
 *   se elimina una factura: cambia a estado Anulada registrando
 *   quién, cuándo y por qué.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type', 30)
                ->default(InvoiceType::Mensualidad->value)
                ->after('branch_id');

            $table->string('prefix', 10)->nullable()->after('type');
            $table->unsignedBigInteger('number')->nullable()->after('prefix');
            $table->string('full_number', 30)->nullable()->unique()->after('number');
            $table->foreignId('numbering_sequence_id')
                ->nullable()
                ->after('full_number')
                ->constrained('invoice_numbering_sequences');

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['numbering_sequence_id']);
            $table->dropForeign(['voided_by']);
            $table->dropUnique(['full_number']);
            $table->dropColumn([
                'type', 'prefix', 'number', 'full_number',
                'numbering_sequence_id', 'voided_at', 'voided_by', 'void_reason',
            ]);
        });
    }
};
