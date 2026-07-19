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
        Schema::create('user_branch', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Relación con la tabla users
            $table->foreignId('branch_id')->constrained()->onDelete('cascade'); // Relación con la tabla branches
            // Rol del usuario en esta sucursal. Sin FK porque la tabla
            // roles (Spatie) se crea en una migración posterior; el
            // código garantiza la integridad vía validación exists.
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_branch');
    }
};
