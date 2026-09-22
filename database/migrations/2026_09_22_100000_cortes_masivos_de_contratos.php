<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cortes masivos por mora: cada tanda y lo que pasó con cada contrato.
 *
 * POR QUÉ SE GUARDA
 * -----------------
 * El corte de cada contrato va en la cola —la OLT abre una sesión SSH
 * por ONT y cien contratos no caben en una petición web—, así que el
 * resultado no se puede enseñar al pulsar el botón: se consulta
 * después. Y un corte masivo es de las operaciones que alguien
 * reclamará: «¿por qué me cortaron?», «¿quién subió esa lista?».
 *
 * Se guarda TODA la lista, también lo que no se cortó y por qué: el
 * que no debía dos meses, el que ya estaba cortado, el número que no
 * existe. Es lo que responde «yo lo mandé en la lista».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_cutoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            // De dónde salió la lista: «Archivo cartera.xlsx» o «Lista manual».
            $table->string('source', 255);
            // Cuántas facturas vencidas exigía la sucursal ese día: si
            // luego cambia la regla, la tanda se sigue entendiendo.
            $table->unsignedSmallInteger('threshold');
            $table->timestamps();
        });

        Schema::create('contract_cutoff_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_cutoff_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            // Lo que venía en la lista, tal cual: puede no existir.
            $table->string('contract_number', 100);
            $table->string('status', 20);
            $table->string('message', 1000)->nullable();
            $table->unsignedSmallInteger('overdue_count')->nullable();
            $table->decimal('overdue_amount', 15, 2)->nullable();
            $table->foreignId('technical_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['contract_cutoff_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_cutoff_items');
        Schema::dropIfExists('contract_cutoffs');
    }
};
