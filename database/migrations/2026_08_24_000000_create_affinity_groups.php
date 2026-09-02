<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El grupo de afinidad (fase 5 de multiempresa).
 *
 * QUÉ ES
 * ------
 * Una clasificación de los contratos DENTRO de una empresa. Lo que
 * decide, contrato a contrato, por qué camino sale su factura: uno
 * electrónico que se reporta a la DIAN, u otro interno que no.
 *
 * POR QUÉ ESTE NOMBRE
 * -------------------
 * Se descartaron dos alternativas. «Tipo de facturación» ata el
 * nombre a un solo uso, y la idea es poder colgar más reglas del
 * grupo con el tiempo. «Categoría» ya existe en el proyecto —
 * `Category`, la del inventario— y leer código con dos cosas
 * distintas llamadas igual es una fuente de errores.
 *
 * POR QUÉ CUELGA DE LA EMPRESA Y NO DE LA SUCURSAL
 * ------------------------------------------------
 * Es una clasificación fiscal y comercial del CONTRIBUYENTE. El mismo
 * grupo tiene que poder usarse en varias sedes: «corporativo» o
 * «cortesía» no cambian de significado al cruzar de ciudad. Además,
 * de él dependerá la serie de numeración, y las series son del NIT.
 *
 * LOS CAMPOS DE LA DIAN NACEN VACÍOS
 * ----------------------------------
 * `dian_operation_type_code`, `default_payment_means_code` y
 * `default_payment_method_code` salen de los catálogos del anexo
 * técnico y se rellenan en la fase de datos fiscales. Se crean ya
 * para no volver a alterar la tabla, y admiten nulo porque exigirlos
 * hoy impediría crear un grupo a quien todavía no tiene esa
 * información — que es todo el mundo hasta la fase 8.
 *
 * QUÉ HACE ESTA MIGRACIÓN CON LOS DATOS QUE YA EXISTEN
 * ----------------------------------------------------
 * Crea un grupo «General» por empresa y le asigna TODOS los contratos
 * que ya hay. No se deja ninguno sin grupo a propósito: un contrato
 * sin grupo es un contrato del que no se sabe cómo hay que facturarlo,
 * y eso solo se descubriría al emitir.
 *
 * La columna se deja NULLABLE aun así. Es deliberado: hacerla
 * obligatoria en la base convertiría cualquier contrato huérfano —uno
 * creado por un camino que se nos escapara— en un error de base de
 * datos en producción. Lo obligatorio se exige en la validación del
 * alta, donde se puede explicar; la base solo garantiza que si hay
 * grupo, existe y es de una empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affinity_groups', function (Blueprint $table) {
            $table->id();

            // SIN cascadeOnUpdate, y no por descuido.
            //
            // MySQL prohíbe que una clave foránea con CASCADE, SET NULL
            // o SET DEFAULT recaiga sobre una columna que sirve de base
            // a una columna generada STORED — y `company_id` alimenta
            // `default_company_id`, la de más abajo. Lo rechaza con un
            // «Cannot add foreign key constraint» que no menciona la
            // columna generada por ningún lado.
            //
            // No se pierde nada: los id no se reescriben nunca, así que
            // el CASCADE en actualización no tenía a quién propagar.
            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // ---- Identificación ----
            // El código es corto y estable: es lo que se verá en
            // informes y, más adelante, en el prefijo de la serie.
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // ---- La regla que de verdad importa ----
            // Si los contratos de este grupo facturan electrónicamente.
            // Arranca en false: hasta la fase 9 no hay ningún camino
            // electrónico, y un valor por defecto en true prometería
            // algo que el sistema todavía no sabe hacer.
            $table->boolean('requires_electronic_invoicing')->default(false);

            // Si exige que el cliente tenga sus datos fiscales
            // completos. Sirve para avisar AL DAR DE ALTA el contrato
            // en vez de descubrirlo el día de emitir la factura.
            $table->boolean('requires_client_tax_data')->default(false);

            // ---- Catálogos DIAN (se rellenan en la fase 8) ----
            $table->string('dian_operation_type_code', 10)->nullable();
            $table->string('default_payment_means_code', 10)->nullable();
            $table->string('default_payment_method_code', 10)->nullable();

            // ---- Estado y orden ----
            $table->boolean('active')->default(true);

            // El grupo que reciben los contratos que no eligen ninguno.
            // Solo uno por empresa; lo garantiza el índice de abajo.
            $table->boolean('is_default')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();

            // ---- Un solo grupo por defecto por empresa ----
            //
            // MySQL no tiene índices parciales (WHERE is_default = 1),
            // así que se consigue con una columna generada: vale
            // company_id cuando el grupo es el predeterminado y NULL
            // cuando no. Un UNIQUE sobre ella deja pasar tantos NULL
            // como haga falta y rechaza el segundo predeterminado de
            // la misma empresa.
            //
            // Va DENTRO del create y no en un ALTER posterior: MySQL 8
            // no admite añadir una columna generada STORED a una tabla
            // que ya tiene claves foráneas —y lo reporta con un
            // «Cannot add foreign key constraint» que no dice nada del
            // problema real.
            //
            // Se hace en la base y no solo en el código porque una
            // regla que solo vive en PHP se salta con un seeder, un
            // comando o una importación, y dos predeterminados dejan
            // la asignación automática dependiendo del orden de la
            // consulta.
            $table->unsignedBigInteger('default_company_id')
                ->nullable()
                ->storedAs('IF(is_default = 1, company_id, NULL)');

            // El código es único DENTRO de la empresa: dos empresas
            // pueden tener cada una su grupo "GEN".
            $table->unique(['company_id', 'code'], 'affinity_groups_company_code_unique');
            $table->index(['company_id', 'active'], 'affinity_groups_company_active_index');
            $table->unique('default_company_id', 'affinity_groups_one_default_per_company');
        });

        Schema::table('contracts', function (Blueprint $table) {
            // NULLABLE a propósito: ver la cabecera del archivo.
            $table->foreignId('affinity_group_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('affinity_groups')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->index(['affinity_group_id', 'status'], 'contracts_group_status_index');
        });

        $this->crearGrupoGeneralYAsignarContratos();
    }

    /**
     * Un grupo «General» por empresa, con todos sus contratos dentro.
     *
     * Se hace aquí y no en un seeder para que una base ya en uso quede
     * consistente en el mismo paso que crea la tabla: entre migrar y
     * sembrar no puede haber una ventana en la que existan contratos
     * sin grupo.
     */
    private function crearGrupoGeneralYAsignarContratos(): void
    {
        // withoutGlobalScope: aquí se recorren TODAS las empresas, que
        // es justo lo que el scope de empresa impide en una petición
        // normal.
        $empresas = Company::withoutGlobalScope('empresa')->get();

        foreach ($empresas as $empresa) {
            $grupoId = DB::table('affinity_groups')->insertGetId([
                'company_id' => $empresa->id,
                'code' => 'GEN',
                'name' => 'General',
                'description' => 'Grupo creado automáticamente al implantar los grupos de afinidad. '
                    . 'Contiene los contratos que ya existían.',
                'requires_electronic_invoicing' => false,
                'requires_client_tax_data' => false,
                'active' => true,
                'is_default' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Los contratos de la empresa son los de sus sucursales.
            $sucursales = DB::table('branches')
                ->where('company_id', $empresa->id)
                ->pluck('id');

            if ($sucursales->isEmpty()) {
                continue;
            }

            DB::table('contracts')
                ->whereIn('branch_id', $sucursales)
                ->whereNull('affinity_group_id')
                ->update(['affinity_group_id' => $grupoId]);
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // La clave foránea PRIMERO. MySQL se apoya en el índice
            // para comprobarla, así que intentar borrar el índice antes
            // que la FK falla — y el error no dice que el problema sea
            // el orden.
            $table->dropForeign(['affinity_group_id']);
            $table->dropIndex('contracts_group_status_index');
            $table->dropColumn('affinity_group_id');
        });

        // La columna generada y su UNIQUE se van con la tabla.
        Schema::dropIfExists('affinity_groups');
    }
};
