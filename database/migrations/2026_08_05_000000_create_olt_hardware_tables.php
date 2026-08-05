<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario físico de la OLT: tarjetas, uplinks y métricas de puerto.
 *
 * POR QUÉ HACÍA FALTA
 * -------------------
 * Hasta ahora los puertos PON se conocían de rebote, mirando dónde
 * había ONTs conectadas. Eso responde "dónde hay clientes" pero no
 * "qué puertos tiene esta OLT", que es justo lo que se necesita al
 * planear: al crear una zona o colgar una caja hay que poder elegir
 * entre TODOS los puertos del equipo, incluidos los que todavía están
 * vacíos — que son precisamente los que interesan para crecer.
 *
 * QUÉ SE GUARDA Y QUÉ NO
 * ----------------------
 * Estas tablas son un espejo del hardware, no documentación: se
 * rellenan solas desde el equipo (`olt:discover-ports`) y se pueden
 * borrar y volver a descubrir sin perder nada. Lo que SÍ es
 * documentación —la zona, el splitter, las cajas colgadas— vive en
 * pon_ports y sobrevive a cualquier redescubrimiento, porque los
 * puertos se emparejan por su posición física (frame/slot/port), no
 * por el ifIndex, que la OLT puede reasignar al reiniciar una tarjeta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Tarjetas ----------
        Schema::create('olt_boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('frame')->default(0);
            $table->unsignedSmallInteger('slot');

            // Nombre del modelo de tarjeta (GPBD, GPFD, H901XGHD…).
            // Es opcional: solo se rellena si el equipo lo publica.
            $table->string('name', 60)->nullable();
            // pon | uplink | control | desconocida
            $table->string('type', 20)->default('desconocida');
            $table->unsignedSmallInteger('port_count')->default(0);
            $table->string('status', 30)->nullable();

            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'frame', 'slot'], 'olt_boards_posicion_unique');
        });

        // ---------- Puertos uplink ----------
        Schema::create('olt_uplinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olt_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('if_index');
            $table->string('name', 120);
            // ifAlias: la descripción que el operador puso en el puerto
            $table->string('description')->nullable();

            $table->unsignedSmallInteger('frame')->nullable();
            $table->unsignedSmallInteger('slot')->nullable();
            $table->unsignedSmallInteger('port')->nullable();

            // Velocidad negociada, en Mbps (ifHighSpeed)
            $table->unsignedInteger('speed_mbps')->nullable();
            $table->string('admin_status', 20)->nullable();
            $table->string('oper_status', 20)->nullable();

            // Última lectura, para pintar la ficha sin consultar el
            // equipo. El historial va en olt_port_metrics.
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();
            $table->timestamp('measured_at')->nullable();

            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'if_index'], 'olt_uplinks_if_index_unique');
        });

        // ---------- Historial de tráfico por puerto ----------
        //
        // Una sola tabla para puertos PON y uplinks, con una relación
        // polimórfica. Son la misma clase de dato (contadores de la
        // IF-MIB sobre un ifIndex) y separarlas obligaría a duplicar el
        // poller, la poda y las gráficas.
        Schema::create('olt_port_metrics', function (Blueprint $table) {
            $table->id();
            $table->morphs('port');

            // Contadores crudos: hacen falta para calcular la
            // diferencia con la muestra siguiente.
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();
            // Ya convertidos a bits por segundo, para graficar sin
            // recalcular nada.
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();

            // Solo para puertos PON
            $table->decimal('tx_power', 6, 2)->nullable();
            $table->unsignedSmallInteger('onts_total')->nullable();
            $table->unsignedSmallInteger('onts_online')->nullable();

            $table->timestamp('measured_at');
            $table->timestamps();

            // La consulta de la gráfica: las muestras de un puerto en
            // un rango de tiempo.
            $table->index(['port_type', 'port_id', 'measured_at'], 'olt_port_metrics_serie_index');
        });

        // ---------- Lo descubierto sobre el puerto PON ----------
        Schema::table('pon_ports', function (Blueprint $table) {
            // ifIndex de la IF-MIB: con él se leen tráfico y estado.
            // Se guarda pero NO identifica al puerto: la OLT puede
            // reasignarlo al reiniciar una tarjeta.
            $table->unsignedInteger('if_index')->nullable()->after('port');
            $table->string('board_name', 60)->nullable()->after('if_index');
            $table->string('admin_status', 20)->nullable()->after('board_name');
            $table->string('oper_status', 20)->nullable()->after('admin_status');

            // Últimas lecturas, para pintar la rejilla de la ficha sin
            // ir al equipo ni recorrer el historial.
            $table->decimal('tx_power', 6, 2)->nullable()->after('oper_status');
            $table->unsignedBigInteger('in_bps')->nullable()->after('tx_power');
            $table->unsignedBigInteger('out_bps')->nullable()->after('in_bps');
            $table->timestamp('measured_at')->nullable()->after('out_bps');

            // Cuándo lo vio el descubridor por última vez. Un puerto
            // que deja de aparecer no se borra —puede tener cajas
            // documentadas—: se marca y se avisa en pantalla.
            $table->timestamp('discovered_at')->nullable()->after('measured_at');
        });
    }

    public function down(): void
    {
        Schema::table('pon_ports', function (Blueprint $table) {
            $table->dropColumn([
                'if_index', 'board_name', 'admin_status', 'oper_status',
                'tx_power', 'in_bps', 'out_bps', 'measured_at', 'discovered_at',
            ]);
        });

        Schema::dropIfExists('olt_port_metrics');
        Schema::dropIfExists('olt_uplinks');
        Schema::dropIfExists('olt_boards');
    }
};
