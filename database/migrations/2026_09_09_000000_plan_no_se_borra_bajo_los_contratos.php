<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un plan con contratos vivos deja de poder desaparecer.
 *
 * QUÉ PASABA
 * ----------
 * `contracts.plan_id` estaba declarada con `onDelete('set null')`.
 * Borrar un plan dejaba en null el `plan_id` de TODOS sus contratos, y
 * un contrato sin plan no tiene servicios que facturar —los saca de
 * `$contract->plan->services`—.
 *
 * En una factura interna eso produce un documento absurdo. En una
 * ELECTRÓNICA es caro: gasta un consecutivo del rango autorizado, que
 * no se recupera, en un XML que el XSD de la DIAN rechaza (un
 * `Invoice` sin `InvoiceLine` no es válido). Queda el hueco en la
 * numeración y ningún documento que enseñar.
 *
 * `PlanController::destroy()` ya se negaba a borrar un plan con
 * contratos, y `InvoiceGenerator` ya se niega a emitir una factura
 * vacía. Las dos son redes por encima; esta es la de abajo. Un borrado
 * por SQL, un `cascade` que llegue desde otra tabla o un camino nuevo
 * que nadie recuerde blindar se topan aquí.
 *
 * LA COLUMNA SIGUE SIENDO NULLABLE, Y ES A PROPÓSITO
 * --------------------------------------------------
 * Un contrato PUEDE nacer sin plan: el formulario lo permite y hay
 * casos reales —un contrato que solo cobra cargos adicionales—. Lo que
 * no puede es perderlo por detrás, sin que nadie lo decida.
 *
 * SI FALLA AL APLICARSE
 * ---------------------
 * Solo puede fallar si hay `plan_id` apuntando a planes inexistentes, y
 * eso no debería poder pasar: la clave foránea ya existía. Si ocurre,
 * la consulta que lo encuentra es:
 *
 *   SELECT ct.id, ct.plan_id FROM contracts ct
 *   LEFT JOIN plans p ON p.id = ct.plan_id
 *   WHERE ct.plan_id IS NOT NULL AND p.id IS NULL;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);

            $table->foreign('plan_id')
                ->references('id')->on('plans')
                ->restrictOnDelete();
        });

        // ---- Retirar un plan sin borrarlo ----
        //
        // Va en la misma migración porque es su consecuencia directa:
        // si un plan con contratos ya no se puede borrar, hace falta
        // poder sacarlo del formulario cuando se deja de vender. Sin
        // esto, el listado de planes solo crece.
        if (!Schema::hasColumn('plans', 'active')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->boolean('active')->default(true)->after('name');
            });

            DB::table('plans')->update(['active' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);

            $table->foreign('plan_id')
                ->references('id')->on('plans')
                ->nullOnDelete();
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
