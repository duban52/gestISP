<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuelga las sucursales de su empresa y desbloquea el multiempresa.
 *
 * TRAE LOS DATOS CONSIGO
 * ----------------------
 * El relleno va DENTRO de la migración y no en un comando aparte a
 * propósito: si la columna se creara vacía y el relleno fuera un paso
 * manual posterior, cualquier despliegue que se quede a medias deja
 * sucursales sin empresa — y con las restricciones nuevas eso es una
 * base inconsistente. Aquí, o pasa todo o no pasa nada.
 *
 * El comando `gestisp:empresas-migrar` existe aparte para INSPECCIONAR
 * y para reparar, no para completar esta migración.
 *
 * Se usan consultas directas y no modelos de Eloquent: una migración
 * tiene que seguir funcionando dentro de dos años, cuando los modelos
 * hayan cambiado.
 *
 * LAS DOS RESTRICCIONES QUE SE QUITAN
 * -----------------------------------
 * `branches.name` y `plans.name` eran ÚNICAS a nivel global. Eso
 * impedía físicamente que dos empresas tuvieran cada una su "Sucursal
 * Principal" o su plan "100 Megas". Pasan a ser únicas dentro de su
 * ámbito:
 *
 *   branches.name → único por EMPRESA
 *   plans.name    → único por SUCURSAL (el plan es un empaquetado
 *                   comercial de la sede; el servicio, que sí es
 *                   fiscal, subirá a la empresa más adelante)
 *
 * REVERSIBLE, PERO NO DEL TODO
 * ----------------------------
 * down() deshace el esquema, pero las empresas creadas aquí se
 * borran: si alguien ya editó la razón social o añadió una empresa a
 * mano, ese trabajo se pierde. No es una migración para ir y volver
 * en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->restrictOnDelete();
        });

        $this->crearEmpresasDesdeLasSucursales();

        // Una sucursal SIEMPRE pertenece a una empresa. Se decidió
        // que no existan empresas sin sucursales ni sucursales
        // huérfanas: un company_id opcional obligaría a preguntar
        // "¿y si no tiene?" en cada consulta del sistema.
        //
        // Va en SQL directo y no con ->change() porque este proyecto no
        // tiene doctrine/dbal, que es lo que Laravel 10 necesita para
        // alterar una columna existente. Es el mismo motivo por el que
        // la migración de pon_ports lo hizo así.
        DB::statement('ALTER TABLE branches MODIFY company_id BIGINT UNSIGNED NOT NULL');

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_name_unique');
            $table->unique(['company_id', 'name'], 'branches_company_name_unique');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropUnique('plans_name_unique');
            $table->unique(['branch_id', 'name'], 'plans_branch_name_unique');
        });
    }

    /**
     * Una empresa por cada NIT distinto que haya en las sucursales.
     *
     * Hoy el NIT vive en la sucursal, así que es el único dato que
     * permite deducir qué sucursales son de la misma empresa. Las que
     * comparten NIT son la misma empresa; las que no, empresas
     * distintas.
     *
     * La razón social se toma del nombre de la primera sucursal del
     * grupo. Es solo un punto de partida legible —"Blutv Yarumal" no
     * es una razón social— y se corrige desde la pantalla de empresas.
     */
    private function crearEmpresasDesdeLasSucursales(): void
    {
        $grupos = DB::table('branches')
            ->select('nit')
            ->selectRaw('MIN(id) as primera')
            ->groupBy('nit')
            ->get();

        foreach ($grupos as $grupo) {
            $primera = DB::table('branches')->find($grupo->primera);

            $companyId = DB::table('companies')->insertGetId([
                'legal_name' => $primera->name,
                'document_type_code' => '31',
                // Una sucursal sin NIT no puede quedarse sin empresa:
                // se le pone un marcador visible para que salte a la
                // vista en la pantalla de empresas y se corrija.
                'document_number' => $grupo->nit ?: 'SIN-NIT-' . $grupo->primera,
                'address' => $primera->address,
                'phone' => $primera->number_phone,
                'operation_mode' => 'independent',
                'electronic_invoicing_enabled' => false,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('branches')
                ->where(fn ($q) => $grupo->nit === null
                    ? $q->whereNull('nit')
                    : $q->where('nit', $grupo->nit))
                ->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropUnique('plans_branch_name_unique');
            $table->unique('name', 'plans_name_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_company_name_unique');
            $table->unique('name', 'branches_name_unique');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });

        DB::table('companies')->delete();
    }
};
