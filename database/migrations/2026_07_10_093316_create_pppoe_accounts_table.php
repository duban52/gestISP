<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pppoe_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('router_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('mikrotik_id')->nullable();      // .id interno de mikrotik (*1A etc.)
            $table->string('username');
            $table->string('password');
            $table->string('profile');                       // perfil pppoe (plan de velocidad)
            $table->string('service')->default('pppoe');
            $table->string('remote_address')->nullable();    // IP fija si aplica
            $table->boolean('disabled')->default(false);
            $table->string('comment')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('router_id')->references('id')->on('routers');
            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->unique(['router_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_accounts');
    }
};
