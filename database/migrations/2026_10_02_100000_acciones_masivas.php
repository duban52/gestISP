<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acciones masivas: qué se ejecutó, sobre qué, y cómo deshacerlo.
 *
 * POR QUÉ DOS TABLAS NUEVAS Y NO `audits`
 * ---------------------------------------
 * La auditoría ya registra quién hizo qué, con su IP y su contexto, y
 * se queda. Lo que no puede hacer es sostener una operación: una
 * importación de 50.000 filas son 50.000 registros con su estado
 * ANTES, su estado DESPUÉS y si ya se revirtió o no. Metido en
 * `audits` —que ya crece de más, por eso existe `audits:purgar`—
 * ahogaría la bitácora y seguiría sin poder responder la única
 * pregunta que importa aquí: ¿esto se puede deshacer?
 *
 * LAS TRES TABLAS QUE YA HABÍA SIGUEN DONDE ESTÁN
 * -----------------------------------------------
 * `billing_runs`, `contract_cutoffs` y `ont_import_runs` tienen datos
 * propios de su proceso —el período facturado, el umbral de mora, la
 * OLT— y pantallas que los usan. Esto no las sustituye: las enlaza.
 * Cada acción masiva apunta al registro propio de su proceso cuando
 * existe, y así el historial es uno solo sin romper lo que ya
 * funciona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mass_actions', function (Blueprint $table) {
            $table->id();

            // Qué clase de operación fue. Es lo que decide qué
            // estrategia sabe revertirla.
            $table->string('type', 60);
            $table->string('status', 30)->default('iniciada');

            // Para la pantalla: una línea que explique de qué iba.
            $table->string('description', 500);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            // Contadores. Se llevan aquí y no se cuentan sobre los
            // ítems: el historial lista decenas de acciones y contar
            // ítems en cada fila sería una consulta por fila.
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('ok_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('reverted_items')->default(0);
            $table->unsignedInteger('conflict_items')->default(0);

            // Lo propio del proceso: el archivo de la importación, el
            // período de la corrida, el umbral del corte. Cada tipo
            // guarda lo suyo y la vista lo pinta tal cual.
            $table->json('summary')->nullable();

            // El registro propio del proceso, cuando lo tiene
            // (billing_runs, contract_cutoffs, ont_import_runs). Morph
            // porque son tablas distintas y ninguna manda sobre otra.
            $table->nullableMorphs('source');

            // LA CADENA DE LA REVERSIÓN.
            //
            // Revertir es, a su vez, una acción masiva: tiene su
            // usuario, su fecha, sus ítems y sus conflictos. Las dos se
            // apuntan entre sí para que desde cualquiera de las dos se
            // llegue a la otra.
            $table->foreignId('reverses_mass_action_id')->nullable()
                ->constrained('mass_actions')->nullOnDelete();
            $table->foreignId('reverted_by_mass_action_id')->nullable()
                ->constrained('mass_actions')->nullOnDelete();

            // La petición que la originó: enlaza con las filas que la
            // auditoría escribió en ese mismo momento.
            $table->uuid('request_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('mass_action_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mass_action_id')->constrained()->cascadeOnDelete();

            // Sobre qué actuó. Nullable porque una importación que
            // falla en la fila 40 no llegó a crear nada, y ese fracaso
            // también hay que poder contarlo.
            $table->nullableMorphs('subject');

            // Con qué lo reconoce una persona: el número de contrato,
            // el usuario PPPoE, el número de factura. El id interno no
            // le dice nada a nadie en una pantalla.
            $table->string('label', 150)->nullable();

            $table->string('status', 30)->default('pendiente');
            $table->string('message', 1000)->nullable();

            // SOLO LOS CAMPOS QUE CAMBIARON, no el registro entero.
            //
            // Es lo que permite comparar sin almacenar de más: con
            // 50.000 ítems, un volcado completo por registro multiplica
            // la base por nada. `after` es además lo que se compara con
            // el estado ACTUAL antes de revertir: si no coincide, es que
            // alguien lo cambió después y no se toca.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // Reversión: cuándo se revirtió este ítem —sirve de candado
            // para que un reintento no lo haga dos veces— y por qué no
            // se pudo.
            $table->timestamp('reverted_at')->nullable();
            $table->string('conflict_reason', 1000)->nullable();

            $table->timestamps();

            $table->index(['mass_action_id', 'status']);
            // Para responder «¿qué le han hecho a este contrato?» desde
            // la ficha del propio contrato.
            $table->index(['subject_type', 'subject_id'], 'mass_action_items_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_action_items');
        Schema::dropIfExists('mass_actions');
    }
};
