<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->unsignedBigInteger('if_index')->nullable()->after('onu_id');
        });
    }

    public function down(): void
    {
        Schema::table('onts', function (Blueprint $table) {
            $table->dropColumn('if_index');
        });
    }
};
