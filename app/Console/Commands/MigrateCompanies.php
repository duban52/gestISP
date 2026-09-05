<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspecciona y repara el enlace entre sucursales y empresas.
 *
 * PARA QUÉ SIRVE
 * --------------
 * La migración que creó las empresas ya dejó todo enlazado, así que
 * este comando NO completa nada: sirve para MIRAR qué quedó y para
 * arreglar los casos que aparezcan después —una sucursal creada por
 * una importación que se saltó los eventos del modelo, o un NIT
 * corregido a mano que dejó a dos empresas donde debería haber una.
 *
 * POR DEFECTO NO ESCRIBE NADA
 * ---------------------------
 * Se ejecuta en seco. Para que escriba hay que pedirlo con --aplicar.
 * Es deliberado: agrupar sucursales por NIT es una decisión que afecta
 * a qué datos comparte quién, y conviene verla antes de que ocurra.
 */
class MigrateCompanies extends Command
{
    protected $signature = 'gestisp:empresas-migrar
                            {--aplicar : Escribe los cambios. Sin esta opción solo informa}';

    protected $description = 'Revisa el enlace entre sucursales y empresas, y lo repara si hace falta';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $this->info($aplicar
            ? 'Modo APLICAR: se escribirán los cambios.'
            : 'Modo revisión: no se escribe nada. Use --aplicar para ejecutar.');
        $this->newLine();

        $this->estadoActual();

        $huerfanas = Branch::whereNull('company_id')->get();
        $duplicadas = $this->empresasConNitRepetido();
        $filasSueltas = $this->filasSinEmpresa();
        $desalineados = $this->clientesDesalineados();

        if ($huerfanas->isEmpty() && $duplicadas->isEmpty() && $filasSueltas === [] && $desalineados->isEmpty()) {
            $this->newLine();
            $this->info('Nada que reparar.');

            return self::SUCCESS;
        }

        $this->repararHuerfanas($huerfanas, $aplicar);
        $this->repararFilasSueltas($filasSueltas, $aplicar);
        $this->repararDesalineados($desalineados, $aplicar);
        $this->avisarDuplicadas($duplicadas);

        if (!$aplicar) {
            $this->newLine();
            $this->warn('No se escribió nada. Repita con --aplicar cuando lo anterior sea correcto.');
        }

