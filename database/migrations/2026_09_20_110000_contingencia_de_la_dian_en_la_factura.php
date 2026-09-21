<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se expidió una factura en contingencia de la DIAN.
 *
 * El anexo técnico (§12.2) dice qué hacer cuando el servicio de
 * validación de la DIAN no responde: tras agotar los intentos, el
 * documento se expide y se le entrega al adquiriente SIN validación
 * previa, declarado con `InvoiceTypeCode = 04`, y se transmite
 * dentro de las 48 horas siguientes.
 *
 * El sistema ya detectaba la situación —DocumentTransmitter lo dice
 * en su comentario— pero seguía declarando tipo 01, que es el de una
 * factura validada. Faltaba esta marca para poder decir la verdad en
 * el XML.
 *
 * Va en la FACTURA y no en el documento electrónico porque es un
 * hecho de la factura: así se expidió, y su representación gráfica
 * tiene que decirlo igual que su XML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('contingency_at')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('contingency_at');
        });
    }
};
