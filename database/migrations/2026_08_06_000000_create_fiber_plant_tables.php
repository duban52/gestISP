<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planta de fibra: muflas, cables, hilos, fusiones y splitters.
 *
 * QUÉ RESUELVE
 * ------------
 * Hasta ahora la red documentada llegaba hasta el puerto PON y saltaba
 * directamente a la caja NAP, como si entre uno y otra no hubiera nada.
 * En el medio está lo que de verdad se rompe: los cables y las muflas.
 *
 * Con esto se puede responder la pregunta que hoy se contesta llamando
 * al que lleva más años en la empresa: «si corto esta mufla, ¿a quién
 * dejo sin servicio?».
 *
 * LA DECISIÓN QUE SOSTIENE EL MODELO
 * ----------------------------------
 * Cada HILO es una fila. Un cable de 48 son 48 filas, generadas solas
 * al crearlo, igual que los puertos de una caja NAP. Podría guardarse
 * solo la capacidad y llevar los empalmes en un texto, pero entonces no
 * se puede preguntar nada: ni qué hilos quedan libres en el troncal, ni
 * por dónde va un cliente, ni a quién afecta un corte. El coste es
 * ridículo —una red de quinientos cables de 48 son 24.000 filas— y es
 * lo único que convierte el inventario en algo que se usa.
 *
 * Igual que en las cajas NAP, el estado del hilo NO guarda «ocupado»:
 * eso se deduce de si participa en una fusión, alimenta un splitter o
 * entra a una caja. Solo se guarda lo que no se puede deducir: dañado
 * o reservado.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Muflas / cajas de empalme ----------
        Schema::create('splice_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_zone_id')->nullable()
                ->constrained('network_zones')->nullOnDelete();

            $table->string('code', 30);
            $table->string('name')->nullable();
            // aerea | subterranea | pedestal | pared
            $table->string('type', 20)->default('aerea');

            // Cuántas bandejas tiene y cuántas fusiones entran en cada
            // una: es el límite real de lo que se puede empalmar ahí.
            $table->unsignedSmallInteger('tray_count')->default(1);
            $table->unsignedSmallInteger('splices_per_tray')->default(12);

            // Igual que en las cajas NAP: sin ubicación no se encuentra
            // en campo y el registro no sirve para nada.
            $table->string('address');
            $table->string('reference')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->string('status', 20)->default('operativa');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['optical_network_id', 'code'], 'splice_closures_codigo_unique');
        });

        // ---------- Cables ----------
        Schema::create('fiber_cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_zone_id')->nullable()
                ->constrained('network_zones')->nullOnDelete();

            $table->string('code', 30);
            $table->string('name')->nullable();
            // troncal | distribucion | acometida
            $table->string('type', 20)->default('distribucion');

            // La capacidad y su reparto. Se valida que
            // fiber_count = buffer_count * fibers_per_buffer.
            $table->unsignedSmallInteger('fiber_count');
            $table->unsignedSmallInteger('buffer_count');
            $table->unsignedSmallInteger('fibers_per_buffer');

            // Extremos. Polimórficos porque un cable puede ir de una
            // OLT a una mufla, de mufla a mufla, o de mufla a caja NAP:
            // es lo que convierte el inventario en un grafo recorrible.
            $table->nullableMorphs('from');
            $table->nullableMorphs('to');

            $table->unsignedInteger('length_m')->nullable();
            // aereo | canalizado | subterraneo | fachada
            $table->string('installation', 20)->nullable();
            $table->string('owner')->nullable();
            $table->string('status', 20)->default('operativo');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['optical_network_id', 'code'], 'fiber_cables_codigo_unique');
        });

        // ---------- Hilos ----------
        Schema::create('cable_strands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiber_cable_id')->constrained()->cascadeOnDelete();

            // Número corrido dentro del cable (1..48) y su posición
            // física, que es como lo nombra el técnico.
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('buffer_number');
            $table->string('buffer_color', 30);
            $table->unsignedSmallInteger('strand_number');
            $table->string('strand_color', 30);

            // libre | danado | reservado. NUNCA "ocupado": eso se
            // deduce de las fusiones y de a qué alimenta.
            $table->string('status', 20)->default('libre');
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['fiber_cable_id', 'number'], 'cable_strands_posicion_unique');
        });

        // ---------- Fusiones ----------
        Schema::create('splices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('splice_closure_id')->constrained()->cascadeOnDelete();

            // Una fusión une exactamente DOS hilos. El par se guarda
            // siempre en el mismo orden (el id menor primero) para que
            // el índice único impida registrarla dos veces al revés.
            $table->foreignId('strand_a_id')->constrained('cable_strands')->cascadeOnDelete();
            $table->foreignId('strand_b_id')->constrained('cable_strands')->cascadeOnDelete();

            $table->unsignedSmallInteger('tray')->nullable();
            $table->unsignedSmallInteger('position')->nullable();
            // fusion | mecanico
            $table->string('type', 20)->default('fusion');
            // Atenuación medida con la empalmadora, en dB
            $table->decimal('loss_db', 5, 2)->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['strand_a_id', 'strand_b_id'], 'splices_par_unique');
            $table->index('splice_closure_id');
        });

        // ---------- Splitters ----------
        Schema::create('splitters', function (Blueprint $table) {
            $table->id();
            // Solo dentro de muflas: el splitter de una caja NAP se
            // documenta con el ratio de la propia caja, porque sus
            // salidas SON los puertos de la caja. Modelarlo dos veces
            // abriría la puerta a que las dos versiones se contradigan.
            $table->foreignId('splice_closure_id')->constrained()->cascadeOnDelete();

            $table->string('code', 30)->nullable();
            // "1:8", "1:16"…
            $table->string('ratio', 10);
            // "output_count" y no "outputs": el modelo tiene una
            // relacion outputs(), y una columna con el mismo nombre la
            // tapa — Eloquent resuelve primero los atributos, asi que
            // $splitter->outputs devolveria el numero en vez de las
            // salidas, y el fallo aparece lejos de aqui.
            $table->unsignedSmallInteger('output_count');

            // Por dónde entra la señal. Nullable porque una mufla se
            // documenta a veces antes de tener el troncal conectado.
            $table->foreignId('input_strand_id')->nullable()
                ->constrained('cable_strands')->nullOnDelete();

            $table->decimal('insertion_loss_db', 5, 2)->nullable();
            $table->unsignedSmallInteger('tray')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('splitter_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('splitter_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');

            // A qué hilo sale. Nullable: un splitter 1:8 con seis
            // salidas usadas es lo normal.
            $table->foreignId('strand_id')->nullable()
                ->constrained('cable_strands')->nullOnDelete();

            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['splitter_id', 'number'], 'splitter_outputs_numero_unique');
            // Un hilo no puede ser la salida de dos splitters
            $table->unique('strand_id', 'splitter_outputs_hilo_unique');
        });

        // ---------- Qué hilo alimenta cada caja NAP ----------
        Schema::table('nap_boxes', function (Blueprint $table) {
            // Es el eslabón que cierra la cadena: OLT → puerto PON →
            // cable → mufla → fusiones/splitter → cable → ESTA caja.
            // Sin él el grafo se corta justo antes del cliente.
            $table->foreignId('feed_strand_id')->nullable()->after('pon_port_id')
                ->constrained('cable_strands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('nap_boxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feed_strand_id');
        });

        Schema::dropIfExists('splitter_outputs');
        Schema::dropIfExists('splitters');
        Schema::dropIfExists('splices');
        Schema::dropIfExists('cable_strands');
        Schema::dropIfExists('fiber_cables');
        Schema::dropIfExists('splice_closures');
    }
};
