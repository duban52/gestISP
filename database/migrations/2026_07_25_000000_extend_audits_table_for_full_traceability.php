<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía la auditoría a TODO el sistema.
 *
 * La tabla ya existía para las facturas (fase 4 de facturación) con lo
 * mínimo: quién, qué modelo y valores antes/después. Para poder auditar
 * de verdad hace falta el CONTEXTO de cada acción: en qué sucursal y
 * con qué rol se hizo, desde qué pantalla, con qué navegador y en qué
 * sesión.
 *
 * Los registros que ya existen se conservan intactos: las columnas
 * nuevas son opcionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            // Contexto de quién actuaba
            $table->foreignId('branch_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->string('user_name')->nullable()->after('branch_id');
            $table->string('role_name')->nullable()->after('user_name');

            // Qué se hizo, en palabras
            $table->string('description')->nullable()->after('action');
            $table->string('category', 40)->nullable()->after('description');

            // Desde dónde
            $table->string('route_name')->nullable()->after('ip');
            $table->string('method', 10)->nullable()->after('route_name');
            $table->text('url')->nullable()->after('method');
            $table->string('user_agent')->nullable()->after('url');

            // Correlación: todas las filas de una misma petición
            // comparten request_id, y la sesión enlaza con la
            // trazabilidad de accesos ya existente.
            $table->uuid('request_id')->nullable()->after('user_agent');
            $table->unsignedBigInteger('trace_session_id')->nullable()->after('request_id');

            // Datos extra de las acciones que no son cambios de modelo
            $table->json('context')->nullable()->after('new_values');

            $table->index('user_id');
            $table->index('branch_id');
            $table->index('action');
            $table->index('category');
            $table->index('created_at');
            $table->index('request_id');
        });

        // 'action' se creó con 20 caracteres, suficiente para
        // created/updated/deleted pero no para las acciones con nombre
        // ("technical_order.process"). Se amplía con SQL directo para
        // no depender de doctrine/dbal.
        DB::statement('ALTER TABLE audits MODIFY action VARCHAR(100) NOT NULL');

        // La tabla nació ligada a un modelo (morphs obliga a ambas
        // columnas). Ahora también registra acciones que no afectan a
        // ningún registro concreto —exportar un listado, un intento de
        // acceso fallido—, así que la relación pasa a ser opcional.
        DB::statement('ALTER TABLE audits MODIFY auditable_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE audits MODIFY auditable_id BIGINT UNSIGNED NULL');
    }

    /**
     * Deshace los cambios.
     *
     * Se comprueba la existencia de cada índice y columna antes de
     * borrarlos: si la tabla quedó a medias por un intento anterior,
     * un `down()` rígido vuelve a fallar y deja la base de datos en un
     * estado peor. Así el rollback siempre termina.
     */
    public function down(): void
    {
        $indices = collect(DB::select('SHOW INDEX FROM audits'))->pluck('Key_name')->unique();

        foreach ([
            'audits_user_id_index',
            'audits_branch_id_index',
            'audits_action_index',
            'audits_category_index',
            'audits_created_at_index',
            'audits_request_id_index',
        ] as $indice) {
            if ($indices->contains($indice)) {
                DB::statement("DROP INDEX {$indice} ON audits");
            }
        }

        $columnas = collect(DB::select('SHOW COLUMNS FROM audits'))->pluck('Field');

        if ($columnas->contains('branch_id')) {
            // La clave foránea se llama así por convención de Laravel
            try {
                DB::statement('ALTER TABLE audits DROP FOREIGN KEY audits_branch_id_foreign');
            } catch (\Throwable $e) {
                // Ya no estaba: se continúa con el borrado de la columna
            }

            DB::statement('ALTER TABLE audits DROP COLUMN branch_id');
        }

        foreach ([
            'user_name', 'role_name', 'description', 'category',
            'route_name', 'method', 'url', 'user_agent',
            'request_id', 'trace_session_id', 'context',
        ] as $columna) {
            if ($columnas->contains($columna)) {
                DB::statement("ALTER TABLE audits DROP COLUMN {$columna}");
            }
        }

        DB::statement('ALTER TABLE audits MODIFY action VARCHAR(20) NOT NULL');
    }
};
