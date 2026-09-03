<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La decisión de facturación, congelada en la factura (fase 9).
 *
 * QUÉ SE CONGELA Y POR QUÉ
 * ------------------------
 * Dos cosas, y las dos por el mismo motivo: **una factura emitida no
 * puede cambiar de naturaleza porque alguien edite otra cosa después**.
 *
 *   · `affinity_group_id` — el grupo que tenía el contrato al emitir.
 *     Si mañana ese contrato pasa a otro grupo, las facturas ya
 *     emitidas se quedan como estaban. Es la decisión D7 del plan:
 *     cambiar el grupo NUNCA afecta a lo ya emitido.
 *
 *   · `document_kind` — si esta factura es electrónica o un documento
 *     interno. Se decide UNA vez, al emitirla, y ya no se vuelve a
 *     calcular. Recalcularla al vuelo significaría que la misma
 *     factura puede responder cosas distintas según cuándo se
 *     pregunte, y eso en un documento emitido es inaceptable.
 *
 * LAS DOS SERIES NO SE MEZCLAN
 * ----------------------------
 * `invoice_numbering_sequences` recibe `kind`. Es la decisión D5: los
 * documentos internos no pueden compartir serie con los electrónicos
 * ni agotar sus consecutivos. Un rango autorizado que se gasta con
 * documentos que nunca se reportan deja huecos que hay que justificar.
 *
 * Todas las secuencias que ya existen son internas —no hay ninguna
 * resolución registrada todavía—, así que arrancan como tales y nada
 * cambia de comportamiento.
 *
 * LO QUE ESTA MIGRACIÓN **NO** HACE
 * ---------------------------------
 * No toca ningún consecutivo. Las facturas ya emitidas conservan su
 * número y su secuencia; lo único que reciben es la clasificación que
 * les corresponde según el grupo de su contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // El grupo CONGELADO. Nulo en las facturas anteriores a los
            // grupos, y en las de contratos sin clasificar.
            $table->foreignId('affinity_group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('affinity_groups')
                ->nullOnDelete();

            // 'internal' o 'electronic'. Se decide al emitir y no se
            // recalcula. Nace en 'internal' porque hasta ahora TODAS lo
            // eran: no hay ninguna resolución registrada.
            $table->string('document_kind', 12)->default('internal')->after('type');

            // Forma y medio de pago del catálogo de la DIAN. Nacen
            // vacíos y se rellenan cuando la emisión electrónica los
            // necesite; están aquí para no volver a alterar la tabla.
            $table->string('payment_means_code', 10)->nullable()->after('total');
            $table->string('payment_method_code', 10)->nullable()->after('payment_means_code');

            $table->index(['document_kind', 'status'], 'invoices_kind_status_index');
        });

        Schema::table('invoice_numbering_sequences', function (Blueprint $table) {
            // Qué tipo de documento numera esta serie. Lo que impide
            // que un documento interno gaste un consecutivo autorizado.
            $table->string('kind', 12)->default('internal')->after('branch_id');

            $table->index(['branch_id', 'kind', 'active'], 'invoice_sequences_branch_kind_index');
        });

        $this->clasificarFacturasExistentes();
    }

    /**
     * Copia a cada factura el grupo de su contrato.
     *
     * Se hace con un UPDATE con JOIN y no fila a fila: son todas las
     * facturas del sistema, y recorrerlas en PHP sería una consulta por
     * cada una.
     *
     * `document_kind` se queda en 'internal' para todas, que es lo que
     * son: no existe todavía ninguna resolución ni ninguna empresa con
     * la facturación electrónica encendida.
     */
    private function clasificarFacturasExistentes(): void
    {
        DB::statement(
            'UPDATE invoices
             INNER JOIN contracts ON contracts.id = invoices.contract_id
             SET invoices.affinity_group_id = contracts.affinity_group_id
             WHERE invoices.affinity_group_id IS NULL
               AND contracts.affinity_group_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('invoice_numbering_sequences', function (Blueprint $table) {
            $table->dropIndex('invoice_sequences_branch_kind_index');
            $table->dropColumn('kind');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_kind_status_index');
            $table->dropForeign(['affinity_group_id']);
            $table->dropColumn([
                'affinity_group_id', 'document_kind',
                'payment_means_code', 'payment_method_code',
            ]);
        });
    }
};
