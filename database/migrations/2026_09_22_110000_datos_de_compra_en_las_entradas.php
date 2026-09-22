<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quién se compró y con qué factura entró el material.
 *
 * Sin esto, una entrada solo decía «Compra»: para saber de qué pedido
 * salieron 500 ONT había que buscar la factura en papel. Va en el
 * movimiento y no en una tabla aparte porque es un dato de ESE ingreso,
 * y así viaja solo al comprobante, al historial y al Excel.
 *
 * Se repite en cada renglón de una entrada de equipos —uno por serial—
 * igual que ya se repiten el motivo y el almacén: es el precio de que
 * cada serial sea su propio movimiento, y es lo que permite buscar por
 * número de factura y encontrar el equipo.
 *
 * El proveedor es TEXTO, no un catálogo: se propone lo ya escrito para
 * que no aparezcan tres formas del mismo nombre. Si algún día hace
 * falta un maestro de proveedores (NIT, contacto, cuentas por pagar),
 * esta columna es de dónde sale la lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->string('supplier', 150)->nullable()->after('reason');
            $table->string('invoice_number', 60)->nullable()->after('supplier');
            $table->date('invoice_date')->nullable()->after('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropColumn(['supplier', 'invoice_number', 'invoice_date']);
        });
    }
};
