<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcas para no repetir los avisos de facturación.
 *
 * El comando que avisa "por vencer" y "vencida" corre a diario. Sin
 * una marca de "ya avisado", cada corrida volvería a enviar el mismo
 * mensaje al cliente. Estas columnas guardan cuándo se envió cada
 * aviso, de modo que el comando es idempotente: avisa una sola vez
 * por factura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('due_soon_notified_at')->nullable()->after('void_reason');
            $table->timestamp('overdue_notified_at')->nullable()->after('due_soon_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['due_soon_notified_at', 'overdue_notified_at']);
        });
    }
};
