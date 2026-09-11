<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La unidad de medida pasa a ser del MATERIAL.
 *
 * EL PROBLEMA
 * -----------
 * Se pedía en cada operación: en cada movimiento y en cada orden
 * técnica había que volver a decir que la fibra se mide en metros. Eso
 * es preguntar por algo que no cambia, y tiene dos costes:
 *
 * · Es un dato que se puede contestar MAL. Nada impedía ingresar 200
 *   «Unidades» de fibra en un almacén y sacar 50 «Metros» de la misma
 *   fibra en otro; el sistema los sumaba igual y las existencias
 *   quedaban en una unidad que no significaba nada.
 * · En las órdenes técnicas era trámite puro: se preguntaba y ni
 *   siquiera se guardaba (`technical_order_materials` no tiene la
 *   columna).
 *
 * La unidad es una propiedad del material, como su nombre o si lleva
 * serial. Se declara una vez, al crearlo, y el resto del sistema la
 * asume.
 *
 * LO QUE NO SE TOCA
 * -----------------
 * `inventories.unit_of_measurement` y `material_movements.unit_of_measurement`
 * se quedan. NO son duplicados ociosos: son la foto de con qué unidad
 * se registró aquel movimiento. Si mañana alguien corrige la unidad de
 * un material, el histórico debe seguir diciendo lo que se hizo
 * entonces, no lo que diríamos hoy.
 *
 * EL REPARTO DE LO EXISTENTE
 * --------------------------
 * Los materiales ya creados no tienen unidad, y ponerles a todos
 * «Unidades» dejaría la fibra medida en unidades. Se deduce, en este
 * orden: la unidad más usada en sus existencias → la más usada en sus
 * movimientos → «Unidades» como último recurso. Es el mismo criterio
 * con el que se repartió `branch_id` cuando el catálogo dejó de ser
 * global.
 */
return new class extends Migration
{
    private const POR_DEFECTO = 'Unidades';

    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('unit_of_measurement', 30)
                ->nullable()
                ->after('is_equipment');
        });

        $this->repartirLoExistente();

        // NOT NULL solo DESPUÉS del reparto, y con SQL crudo: este
        // proyecto es Laravel 10 sin doctrine/dbal, donde `->change()`
        // lanza excepción. La columna deja de admitir nulos porque un
        // material sin unidad es un material del que no se sabe qué
        // significan sus existencias.
        DB::statement("ALTER TABLE materials MODIFY unit_of_measurement VARCHAR(30) NOT NULL DEFAULT '" . self::POR_DEFECTO . "'");
    }

    /**
     * Le pone a cada material la unidad con la que ya se estaba
     * trabajando, no una inventada.
     */
    private function repartirLoExistente(): void
    {
        $materiales = DB::table('materials')->pluck('id');

        foreach ($materiales as $materialId) {
            $unidad = $this->masUsada('inventories', $materialId)
                ?? $this->masUsada('material_movements', $materialId)
                ?? self::POR_DEFECTO;

            DB::table('materials')
                ->where('id', $materialId)
                ->update(['unit_of_measurement' => $unidad]);
        }
    }

    /** La unidad con la que más veces aparece el material en una tabla. */
    private function masUsada(string $tabla, int $materialId): ?string
    {
        return DB::table($tabla)
            ->where('material_id', $materialId)
            ->whereNotNull('unit_of_measurement')
            ->where('unit_of_measurement', '<>', '')
            ->select('unit_of_measurement', DB::raw('COUNT(*) as veces'))
            ->groupBy('unit_of_measurement')
            ->orderByDesc('veces')
            ->value('unit_of_measurement');
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('unit_of_measurement');
        });
    }
};
