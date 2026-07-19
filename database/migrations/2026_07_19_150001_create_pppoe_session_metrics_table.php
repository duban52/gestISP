<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de tráfico de las cuentas PPPoE.
 *
 * Cada ejecución del poller (pppoe:poll) guarda una muestra por
 * cuenta con sesión activa. De aquí sale la gráfica de ancho de
 * banda de la vista de detalle.
 *
 * Se guardan los contadores crudos (necesarios para calcular la
 * diferencia con la muestra siguiente) y los bits por segundo ya
 * calculados, para poder graficar sin recalcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pppoe_session_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pppoe_account_id')->constrained()->onDelete('cascade');

            // Contadores acumulados de la interfaz de la sesión
            $table->unsignedBigInteger('in_octets')->nullable();
            $table->unsignedBigInteger('out_octets')->nullable();

            // Velocidad calculada por diferencia entre muestras
            $table->unsignedBigInteger('in_bps')->nullable();
            $table->unsignedBigInteger('out_bps')->nullable();

            // Contexto de la sesión en el momento de la muestra
            $table->boolean('connected')->default(false);
            $table->string('address', 45)->nullable();
            $table->string('uptime', 40)->nullable();

            $table->timestamp('measured_at')->index();
            $table->timestamps();

            $table->index(['pppoe_account_id', 'measured_at'], 'pppoe_metrics_account_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_session_metrics');
    }
};
