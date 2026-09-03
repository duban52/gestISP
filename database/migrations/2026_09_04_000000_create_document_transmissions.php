<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El registro de cada intento de transmision a la DIAN (fase 11).
 *
 * POR QUE UNA TABLA Y NO UNAS COLUMNAS EN EL DOCUMENTO
 * ----------------------------------------------------
 * Porque los intentos son VARIOS y hay que poder explicarlos. El anexo
 * obliga a reintentar —tres veces cada 5 segundos ante error, cinco
 * veces cada 2 minutos ante demora— y a «mantener o archivar las
 * evidencias del error» (§12.2). Con unas columnas en el documento cada
 * intento pisaria al anterior y no quedaria ninguna evidencia: solo el
 * ultimo estado.
 *
 * Ademas es lo que permite contestar la pregunta que de verdad se hace
 * cuando algo va mal: «¿que le mandamos y que nos contesto?».
 *
 * QUE NO SE GUARDA AQUI
 * ---------------------
 * El XML enviado. Ya esta en `electronic_documents.signed_xml`, es
 * grande, y no cambia entre intentos: duplicarlo por cada reintento
 * multiplicaria el tamano de la base sin anadir nada. Lo que si se
 * guarda es la RESPUESTA, que es distinta en cada intento y es la
 * evidencia que pide el anexo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_transmissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('electronic_document_id')
                ->constrained('electronic_documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Desnormalizada para poder consultar por empresa sin cruzar
            // dos tablas, igual que en el resto del sistema.
            $table->foreignId('company_id')->nullable()->constrained('companies');

            // El numero de intento, empezando en 1. Es lo que hace
            // legible la secuencia al leer la tabla.
            $table->unsignedSmallInteger('attempt')->default(1);

            // Que paso: accepted, rejected, error, timeout.
            $table->string('outcome', 20);

            // Lo que contesto la DIAN. Puede venir vacio si ni siquiera
            // se llego a hablar con ella.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('track_id', 100)->nullable();
            $table->longText('response')->nullable();

            // Los mensajes de error, tal cual los devuelve la DIAN. Se
            // guardan como lista para poder enseñarlos uno a uno.
            $table->json('errors')->nullable();

            // Cuanto tardo. Sirve para detectar la «demora declarada»
            // del §12.4, que empieza al minuto.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['electronic_document_id', 'attempt'], 'transmissions_document_attempt_index');
            $table->index(['company_id', 'outcome'], 'transmissions_company_outcome_index');
        });

        Schema::table('electronic_documents', function (Blueprint $table) {
            // El identificador que devuelve la DIAN al recibir el
            // documento. Es con lo que se consulta despues su estado.
            $table->string('dian_track_id', 100)->nullable()->after('status');

            // Cuando la DIAN lo dio por bueno. Separado de updated_at
            // porque updated_at cambia por cualquier cosa.
            $table->timestamp('accepted_at')->nullable()->after('signed_at');

            // Cuantas veces se ha intentado ya. Vive aqui y no solo en
            // la tabla de intentos para poder filtrar «los que llevan
            // demasiados» sin agrupar.
            $table->unsignedSmallInteger('attempts')->default(0)->after('accepted_at');

            $table->index(['status', 'attempts'], 'electronic_documents_pendientes_index');
        });
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropIndex('electronic_documents_pendientes_index');
            $table->dropColumn(['dian_track_id', 'accepted_at', 'attempts']);
        });

        Schema::dropIfExists('document_transmissions');
    }
};
