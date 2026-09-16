<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La marca de la ONT.
 *
 * `display ont version` da Vendor-ID (HWTC) y Equipment-ID (ZK9004WT):
 * marca y modelo. Solo había columna para el modelo, y meter los dos en
 * una impediría filtrar por marca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->string('vendor', 30)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->dropColumn('vendor');
        });
    }
};
