<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schema as Esquema;

/**
 * Planes y servicios pasan a poder ser DE LA EMPRESA.
 *
 * QUÉ CAMBIA
 * ----------
 * `plans.branch_id` y `services.branch_id` pasan a ser nullable, con el
 * mismo significado que ya tienen en `numbering_ranges`:
 *
 *   branch_id = NULL  →  es de la EMPRESA, disponible en todas sus sedes
 *   branch_id = 7     →  exclusivo de esa sede
 *
 * POR QUÉ
 * -------
 * Un servicio lleva UNSPSC, unidad de medida y clasificación de IVA.
 * Eso es del CONTRIBUYENTE, no de la sede. Tenerlo repetido una vez por
 * sucursal no aporta nada —el precio es igual en todas— y sí permite
 * que diverja: ya pasó, con «Servicio de TV» al 19% conviviendo con
 * «Television» al 0%.
 *
 * NO MUEVE NINGÚN DATO
 * --------------------
 * Solo abre la puerta. Todo lo que existe sigue con su `branch_id` y se
 * comporta igual que antes. Consolidar los duplicados es un paso
 * aparte y deliberado: `php artisan gestisp:catalogo-consolidar`.
 *
 * Separarlo importa: una migración que además fusionara catálogos
 * cambiaría lo que se le factura a alguien el mes siguiente, sin que
 * nadie lo hubiera revisado.
 *
 * LA UNICIDAD DEL NOMBRE, Y POR QUÉ HACE FALTA UNA COLUMNA GENERADA
 * -----------------------------------------------------------------
 * MySQL permite VARIOS nulos dentro de un índice único. Así que un
 * `UNIQUE (company_id, branch_id, name)` **no impediría** dos planes de
 * empresa llamados igual — que es justo el caso nuevo.
 *
 * `branch_key = COALESCE(branch_id, 0)` convierte el nulo en un valor
 * comparable. El 0 no colisiona con ninguna sucursal real: los ids
 * empiezan en 1.
 *
 * VIRTUAL Y NO STORED, Y NO ES UN DETALLE
 * ---------------------------------------
 * La primera versión de esta migración la creaba STORED y fallaba con
 * «1215 Cannot add foreign key constraint», que no dice nada de lo que
 * pasa: MySQL no puede añadir una columna generada STORED en el sitio,
 * así que COPIA la tabla — y copiar una tabla referenciada por claves
 * foráneas (`contracts.plan_id` apunta a `plans.id`) revienta.
 *
 * VIRTUAL se añade INPLACE, sin copiar nada, y admite índice secundario
 * igual. No ocupa espacio: se calcula al leer.
 *
 * SE PUEDE REPETIR
 * ----------------
 * Cada paso comprueba antes si ya está hecho. La primera versión dejó
 * la base a medias —índice caído, columnas ya nullable, sin
 * `branch_key`— y una migración que no se puede reanudar obliga a
 * arreglar el destrozo a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. El único viejo, que era por sucursal ----
        //
        // OJO: no se puede quitar sin más. Ese índice era
        // `(branch_id, name)`, y MySQL lo estaba usando para sostener la
        // clave foránea `plans_branch_id_foreign` —una FK necesita algún
        // índice que empiece por su columna—. Quitarlo a secas falla con
        // «1553 Cannot drop index: needed in a foreign key constraint».
        //
        // En la base de desarrollo no pasaba, porque ahí convivían los
        // dos índices; en una base creada desde cero, MySQL descarta el
        // redundante y solo queda este. O sea: fallaba únicamente en
        // instalaciones nuevas, que es la peor forma de fallar.
        //
        // Se le da primero otro índice sobre `branch_id` y entonces sí
        // se puede soltar el único.
        if ($this->hayIndice('plans', 'plans_branch_name_unique')) {
            if (!$this->hayIndice('plans', 'plans_branch_id_index')) {
                Schema::table('plans', function (Blueprint $table) {
                    $table->index('branch_id', 'plans_branch_id_index');
                });
            }

            Schema::table('plans', function (Blueprint $table) {
                $table->dropUnique('plans_branch_name_unique');
            });
        }

        // ---- 2. branch_id deja de ser obligatorio ----
        //
        // En SQL directo y no con ->change() porque el proyecto no
        // tiene doctrine/dbal, que es lo que Laravel 10 necesita para
        // alterar una columna existente.
        foreach (['plans', 'services'] as $tabla) {
            if ($this->esObligatoria($tabla, 'branch_id')) {
                DB::statement("ALTER TABLE {$tabla} MODIFY branch_id BIGINT UNSIGNED NULL");
            }
        }

        // ---- 3. La clave de ámbito, y su único ----
        foreach (['plans', 'services'] as $tabla) {
            if (!Esquema::hasColumn($tabla, 'branch_key')) {
                DB::statement(
                    "ALTER TABLE {$tabla} ADD COLUMN branch_key BIGINT UNSIGNED "
                    . 'AS (COALESCE(branch_id, 0)) VIRTUAL'
                );
            }
        }

        // `services` NO tenía índice único de nombre: se podían crear
        // dos servicios llamados igual en la misma sede. Se le pone
        // ahora, porque a partir de aquí el nombre es lo que usa el
        // consolidador para agrupar.
        //
        // Si ya hubiera homónimos, el índice fallaría con un error de
        // MySQL que no dice cuáles son. Se comprueba antes.
        $this->exigirNombresUnicos('services');
        $this->exigirNombresUnicos('plans');

        foreach (['plans', 'services'] as $tabla) {
            if (!$this->hayIndice($tabla, "{$tabla}_company_branch_name_unique")) {
                Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                    $table->unique(['company_id', 'branch_key', 'name'], "{$tabla}_company_branch_name_unique");
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['services', 'plans'] as $tabla) {
            if ($this->hayIndice($tabla, "{$tabla}_company_branch_name_unique")) {
                Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                    $table->dropUnique("{$tabla}_company_branch_name_unique");
                });
            }

            if (Esquema::hasColumn($tabla, 'branch_key')) {
                DB::statement("ALTER TABLE {$tabla} DROP COLUMN branch_key");
            }
        }

        // Volver a NOT NULL solo es posible si nadie usó el modo «de la
        // empresa». Si alguien lo usó, esto falla — y es lo correcto:
        // revertir borraría la información de que ese plan era
        // compartido.
        DB::statement('ALTER TABLE plans MODIFY branch_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE services MODIFY branch_id BIGINT UNSIGNED NOT NULL');

        Schema::table('plans', function (Blueprint $table) {
            $table->unique(['branch_id', 'name'], 'plans_branch_name_unique');
        });
    }

    // ==================== Apoyo ====================

    private function hayIndice(string $tabla, string $indice): bool
    {
        return DB::table('information_schema.statistics')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $tabla)
            ->where('index_name', $indice)
            ->exists();
    }

    private function esObligatoria(string $tabla, string $columna): bool
    {
        return DB::table('information_schema.columns')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', $tabla)
            ->where('column_name', $columna)
            ->where('is_nullable', 'NO')
            ->exists();
    }

    private function exigirNombresUnicos(string $tabla): void
    {
        $repetidos = DB::table($tabla)
            ->selectRaw('company_id, branch_id, name')
            ->groupBy('company_id', 'branch_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($repetidos->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay %d grupo(s) en `%s` con el mismo nombre en la misma sucursal. '
                . 'Renómbrelos o elimínelos antes de migrar. Para verlos: '
                . 'SELECT company_id, branch_id, name, COUNT(*) FROM %s GROUP BY 1,2,3 HAVING COUNT(*) > 1;',
                $repetidos->count(),
                $tabla,
                $tabla,
            ));
        }
    }
};
