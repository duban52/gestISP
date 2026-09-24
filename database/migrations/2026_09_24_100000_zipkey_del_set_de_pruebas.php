<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde queda la ZipKey del set de pruebas.
 *
 * POR QUÉ HACE FALTA UNA COLUMNA
 * ------------------------------
 * La DIAN no contesta si el set está bien: contesta una `ZipKey` y
 * valida después. Para preguntarle el resultado hay que volver a
 * presentarla, así que es un dato que se necesita DESPUÉS, y a veces
 * días después.
 *
 * Hasta ahora solo quedaba en los metadatos del evento
 * `dian.set_de_pruebas` de la trazabilidad. Eso sirve para auditar
 * —quién mandó qué y cuándo— pero no para trabajar: obligaba a ir a
 * buscar a mano el registro de auditoría para poder consultar el
 * estado, y la auditoría es un archivo histórico, no el sitio del que
 * un comando debe leer su configuración.
 *
 * SE GUARDA LA ÚLTIMA, NO TODAS
 * -----------------------------
 * El set se manda hasta que la DIAN lo da por bueno, y lo que interesa
 * es el intento vigente. El historial completo sigue en la
 * trazabilidad, que es donde tiene sentido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dian_configurations', function (Blueprint $table) {
            $table->string('test_set_zip_key', 100)->nullable()->after('test_set_id');
            $table->timestamp('test_set_sent_at')->nullable()->after('test_set_zip_key');
        });
    }

    public function down(): void
    {
        Schema::table('dian_configurations', function (Blueprint $table) {
            $table->dropColumn(['test_set_zip_key', 'test_set_sent_at']);
        });
    }
};
