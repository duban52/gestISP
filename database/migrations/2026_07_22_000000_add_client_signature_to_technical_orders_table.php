<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Firma del cliente al cerrar la orden en campo. Se guarda la
     * ruta de la imagen PNG (capturada en el celular del técnico),
     * igual que la evidencia fotográfica.
     */
    public function up(): void
    {
        Schema::table('technical_orders', function (Blueprint $table) {
            $table->string('client_signature')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('technical_orders', function (Blueprint $table) {
            $table->dropColumn('client_signature');
        });
    }
};
