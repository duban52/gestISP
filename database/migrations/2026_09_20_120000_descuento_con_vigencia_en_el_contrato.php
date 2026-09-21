<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descuento del contrato, con vigencia.
 *
 * Es lo que hace falta para una promoción: «50% los dos primeros
 * meses», «$10.000 menos durante seis». Hasta ahora la única forma de
 * hacerla era emitir la factura completa y después una nota crédito,
 * que deja al cliente viendo dos documentos por un descuento pactado
 * antes de firmar.
 *
 * VA EN LA LÍNEA, NO EN EL PIE DE LA FACTURA. Un descuento que solo
 * bajara el total dejaría el IVA calculado sobre la base sin
 * descontar: el cliente pagaría impuesto por dinero que no se le
 * cobró, y la DIAN rechazaría por FAS07 (el tributo no corresponde a
 * la base por la tarifa).
 *
 * `discount_months` nulo = sin límite, hasta que alguien lo quite.
 * `discount_applied` cuenta las facturas que ya lo llevaron; sin ese
 * contador, «dos meses» dependería de acordarse de quitarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // 'percent' o 'amount'. Nulo = sin descuento.
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->unsignedSmallInteger('discount_months')->nullable();
            $table->unsignedSmallInteger('discount_applied')->default(0);
            $table->string('discount_reason', 255)->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            // El descuento de ESTA línea, congelado como el resto de lo
            // fiscal. En el XML va como cac:AllowanceCharge y es lo que
            // separa el precio de lista de la base gravable.
            $table->decimal('discount', 15, 2)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'discount_type', 'discount_value', 'discount_months',
                'discount_applied', 'discount_reason',
            ]);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
