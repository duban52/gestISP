<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notas credito y debito electronicas.
 *
 * QUE FALTABA
 * -----------
 * Una nota que corrige una factura ELECTRONICA tiene que ser tambien
 * electronica: si no, se estaria ajustando ante la DIAN un documento que
 * ella validó, sin decirselo. Hasta ahora todas las notas salian como
 * documento interno.
 *
 * LO QUE **NO** HACIA FALTA, Y CONVIENE DEJAR DICHO
 * -------------------------------------------------
 * La decision D6 del plan daba por hecho que una nota electronica
 * tendria que numerarse de un rango autorizado, como la factura. Es
 * FALSO, y se comprobo leyendo el anexo:
 *
 *   · El XML de una nota NO lleva el bloque `sts:InvoiceControl` —el de
 *     la resolucion y el rango—. Se verificó en los ejemplos oficiales
 *     `CreditNote.xml` y `DebitNote.xml`.
 *   · Y el propio anexo lo dice (§12.1): para estos documentos el
 *     facturador «no debera usar la numeracion de contingencia [...]
 *     sino la numeracion establecida por el facturador».
 *
 * Asi que la numeracion propia que ya hacia NoteIssuer era la correcta
 * desde el principio. Lo que faltaba era el documento electronico: su
 * CUDE, su XML y su transmision.
 *
 * QUE SE ANADE
 * ------------
 * 1. `document_kind` en la nota, CONGELADO al emitirla, igual que en la
 *    factura. Una nota emitida no cambia de naturaleza porque despues
 *    cambie otra cosa.
 * 2. `credit_debit_note_id` en el documento electronico, para que pueda
 *    colgar de una nota y no solo de una factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_debit_notes', function (Blueprint $table) {
            // 'internal' o 'electronic'. Se decide al emitir y no se
            // recalcula: si manana la empresa apaga la facturacion
            // electronica, esta nota siguio siendo lo que fue.
            $table->string('document_kind', 20)->default('internal')->after('type');

            $table->index(['document_kind', 'status'], 'notes_kind_status_index');
        });

        // Las notas que ya existen son internas: se emitieron antes de
        // que esto existiera, y ninguna se transmitio a la DIAN.
        DB::table('credit_debit_notes')->update(['document_kind' => 'internal']);

        Schema::table('electronic_documents', function (Blueprint $table) {
            // Un documento electronico cuelga de UNA factura o de UNA
            // nota, nunca de las dos. Las dos columnas son nulables y
            // unicas: MySQL admite tantos NULL como haga falta en un
            // indice unico, asi que esto no estorba a las facturas.
            $table->foreignId('credit_debit_note_id')
                ->nullable()
                ->unique()
                ->after('invoice_id')
                ->constrained('credit_debit_notes')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_debit_note_id');
        });

        Schema::table('credit_debit_notes', function (Blueprint $table) {
            $table->dropIndex('notes_kind_status_index');
            $table->dropColumn('document_kind');
        });
    }
};
