<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('username');
            $table->string('password');
            $table->string('brand')->nullable()->default('Mikrotik');
            $table->string('model')->nullable();
            $table->boolean('status')->default(false);     // conectado/desconectado
            $table->boolean('active')->default(true);      // habilitado en el sistema
            $table->string('version')->nullable();          // RouterOS version
            $table->string('board_name')->nullable();
            $table->string('uptime')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
