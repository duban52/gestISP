<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Georreferenciación del servicio y del cierre de las órdenes.
 *
 * QUÉ RESUELVE
 * ------------
 * Hasta ahora "dónde queda el servicio" era una dirección escrita a
 * mano. En un ISP eso no alcanza: el barrio se llama distinto según
 * quién lo escriba, las direcciones rurales no existen y nadie puede
 * responder "¿qué caja NAP le queda cerca a este cliente?" sin ir al
 * sitio. Con el punto en el mapa esa pregunta se contesta sola.
 *
 * DOS PUNTOS DISTINTOS, DOS JUEGOS DE COLUMNAS
 * --------------------------------------------
 *  · contracts.latitude/longitude → dónde DEBE estar el servicio.
 *  · technical_orders.closing_* → dónde estaba el técnico cuando
 *    cerró la orden.
 *
 * No se mezclan a propósito: comparar los dos es justo lo que permite
 * saber si el trabajo se hizo en la casa del cliente o a diez
 * kilómetros. Si se guardaran en el mismo sitio no habría nada que
 * comparar.
 *
 * POR QUÉ ES OPCIONAL
 * -------------------
 * Hay miles de contratos vivos sin coordenadas y no se puede obligar a
 * salir a ubicarlos para poder seguir facturando. Las columnas nacen
 * nulas y se van llenando: cada pantalla distingue "sin ubicar" de
 * "ubicado", nunca inventa un punto por defecto.
 *
 * PRECISIÓN DEL TIPO
 * ------------------
 * decimal(10,7) da ~1 cm de resolución, más que suficiente para una
 * acometida, y evita el redondeo binario de un float —que movería el
 * punto unos metros cada vez que se lee y se vuelve a guardar—.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Dónde queda la vivienda del cliente
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // Quién la ubicó, cuándo y con qué. Sin esto una coordenada
            // rara no se puede rastrear: no se sabe si la puso alguien
            // de oficina mirando el mapa o el GPS de un celular.
            $table->timestamp('located_at')->nullable()->after('longitude');
            $table->unsignedBigInteger('located_by')->nullable()->after('located_at');
            $table->string('location_source', 20)->nullable()->after('located_by');

            $table->foreign('located_by')->references('id')->on('users')->nullOnDelete();

            // El listado ofrece "solo contratos sin ubicar" y la
            // búsqueda de cobertura barre por coordenada.
            $table->index(['branch_id', 'latitude', 'longitude'], 'contracts_ubicacion_index');
        });

        Schema::table('technical_orders', function (Blueprint $table) {
            // Dónde estaba el técnico al cerrar
            $table->decimal('closing_latitude', 10, 7)->nullable()->after('client_signature');
            $table->decimal('closing_longitude', 10, 7)->nullable()->after('closing_latitude');

            // Margen de error que reportó el dispositivo, en metros. Es
            // imprescindible para no acusar a nadie injustamente: 300 m
            // de diferencia no significan nada si el GPS admite 500 m
            // de error porque el técnico estaba bajo techo.
            $table->unsignedInteger('closing_accuracy_m')->nullable()->after('closing_longitude');

            $table->timestamp('closing_located_at')->nullable()->after('closing_accuracy_m');

            // Por qué NO hay punto: permiso denegado, sin señal, equipo
            // sin GPS. Guardarlo distingue "no se pudo" de "no se
            // quiso", que es la diferencia entre un fallo técnico y una
            // orden que conviene revisar.
            $table->string('closing_location_error', 150)->nullable()->after('closing_located_at');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['located_by']);
            $table->dropIndex('contracts_ubicacion_index');
            $table->dropColumn(['latitude', 'longitude', 'located_at', 'located_by', 'location_source']);
        });

        Schema::table('technical_orders', function (Blueprint $table) {
            $table->dropColumn([
                'closing_latitude',
                'closing_longitude',
                'closing_accuracy_m',
                'closing_located_at',
                'closing_location_error',
            ]);
        });
    }
};
