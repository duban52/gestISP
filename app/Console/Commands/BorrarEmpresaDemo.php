<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quita una empresa de prueba y todo lo que colgaba de ella.
 *
 * ES LA MITAD QUE IMPORTA. Sembrar datos de prueba en producción solo
 * es aceptable si se pueden quitar: sin esto, la empresa de mentira se
 * queda para siempre ensuciando selectores, informes consolidados y
 * copias de seguridad, y al cabo de unos meses nadie se atreve a
 * borrarla porque ya no sabe qué arrastra.
 *
 * SOLO BORRA EMPRESAS MARCADAS
 * ----------------------------
 * Se niega a tocar una empresa cuyo nombre no lleve la marca DEMO.
 * Es un comando de borrado masivo ejecutado a mano en un servidor de
 * producción con un cliente real dentro: la protección no sobra, y el
 * día que alguien se equivoque de id querrá que estuviera.
 *
 *   php artisan demo:borrar --empresa=7
 */
class BorrarEmpresaDemo extends Command
{
    protected $signature = 'demo:borrar
                            {--empresa= : Id de la empresa de prueba}
                            {--force : No preguntar}';

    protected $description = 'Borra una empresa de prueba creada con demo:sembrar y todos sus datos';

    /**
     * El orden importa: de lo que cuelga hacia lo que sostiene. Al
     * revés, las claves foráneas rechazan el borrado.
     *
     * `payments` y los movimientos de caja van antes que `invoices`,
     * e `invoices` antes que `contracts`.
     */
    private const POR_CONTRATO = [
        'payments',
        'invoice_items',
        'invoices',
        'technical_orders',
        'contracts',
    ];

    public function handle(): int
    {
        $id = (int) $this->option('empresa');

        if (!$id) {
            $this->error('Falta --empresa=<id>. Use «demo:sembrar» para saber cuál es.');

            return self::FAILURE;
        }

        $empresa = Company::withoutGlobalScopes()->find($id);

        if (!$empresa) {
            $this->error("No existe ninguna empresa con id {$id}.");

            return self::FAILURE;
        }

        if (!str_contains(mb_strtoupper($empresa->legal_name), SembrarEmpresaDemo::MARCA)) {
            $this->error("«{$empresa->legal_name}» no está marcada como " . SembrarEmpresaDemo::MARCA . '.');
            $this->line('Este comando solo borra empresas de prueba. Si de verdad quiere');
            $this->line('eliminar una empresa real, hágalo desde el panel y con cuidado.');

            return self::FAILURE;
        }

        $resumen = $this->contar($id);

        $this->newLine();
        $this->line("Se va a borrar «{$empresa->legal_name}» (id {$id}) y:");

        foreach ($resumen as $tabla => $cuantos) {
            $this->line(sprintf('  · %-20s %d', $tabla, $cuantos));
        }

        $this->newLine();

        if (!$this->option('force') && !$this->confirm('Esto no se puede deshacer. ¿Continuar?', false)) {
            $this->info('Cancelado. No se borró nada.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($id) {
            $contratos = DB::table('contracts')
                ->whereIn('branch_id', fn ($q) => $q->select('id')->from('branches')->where('company_id', $id))
                ->pluck('id');

            if ($contratos->isNotEmpty()) {
                $facturas = DB::table('invoices')->whereIn('contract_id', $contratos)->pluck('id');

                DB::table('payments')->whereIn('invoice_id', $facturas)->delete();
                DB::table('invoice_items')->whereIn('invoice_id', $facturas)->delete();
                DB::table('invoices')->whereIn('id', $facturas)->delete();
                DB::table('technical_orders')->whereIn('contract_id', $contratos)->delete();
                DB::table('contracts')->whereIn('id', $contratos)->delete();
            }

            // Lo que cuelga de la empresa o de sus sucursales.
            $sucursales = DB::table('branches')->where('company_id', $id)->pluck('id');

            DB::table('clients')->where('company_id', $id)->delete();
            DB::table('plan_service')
                ->whereIn('plan_id', fn ($q) => $q->select('id')->from('plans')->whereIn('branch_id', $sucursales))
                ->delete();
            DB::table('plans')->whereIn('branch_id', $sucursales)->delete();
            DB::table('services')->whereIn('branch_id', $sucursales)->delete();
            DB::table('affinity_groups')->where('company_id', $id)->delete();
            DB::table('document_sequences')->where('company_id', $id)->delete();
            DB::table('user_branch')->whereIn('branch_id', $sucursales)->delete();
            DB::table('branches')->where('company_id', $id)->delete();
            DB::table('companies')->where('id', $id)->delete();
        });

        $this->info("Empresa {$id} borrada.");
        $this->line('La trazabilidad (audits) se conserva a propósito: es el registro');
        $this->line('de lo que se hizo, y borrarlo dejaría un hueco inexplicable.');

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function contar(int $empresaId): array
    {
        $sucursales = DB::table('branches')->where('company_id', $empresaId)->pluck('id');
        $contratos = DB::table('contracts')->whereIn('branch_id', $sucursales)->pluck('id');

        return [
            'sucursales' => $sucursales->count(),
            'clientes' => DB::table('clients')->where('company_id', $empresaId)->count(),
            'contratos' => $contratos->count(),
            'facturas' => DB::table('invoices')->whereIn('contract_id', $contratos)->count(),
            'pagos' => DB::table('payments')
                ->whereIn('invoice_id', fn ($q) => $q->select('id')->from('invoices')->whereIn('contract_id', $contratos))
                ->count(),
        ];
    }
}
