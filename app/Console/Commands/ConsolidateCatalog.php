<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sube al ámbito de la empresa los planes y servicios repetidos por sede.
 *
 *     php artisan gestisp:catalogo-consolidar --dry-run
 *     php artisan gestisp:catalogo-consolidar --aplicar
 *
 * QUÉ HACE
 * --------
 * Agrupa por `(empresa, nombre)`. Cuando el mismo nombre existe en
 * varias sucursales y **todas las copias son idénticas**, deja una sola
 * con `branch_id = NULL` —disponible en toda la empresa— y repunta a
 * ella lo que apuntaba a las demás.
 *
 * LO QUE NO HACE, Y ES LO MÁS IMPORTANTE
 * --------------------------------------
 * **No fusiona nada cuyos datos difieran.** Si dos servicios con el
 * mismo nombre tienen distinto precio, distinta tarifa o distinta
 * clasificación fiscal, se informan y se dejan intactos.
 *
 * No es prudencia de más: fusionar dos servicios con IVA distinto
 * cambia lo que se le factura a un cliente el mes siguiente, y en
 * electrónica cambia el XML que se le presenta a la DIAN. Eso lo decide
 * una persona mirando, no un comando adivinando.
 *
 * **Tampoco toca `invoice_items`.** Los renglones de una factura
 * emitida son copias congeladas —llevan su propio precio, su código de
 * producto y su clasificación fiscal— y no apuntan al servicio.
 * Consolidar el catálogo no puede alterar nada ya facturado; hay una
 * prueba que lo fija.
 *
 * POR QUÉ NO ES UNA MIGRACIÓN
 * ---------------------------
 * Porque no es un cambio de esquema sino una decisión sobre datos, y
 * se quiere poder mirarla antes, ejecutarla cuando convenga y repetirla
 * si aparecen duplicados nuevos. Una migración que fusionara catálogos
 * cambiaría facturación sin que nadie lo hubiera revisado.
 */
class ConsolidateCatalog extends Command
{
    protected $signature = 'gestisp:catalogo-consolidar
                            {--empresa= : Solo esta empresa}
                            {--aplicar : Escribe los cambios. Sin esta opción solo informa}';

    protected $description = 'Sube a la empresa los planes y servicios que están repetidos en varias sedes';

