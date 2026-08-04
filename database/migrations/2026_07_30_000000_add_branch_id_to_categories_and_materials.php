<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materiales y categorías por sucursal.
 *
 * EL PROBLEMA
 * -----------
 * `categories` y `materials` nacieron sin `branch_id`, así que el
 * catálogo era global: una sucursal creaba un material y aparecía en
 * todas. En un ISP con varias sedes eso es un error de fondo —cada
 * bodega maneja su propio catálogo— y además ensucia los selectores
 * con materiales que en esa sucursal no existen.
 *
 * CÓMO SE REPARTEN LOS DATOS QUE YA EXISTEN
 * -----------------------------------------
 * No se puede asignar todo a una sucursal cualquiera: el inventario
 * ya está repartido y dejaría a las demás sin ver materiales que sí
 * están usando. Se deduce la sucursal de cada fila siguiendo el
 * rastro que dejó su uso, en este orden:
 *
 *   MATERIAL   1. la sucursal de los almacenes donde tiene inventario
 *              2. la de los almacenes de sus movimientos
 *              3. la sucursal más antigua (nunca se usó: da igual)
 *
 *   CATEGORÍA  1. la sucursal de sus materiales (ya resueltos arriba)
 *              2. la sucursal más antigua
 *
 * Cuando algo aparece en VARIAS sucursales se toma aquella donde más
 * se usa y se avisa por consola: es un caso que un humano debe
 * revisar, porque significa que dos sedes compartían la misma ficha.
 *
 * La columna queda NOT NULL al final: un material sin sucursal
 * volvería a ser global y reaparecería el problema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained();
        });

        $this->repartirMateriales();
        $this->repartirCategorias();

        // Sin sucursales no hay nada que repartir y tampoco se puede
        // exigir la columna (instalación desde cero).
        //
        // Se usa SQL directo y no ->change() porque este proyecto es
        // Laravel 10 sin doctrine/dbal, donde ->change() lanza una
        // excepción. El índice de branch_id ya lo crea constrained().
        if (DB::table('branches')->exists()) {
            DB::statement('ALTER TABLE categories MODIFY branch_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE materials MODIFY branch_id BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    /**
     * Cada material a la sucursal donde de verdad se usa.
     */
    private function repartirMateriales(): void
    {
        $porDefecto = $this->sucursalPorDefecto();

        if (!$porDefecto) {
            return;
        }

        foreach (DB::table('materials')->pluck('id') as $materialId) {
            $sucursales = $this->sucursalesDeInventario($materialId)
                ?: $this->sucursalesDeMovimientos($materialId);

            $branchId = $this->elegirSucursal($sucursales, $porDefecto, "material {$materialId}");

            DB::table('materials')->where('id', $materialId)->update(['branch_id' => $branchId]);
        }
    }

    /**
     * Cada categoría a la sucursal de sus materiales.
     */
    private function repartirCategorias(): void
    {
        $porDefecto = $this->sucursalPorDefecto();

        if (!$porDefecto) {
            return;
        }

        foreach (DB::table('categories')->pluck('id') as $categoriaId) {
            $sucursales = DB::table('materials')
                ->where('category_id', $categoriaId)
                ->whereNotNull('branch_id')
                ->selectRaw('branch_id, COUNT(*) as usos')
                ->groupBy('branch_id')
                ->orderByDesc('usos')
                ->pluck('usos', 'branch_id')
                ->all();

            $branchId = $this->elegirSucursal($sucursales, $porDefecto, "categoría {$categoriaId}");

            DB::table('categories')->where('id', $categoriaId)->update(['branch_id' => $branchId]);
        }
    }

    /**
     * Sucursales donde el material tiene existencias, con cuántas
     * filas de inventario en cada una.
     *
     * @return array<int, int> branch_id => usos
     */
    private function sucursalesDeInventario(int $materialId): array
    {
        return DB::table('inventories')
            ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
            ->where('inventories.material_id', $materialId)
            ->selectRaw('warehouses.branch_id, COUNT(*) as usos')
            ->groupBy('warehouses.branch_id')
            ->orderByDesc('usos')
            ->pluck('usos', 'warehouses.branch_id')
            ->all();
    }

    /**
     * Sucursales que aparecen en los movimientos del material, por si
     * ya no le queda inventario pero sí historia.
     *
     * @return array<int, int> branch_id => usos
     */
    private function sucursalesDeMovimientos(int $materialId): array
    {
        $conteo = [];

        foreach (['warehouse_origin_id', 'warehouse_destination_id'] as $columna) {
            $filas = DB::table('material_movements')
                ->join('warehouses', 'warehouses.id', '=', 'material_movements.' . $columna)
                ->where('material_movements.material_id', $materialId)
                ->selectRaw('warehouses.branch_id, COUNT(*) as usos')
                ->groupBy('warehouses.branch_id')
                ->pluck('usos', 'warehouses.branch_id')
                ->all();

            foreach ($filas as $branchId => $usos) {
                $conteo[$branchId] = ($conteo[$branchId] ?? 0) + $usos;
            }
        }

        arsort($conteo);

        return $conteo;
    }

    /**
     * Decide la sucursal y avisa cuando la elección no era obvia.
     *
     * @param  array<int, int>  $sucursales  branch_id => usos, de mayor a menor
     */
    private function elegirSucursal(array $sucursales, int $porDefecto, string $que): int
    {
        if (empty($sucursales)) {
            return $porDefecto;
        }

        $elegida = (int) array_key_first($sucursales);

        if (count($sucursales) > 1) {
            // Dos sedes compartían la misma ficha: alguien tiene que
            // revisarlo, así que no puede pasar en silencio.
            echo sprintf(
                "  [!] El %s se usaba en %d sucursales (%s). Se asignó a la %d, donde más se usa. Revíselo.\n",
                $que,
                count($sucursales),
                implode(', ', array_keys($sucursales)),
                $elegida,
            );
        }

        return $elegida;
    }

    /** La sucursal más antigua: solo se usa si no hay rastro alguno. */
    private function sucursalPorDefecto(): ?int
    {
        $id = DB::table('branches')->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }
};
