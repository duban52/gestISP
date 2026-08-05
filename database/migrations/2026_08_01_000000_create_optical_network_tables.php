<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentación de la red óptica (ODN).
 *
 * LA JERARQUÍA Y POR QUÉ ES ASÍ
 * -----------------------------
 *   Sucursal
 *    └── RED            el planta externa de una sede ("Red Gómez Plata")
 *         ├── PUERTO PON   el troncal que sale de la OLT (0/1/1)
 *         ├── ZONA         sector que agrupa varios puertos PON
 *         └── NAP / CTO    la caja de la calle, con sus puertos
 *              └── PUERTO  donde se conecta un contrato
 *
 * La ZONA existe porque es la unidad con la que se PLANEA. Una NAP al
 * 90% se resuelve poniendo otra caja; una zona al 90% se resuelve
 * tirando otro puerto PON, que es una obra completamente distinta. Sin
 * esa capa no hay forma de ver venir la segunda.
 *
 * Es OPCIONAL a propósito: obligar a crear zonas antes de poder
 * registrar la primera caja frenaría la documentación de una red que
 * ya está tendida, que es justo lo que este módulo viene a resolver.
 *
 * LOS PUERTOS DE LA NAP SON FILAS, NO UN CONTADOR
 * -----------------------------------------------
 * Se genera una fila por puerto. Cuesta poco y permite lo que en campo
 * pasa todo el tiempo: marcar un puerto como dañado o reservado, y
 * saber qué contratos han pasado por él.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Redes ----------
        Schema::create('optical_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();

            // Consecutivo de cajas, igual que la numeración de
            // contratos: el técnico pide "la NAP-014", no un id.
            $table->string('nap_prefix', 10)->default('NAP');
            $table->unsignedInteger('nap_next_number')->default(1);

            $table->boolean('active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('branch_id');
        });

        // ---------- Zonas ----------
        Schema::create('network_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_network_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // Color con el que se pintan sus cajas en el mapa
            $table->string('color', 7)->default('#3388ff');
            $table->timestamps();

            $table->index('optical_network_id');
        });

        // ---------- Puertos PON ----------
        Schema::create('pon_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_zone_id')->nullable()
                ->constrained('network_zones')->nullOnDelete();

            // Tarjeta y puerto tal como los nombra la OLT (0/1/1)
            $table->unsignedSmallInteger('frame')->default(0);
            $table->unsignedSmallInteger('slot');
            $table->unsignedSmallInteger('port');

            $table->string('description')->nullable();
            // Splitter primario, si lo hay: "1:8", "1:4"
            $table->string('splitter_ratio', 10)->nullable();
            // Tope de ONTs. GPON admite 128 por norma, pero se reparte
            // entre 32 y 64 para no quedarse sin ancho de banda.
            $table->unsignedSmallInteger('max_onts')->default(64);

            $table->boolean('active')->default(true);
            $table->timestamps();

            // Un puerto físico existe una sola vez por OLT
            $table->unique(['olt_id', 'frame', 'slot', 'port'], 'pon_ports_fisico_unique');
            $table->index('optical_network_id');
        });

        // ---------- Cajas NAP / CTO ----------
        Schema::create('nap_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optical_network_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pon_port_id')->constrained('pon_ports')->cascadeOnDelete();
            $table->foreignId('network_zone_id')->nullable()
                ->constrained('network_zones')->nullOnDelete();

            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->string('splitter_ratio', 10)->nullable();

            // Ubicación: dirección para el técnico, coordenadas para
            // el mapa. Decimal(10,7) da precisión de ~1 cm, de sobra.
            $table->string('address')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // operativa | mantenimiento | retirada
            $table->string('status', 20)->default('operativa');
            $table->text('notes')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['optical_network_id', 'code'], 'nap_boxes_code_unique');
            $table->index('pon_port_id');
            $table->index('network_zone_id');
        });

        // ---------- Puertos de cada caja ----------
        Schema::create('nap_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nap_box_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');

            // libre | danado | reservado.
            // "ocupado" NO se guarda: se deduce de si hay un contrato
            // apuntando al puerto. Guardarlo sería un segundo lugar
            // donde la verdad puede quedar desalineada.
            $table->string('status', 20)->default('libre');
            $table->string('notes')->nullable();

            $table->timestamps();

            $table->unique(['nap_box_id', 'number'], 'nap_ports_numero_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nap_ports');
        Schema::dropIfExists('nap_boxes');
        Schema::dropIfExists('pon_ports');
        Schema::dropIfExists('network_zones');
        Schema::dropIfExists('optical_networks');
    }
};