        return self::SUCCESS;
    }

    /**
     * Qué hay ahora mismo: empresas, sus sucursales y sus NIT.
     */
    private function estadoActual(): void
    {
        $empresas = Company::withCount('branches')->orderBy('legal_name')->get();

        if ($empresas->isEmpty()) {
            $this->warn('No hay ninguna empresa registrada.');

            return;
        }

        $this->table(
            ['#', 'Razón social', 'NIT', 'Modo', 'F. electrónica', 'Sucursales'],
            $empresas->map(fn (Company $e) => [
                $e->id,
                $e->legal_name,
                $e->identificacion(),
                $e->esConsolidada() ? 'consolidado' : 'independiente',
                $e->electronic_invoicing_enabled ? 'sí' : 'no',
                $e->branches_count,
            ])->all(),
        );

        // Una empresa sin sucursales no debería existir: se decidió que
        // toda empresa tiene al menos una. Si aparece alguna, es que
        // quedó suelta al borrar sucursales.
        $sinSucursales = $empresas->where('branches_count', 0);

        if ($sinSucursales->isNotEmpty()) {
            $this->warn(sprintf(
                '%d empresa(s) sin ninguna sucursal: %s',
                $sinSucursales->count(),
                $sinSucursales->pluck('legal_name')->implode(', '),
            ));
        }

        $marcadores = $empresas->filter(
            fn (Company $e) => str_starts_with((string) $e->document_number, 'SIN-NIT-'),
        );

        if ($marcadores->isNotEmpty()) {
            $this->warn(sprintf(
                '%d empresa(s) se crearon desde una sucursal SIN NIT y llevan un número '
                . 'de marcador. Corríjalas antes de facturar: %s',
                $marcadores->count(),
                $marcadores->pluck('document_number')->implode(', '),
            ));
        }
    }

    /**
     * Sucursales que quedaron sin empresa.
     */
    private function repararHuerfanas($huerfanas, bool $aplicar): void
    {
        if ($huerfanas->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn($huerfanas->count() . ' sucursal(es) sin empresa:');

        foreach ($huerfanas as $sucursal) {
            $existente = Company::where('document_number', $sucursal->nit)->first();

            $destino = $existente
                ? "se une a la empresa existente «{$existente->legal_name}»"
                : 'se crea una empresa nueva';

            $this->line("  · {$sucursal->name} (NIT {$sucursal->nit}) → {$destino}");

            if (!$aplicar) {
                continue;
            }

            DB::transaction(function () use ($sucursal, $existente) {
                $empresa = $existente ?? Company::create([
                    'legal_name' => $sucursal->name,
                    'document_type_code' => '31',
                    'document_number' => $sucursal->nit ?: 'SIN-NIT-' . $sucursal->id,
                    'address' => $sucursal->address,
                    'phone' => $sucursal->number_phone,
                    'operation_mode' => Company::MODO_INDEPENDIENTE,
                    'electronic_invoicing_enabled' => false,
                    'active' => true,
                ]);

                $sucursal->update(['company_id' => $empresa->id]);
            });
        }
    }

    /**
     * Empresas distintas que comparten NIT.
     *
     * No se arreglan solas: fundir dos empresas mueve sucursales,
     * clientes y documentos de sitio, y eso no lo puede decidir un
     * comando. Se informa para que alguien lo resuelva a mano.
     */
    /**
     * Tablas que cuelgan de la sucursal y llevan `company_id`.
     *
     * Es la misma lista de la migración que añadió la columna. Se
     * repite aquí a propósito: una migración es un hecho del pasado y
     * no se toca; esto es una herramienta de reparación que se ejecuta
     * hoy. Atarlas obligaría a leer una migración vieja para saber qué
     * repara el comando.
     */
    private const TABLAS_CON_EMPRESA = [
        'clients', 'contracts', 'invoices', 'plans', 'services',
        'warehouses', 'materials', 'categories', 'cash_registers',
        'technical_orders', 'onts', 'olts', 'pppoe_accounts', 'routers',
        'optical_networks', 'account_credits', 'billing_runs',
        'credit_debit_notes', 'payment_batches', 'payment_retentions',
    ];

    /**
     * Filas que perdieron su empresa.
     *
     * DE DÓNDE SALEN, QUE NO ES OBVIO
     * -------------------------------
     * La migración rellenó `company_id` desde `branches.company_id`
     * haciendo un JOIN por `branch_id`. Las filas **sin** `branch_id`
     * no tenían de dónde deducirla y se quedaron en null: son las
     * creadas en modo consolidado, sobre todo clientes.
     *
     * POR QUÉ NO ES UN DETALLE
     * ------------------------
     * `BelongsToCompany` filtra por `company_id = X`, y eso **excluye
     * las nulas**. La fila existe en la base y desaparece de la
     * aplicación. Peor: una consulta que haga JOIN en SQL crudo sí la
     * encuentra, así que la fila aparece en un listado y su relación
     * devuelve null — que es exactamente como reventaron la ficha del
     * contrato y el listado de facturas.
     *
     * @return array<string, int>
     */
    private function filasSinEmpresa(): array
    {
        $conteo = [];

        foreach (self::TABLAS_CON_EMPRESA as $tabla) {
            if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, 'company_id')) {
                continue;
            }

            $n = DB::table($tabla)->whereNull('company_id')->count();

            if ($n > 0) {
                $conteo[$tabla] = $n;
            }
        }

        return $conteo;
    }

    /**
     * Les devuelve su empresa.
     *
     * Por dos caminos, en este orden:
     *
     *   1. Por su `branch_id`, cuando lo tienen. Es directo y seguro.
     *   2. Solo para `clients`: por la sucursal de sus CONTRATOS. Un
     *      cliente creado en consolidado no tiene sucursal propia, pero
     *      sus contratos sí, y ahí es donde de verdad se le atiende.
     *
     * Lo que no se pueda deducir se informa y se deja quieto. Adivinar
     * la empresa de un dato fiscal es peor que dejarlo señalado.
     *
     * @param  array<string, int>  $filas
     */
    private function repararFilasSueltas(array $filas, bool $aplicar): void
    {
        if ($filas === []) {
            return;
        }

        $this->newLine();
        $this->warn('Filas sin empresa (invisibles para la aplicación):');
        $this->table(
            ['Tabla', 'Filas'],
            collect($filas)->map(fn ($n, $t) => [$t, $n])->values(),
        );

        if (!$aplicar) {
            return;
        }

        foreach (array_keys($filas) as $tabla) {
            // 1. Por su propia sucursal.
            $porSucursal = DB::update("
                UPDATE {$tabla} t
                JOIN branches b ON b.id = t.branch_id
                SET t.company_id = b.company_id
                WHERE t.company_id IS NULL AND b.company_id IS NOT NULL
            ");

            $porContrato = 0;

            // 2. Los clientes, por la sucursal de sus contratos.
            if ($tabla === 'clients') {
                $porContrato = DB::update("
                    UPDATE clients c
                    JOIN (
                        SELECT ct.client_id, MIN(b.company_id) AS company_id
                        FROM contracts ct
                        JOIN branches b ON b.id = ct.branch_id
                        WHERE b.company_id IS NOT NULL
                        GROUP BY ct.client_id
                    ) x ON x.client_id = c.id
                    SET c.company_id = x.company_id
                    WHERE c.company_id IS NULL
                ");
            }

            $quedan = DB::table($tabla)->whereNull('company_id')->count();

            $this->line(sprintf(
                '  %-22s reparadas: %d%s   quedan: %d',
                $tabla,
                $porSucursal + $porContrato,
                $porContrato ? " (de ellas {$porContrato} por sus contratos)" : '',
                $quedan,
            ));

            if ($quedan > 0) {
                $this->warn(sprintf(
                    '    %d fila(s) de %s no tienen sucursal ni contrato: hay que asignarlas a mano.',
                    $quedan,
                    $tabla,
                ));
            }
        }
    }

    /**
     * Clientes que están en una empresa distinta de la de sus contratos.
     *
     * PEOR QUE UN HUÉRFANO, Y MÁS DIFÍCIL DE VER
     * ------------------------------------------
     * Un cliente sin empresa al menos no aparece en ningún sitio. Este
     * sí aparece —en SU empresa— pero desaparece desde el contrato, que
     * está en la sucursal de OTRA. `BelongsToCompany` filtra por la
     * empresa del contexto, así que al abrir la ficha del contrato la
     * relación devuelve null y la pantalla se cae.
     *
     * Es como quedaron los clientes cuya `branch_id` apuntaba a una
     * sede distinta de la de sus contratos: la migración dedujo su
     * empresa de esa columna, no de dónde se le presta el servicio.
     *
     * SOLO SE REPARA LO QUE NO ES AMBIGUO
     * -----------------------------------
     * Si TODOS los contratos del cliente están en la misma empresa, esa
     * es su empresa y no hay nada que decidir. Si los tiene repartidos
     * entre varias, no se toca: mover un cliente entre contribuyentes
     * es una decisión con consecuencias fiscales y la tiene que tomar
     * una persona.
     */
    private function clientesDesalineados()
    {
        // OJO CON EL FILTRO: el desalineamiento NO puede ir en el
        // WHERE. Poniéndolo ahí se descartan los contratos del cliente
        // que SÍ coinciden con su empresa, y entonces un cliente
        // repartido entre dos empresas parece tener una sola —se le
        // trataría como caso claro y se le movería—. Se agrupa por
        // TODOS sus contratos y se decide en el HAVING.
        return DB::table('clients as c')
            ->join('contracts as ct', 'ct.client_id', '=', 'c.id')
            ->join('branches as b', 'b.id', '=', 'ct.branch_id')
            ->whereNotNull('c.company_id')
            ->whereNotNull('b.company_id')
            ->groupBy('c.id', 'c.company_id')
            ->select('c.id', 'c.company_id as empresa_del_cliente')
            ->selectRaw('COUNT(DISTINCT b.company_id) as empresas_de_sus_contratos')
            ->selectRaw('MIN(b.company_id) as empresa_de_sus_contratos')
            // Sobra solo lo que NO cuadra: o tiene contratos en varias
            // empresas, o en una sola que no es la suya.
            ->havingRaw('COUNT(DISTINCT b.company_id) > 1 OR MIN(b.company_id) <> c.company_id')
            ->get();
    }

    /** @param  \Illuminate\Support\Collection  $desalineados */
    private function repararDesalineados($desalineados, bool $aplicar): void
    {
        if ($desalineados->isEmpty()) {
            return;
        }

        [$claros, $ambiguos] = $desalineados->partition(
            fn ($fila) => (int) $fila->empresas_de_sus_contratos === 1,
        );

        $this->newLine();
        $this->warn('Clientes en una empresa distinta de la de sus contratos:');
        $this->line('  Desde la ficha del contrato NO se ven, y la pantalla se cae al pintarlos.');
        $this->table(
            ['Cliente', 'Su empresa', 'Empresa de sus contratos', '¿Claro?'],
            $desalineados->map(fn ($f) => [
                $f->id,
                $f->empresa_del_cliente,
                (int) $f->empresas_de_sus_contratos === 1 ? $f->empresa_de_sus_contratos : 'varias',
                (int) $f->empresas_de_sus_contratos === 1 ? 'sí' : 'NO — decidir a mano',
            ]),
        );

        if ($ambiguos->isNotEmpty()) {
            $this->warn(sprintf(
                '  %d cliente(s) tienen contratos en VARIAS empresas: no se tocan. '
                . 'Mover un cliente entre contribuyentes es una decisión fiscal.',
                $ambiguos->count(),
            ));
        }

        if (!$aplicar || $claros->isEmpty()) {
            return;
        }

        foreach ($claros as $fila) {
            DB::table('clients')
                ->where('id', $fila->id)
                ->update(['company_id' => $fila->empresa_de_sus_contratos]);
        }

        $this->info(sprintf('  Reasignados %d cliente(s) a la empresa de sus contratos.', $claros->count()));
    }

    private function avisarDuplicadas($duplicadas): void
    {
        if ($duplicadas->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->error('Hay empresas distintas con el mismo NIT. Esto hay que resolverlo a mano:');

        foreach ($duplicadas as $nit => $empresas) {
            $this->line("  · NIT {$nit}: " . $empresas->pluck('legal_name')->implode(' | '));
        }
    }

    private function empresasConNitRepetido()
    {
        return Company::all()
            ->groupBy('document_number')
            ->filter(fn ($grupo) => $grupo->count() > 1);
    }
}
