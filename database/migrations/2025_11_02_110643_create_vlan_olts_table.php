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
        Schema::create('vlan_olts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->onDelete('cascade');
            $table->string('id_vlan', 50);
            $table->string('name', 255);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vlan_olts');
    }
};
