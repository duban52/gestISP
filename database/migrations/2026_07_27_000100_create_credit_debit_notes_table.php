<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas crédito y débito sobre facturas.
 *
 * Son los documentos con los que se corrige una factura ya emitida:
 * la nota CRÉDITO disminuye lo que el cliente debe (devolución,
 * descuento, anulación, ajuste a la baja) y la nota DÉBITO lo aumenta
 * (intereses, gastos por cobrar, ajuste al alza).
 *
 * Una factura emitida NO se modifica ni se borra: se corrige con una
 * nota, que queda como documento independiente y trazable. Por eso
 * cada nota guarda su propio consecutivo, la factura afectada, el
 * concepto normativo del motivo y el texto que lo explica.
 *
 * Los campos de concepto siguen el anexo técnico de facturación
 * electrónica de la DIAN (ver App\Billing\Enums\NoteType).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_debit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // credito | debito
            $table->string('type', 10);

            // Numeración propia, por sucursal y tipo (NC-1, ND-1...)
            $table->string('prefix', 10);
            $table->unsignedInteger('number');
            $table->string('full_number', 30)->unique();

            // Motivo normativo: código del anexo DIAN + su descripción
            // (se guarda el texto para que el documento siga siendo
            // legible aunque la tabla de conceptos cambie).
            $table->string('concept_code', 5);
            $table->string('concept_label');

            // Explicación en palabras: obligatoria, es lo que sustenta
            // la corrección ante una revisión.
            $table->text('reason');

            $table->date('issue_date');

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            // Emitida | Anulada
            $table->string('status', 20)->default('Emitida');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'type']);
            $table->index('invoice_id');
            $table->index('issue_date');
        });

        // Consecutivo por sucursal y tipo de nota
        Schema::create('note_numbering_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('prefix', 10);
            $table->unsignedInteger('current_number')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('note_numbering_sequences');
        Schema::dropIfExists('credit_debit_notes');
    }
};
