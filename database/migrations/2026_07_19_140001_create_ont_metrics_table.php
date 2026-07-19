<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de métricas de las ONTs.
 *
 * Cada ejecución del poller (onts:poll) guarda una muestra por
 * ONT. De aquí salen las gráficas de la vista de detalle:
 * evolución de la potencia óptica y del ancho de banda.
 *
 * El tráfico se guarda de dos formas: los contadores crudos
 * (necesarios para calcular la diferencia con la siguiente
 * muestra) y los bits por segundo ya calculados, para poder
 * graficar sin recalcular nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ont_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ont_id')->constrained()->onDelete('cascade');

            // Ópticas
            $table->decimal('rx_power', 6, 2)->nullable();
            $table->decimal('tx_power', 6, 2)->nullable();
            $table->decimal('olt_rx_power', 6, 2)->nullable();
            $table->decimal('temperature', 6, 2)->nullable();
            $table->decimal('voltage', 6, 2)->nullable();
            $table->decimal('bias_current', 8, 3)->nullable();
            $table->unsignedInteger('distance')->nullable();
            $table->string('run_status', 20)->nullable();

            // Tráfico: contadores crudos y velocidad calculada
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();

            $table->timestamp('measured_at')->index();
            $table->timestamps();

            // Consulta típica: las muestras de una ONT en un rango
            $table->index(['ont_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ont_metrics');
    }
};