    /** Lo que tiene que coincidir para que dos servicios sean el mismo. */
    private const CAMPOS_DEL_SERVICIO = [
        'base_price', 'tax_percentage', 'tax_classification',
        'product_code', 'product_code_type', 'unit_measure_code', 'tax_code',
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $this->info($aplicar
            ? 'Modo APLICAR: se escribirán los cambios.'
            : 'Modo revisión: no se escribe nada. Use --aplicar para ejecutar.');

        $empresas = Company::withoutGlobalScopes()
            ->when($this->option('empresa'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('legal_name')
            ->get();

        if ($empresas->isEmpty()) {
            $this->error('No hay ninguna empresa.');

            return self::FAILURE;
        }

        $huboAlgo = false;

        foreach ($empresas as $empresa) {
            $this->newLine();
            $this->line('<options=bold>' . $empresa->nombreVisible() . '</>');

            $huboAlgo = $this->consolidarServicios($empresa, $aplicar) || $huboAlgo;
            $huboAlgo = $this->consolidarPlanes($empresa, $aplicar) || $huboAlgo;
        }

        if (!$huboAlgo) {
            $this->newLine();
            $this->info('No hay nada repetido: el catálogo ya está consolidado.');

            return self::SUCCESS;
        }

        if (!$aplicar) {
            $this->newLine();
            $this->warn('No se escribió nada. Repita con --aplicar cuando lo anterior sea correcto.');
        }

        return self::SUCCESS;
    }

    // ==================== Servicios ====================

    private function consolidarServicios(Company $empresa, bool $aplicar): bool
    {
        $grupos = DB::table('services')
            ->where('company_id', $empresa->id)
            ->whereNotNull('branch_id')
            ->select('name')
            ->selectRaw('COUNT(*) n')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('n', 'name');

        if ($grupos->isEmpty()) {
            return false;
        }

        $this->line('  Servicios repetidos en varias sedes:');

        $filas = [];
        $fusionables = [];

        foreach ($grupos as $nombre => $cuantos) {
            $copias = DB::table('services')
                ->where('company_id', $empresa->id)
                ->where('name', $nombre)
                ->whereNotNull('branch_id')
                ->get();

            $diferencias = $this->diferenciasEntre($copias, self::CAMPOS_DEL_SERVICIO);

            $filas[] = [
                $nombre,
                $cuantos,
                $diferencias === [] ? 'idénticas' : 'DIFIEREN: ' . implode(', ', $diferencias),
                $diferencias === [] ? 'se fusiona' : 'se deja — decidir a mano',
            ];

            if ($diferencias === []) {
                $fusionables[$nombre] = $copias;
            }
        }

        $this->table(['Servicio', 'Copias', 'Comparación', 'Qué pasa'], $filas);

        if (!$aplicar || $fusionables === []) {
            return true;
        }

        foreach ($fusionables as $nombre => $copias) {
            $superviviente = $copias->first();
            $resto = $copias->skip(1)->pluck('id');

            DB::transaction(function () use ($superviviente, $resto) {
                // El superviviente pasa a ser de la empresa.
                DB::table('services')->where('id', $superviviente->id)->update(['branch_id' => null]);

                // Lo que apuntaba a las otras copias apunta ahora aquí.
                // El pivote puede quedar con duplicados (un plan que
                // tenía dos copias del mismo servicio), así que se
                // repunta y después se limpian los repetidos.
                DB::table('plan_service')->whereIn('service_id', $resto)
                    ->update(['service_id' => $superviviente->id]);

                $this->quitarDuplicadosDelPivote();

                DB::table('services')->whereIn('id', $resto)->delete();
            });

            $this->line(sprintf('    «%s»: %d copias → 1, de la empresa.', $nombre, $copias->count()));
        }

        return true;
    }

    // ==================== Planes ====================

    private function consolidarPlanes(Company $empresa, bool $aplicar): bool
    {
        $grupos = DB::table('plans')
            ->where('company_id', $empresa->id)
            ->whereNotNull('branch_id')
            ->select('name')
            ->selectRaw('COUNT(*) n')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('n', 'name');

        if ($grupos->isEmpty()) {
            return false;
        }

        $this->line('  Planes repetidos en varias sedes:');

        $filas = [];
        $fusionables = [];

        foreach ($grupos as $nombre => $cuantos) {
            $copias = DB::table('plans')
                ->where('company_id', $empresa->id)
                ->where('name', $nombre)
                ->whereNotNull('branch_id')
                ->get();

            // Dos planes son el mismo si llevan EXACTAMENTE los mismos
            // servicios. El nombre no basta: «Plan 100M» puede incluir
            // el router en una sede y no en otra, y fusionarlos
            // cambiaría lo que se factura.
            $porServicios = $copias->groupBy(fn ($plan) => $this->huellaDeServicios($plan->id));

            $mismo = $porServicios->count() === 1;

            $filas[] = [
                $nombre,
                $cuantos,
                $mismo ? 'mismos servicios' : 'DIFIEREN en sus servicios',
                $mismo ? 'se fusiona' : 'se deja — decidir a mano',
            ];

            if ($mismo) {
                $fusionables[$nombre] = $copias;
            }
        }

        $this->table(['Plan', 'Copias', 'Comparación', 'Qué pasa'], $filas);

        if (!$aplicar || $fusionables === []) {
            return true;
        }

        foreach ($fusionables as $nombre => $copias) {
            $superviviente = $copias->first();
            $resto = $copias->skip(1)->pluck('id');

            // Un plan con contratos NO se puede borrar —la clave
            // foránea de la fase 13 lo impide— así que primero se
            // repuntan los contratos al superviviente.
            DB::transaction(function () use ($superviviente, $resto) {
                DB::table('plans')->where('id', $superviviente->id)->update(['branch_id' => null]);

                DB::table('contracts')->whereIn('plan_id', $resto)
                    ->update(['plan_id' => $superviviente->id]);

                DB::table('plan_service')->whereIn('plan_id', $resto)->delete();
                DB::table('plans')->whereIn('id', $resto)->delete();
            });

            $this->line(sprintf('    «%s»: %d copias → 1, de la empresa.', $nombre, $copias->count()));
        }

        return true;
    }

    // ==================== Apoyo ====================

    /**
     * Qué campos NO coinciden entre las copias.
     *
     * @param  \Illuminate\Support\Collection  $copias
     * @param  array<int, string>  $campos
     * @return array<int, string>
     */
    private function diferenciasEntre($copias, array $campos): array
    {
        $distintos = [];

        foreach ($campos as $campo) {
            if ($copias->pluck($campo)->unique()->count() > 1) {
                $distintos[] = $campo;
            }
        }

        return $distintos;
    }

    /** Los servicios de un plan, en un texto comparable. */
    private function huellaDeServicios(int $planId): string
    {
        return DB::table('plan_service')
            ->where('plan_id', $planId)
            ->orderBy('service_id')
            ->pluck('service_id')
            ->implode(',');
    }

    /**
     * Deja una sola fila por par plan-servicio.
     *
     * Hace falta después de repuntar: si un plan tenía dos copias del
     * mismo servicio, al unificarlas quedan dos filas iguales.
     */
    private function quitarDuplicadosDelPivote(): void
    {
        DB::statement(
            'DELETE p1 FROM plan_service p1
             INNER JOIN plan_service p2
             WHERE p1.id > p2.id
               AND p1.plan_id = p2.plan_id
               AND p1.service_id = p2.service_id'
        );
    }
}
