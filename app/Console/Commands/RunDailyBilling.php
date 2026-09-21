<?php

namespace App\Console\Commands;

use App\Billing\Services\MonthlyBillingRun;
use App\Billing\Services\OverdueProcessor;
use App\Models\Branch;
use App\Models\BillingRun;
use App\Models\BranchBillingSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * La tarea diaria de facturación.
 *
 * DOS TRABAJOS, Y EL PRIMERO ES PARA TODAS LAS SUCURSALES
 * -------------------------------------------------------
 * 1. MORA. Marcar vencidas las facturas cuyo plazo pasó y refrescar
 *    las suspensiones. Esto corre siempre, facture la sucursal a mano
 *    o sola.
 *
 *    Hasta ahora esto solo pasaba cuando alguien ABRÍA la pantalla de
 *    facturas o lanzaba una corrida. Como el aviso de cobro de las
 *    08:00 busca facturas en estado «Vencida», si nadie entraba no
 *    había vencidas, no salía ningún aviso y nadie se suspendía: la
 *    cobranza dependía de que una persona abriera una pantalla.
 *
 * 2. FACTURACIÓN. Solo de las sucursales en modo automático a las que
 *    hoy les toca su día. Las manuales no se tocan: siguen saliendo
 *    del botón, igual que siempre.
 *
 * SE PROGRAMA ANTES DE LOS AVISOS (ver Console\Kernel): marcar la mora
 * después de mandar los recordatorios sería mandarlos con el estado
 * del día anterior.
 *
 * NO FACTURA DOS VECES. Tres cosas lo impiden, de fuera hacia dentro:
 * aquí se salta la sucursal que ya tiene una corrida de este mes; la
 * corrida no duplica facturas del mismo período; y el generador
 * comprueba `billed_year_month` contrato a contrato.
 */
class RunDailyBilling extends Command
{
    protected $signature = 'facturacion:diaria
                            {--sucursal= : Correr solo esta sucursal (id)}
                            {--forzar : Facturar aunque hoy no sea el día configurado}
                            {--dry-run : Decir qué haría, sin escribir nada}';

    protected $description = 'Marca la mora de todas las sucursales y factura las que tengan la corrida automática';

    public function __construct(
        private readonly OverdueProcessor $overdueProcessor,
        private readonly MonthlyBillingRun $billingRun,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hoy = now();
        $simulacion = (bool) $this->option('dry-run');
        $soloSucursal = $this->option('sucursal');

        $sucursales = Branch::withoutGlobalScopes()
            ->when($soloSucursal, fn ($q) => $q->whereKey((int) $soloSucursal))
            ->orderBy('id')
            ->get();

        if ($sucursales->isEmpty()) {
            $this->error('No hay sucursales que procesar.');

            return self::FAILURE;
        }

        $this->comprobarMora($sucursales, $simulacion);
        $this->facturarLasQueToca($sucursales, $hoy, $simulacion);

        return self::SUCCESS;
    }

    /**
     * Mora: para TODAS las sucursales, sean manuales o automáticas.
     */
    private function comprobarMora($sucursales, bool $simulacion): void
    {
        if ($simulacion) {
            $this->line('Mora: no se toca nada (--dry-run).');

            return;
        }

        $vencidas = $this->overdueProcessor->markOverdueInvoices();

        foreach ($sucursales as $sucursal) {
            $this->overdueProcessor->refreshContractSuspensions($sucursal->id);
        }

        $this->info(sprintf(
            'Mora: %d factura(s) pasaron a vencida; suspensiones refrescadas en %d sucursal(es).',
            $vencidas,
            $sucursales->count(),
        ));

        Log::info('facturacion:diaria mora', ['vencidas' => $vencidas, 'sucursales' => $sucursales->count()]);
    }

    /**
     * Facturación: solo las automáticas a las que hoy les toca.
     */
    private function facturarLasQueToca($sucursales, $hoy, bool $simulacion): void
    {
        $forzar = (bool) $this->option('forzar');
        $periodo = $hoy->format('Ym');
        $corridas = 0;

        foreach ($sucursales as $sucursal) {
            $config = BranchBillingSetting::forBranch($sucursal->id);

            if (!$config->facturaSola()) {
                continue;
            }

            if (!$forzar && !$config->facturaHoy($hoy)) {
                continue;
            }

            // Ya se facturó este mes en esta sucursal: ni a mano ni
            // sola se factura dos veces.
            $yaCorrio = BillingRun::withoutGlobalScopes()
                ->where('branch_id', $sucursal->id)
                ->where('billed_year_month', $periodo)
                ->exists();

            if ($yaCorrio) {
                $this->line("· {$sucursal->name}: ya tiene una corrida de {$periodo}, se omite.");
                continue;
            }

            if ($simulacion) {
                $this->line("· {$sucursal->name}: le tocaría facturar hoy (día {$config->billing_day}).");
                $corridas++;
                continue;
            }

            // Sin usuario: la corrida no la lanzó nadie.
            $resultado = $this->billingRun->runForBranch($sucursal->id, null);
            $corridas++;

            $this->info(sprintf(
                '· %s: %d factura(s) generada(s), %d omitida(s), $%s facturados.',
                $sucursal->name,
                $resultado['generated'],
                $resultado['skipped'],
                number_format($resultado['total_billed'], 2, ',', '.'),
            ));

            Log::info('facturacion:diaria corrida', [
                'sucursal' => $sucursal->id,
                'periodo' => $periodo,
            ] + $resultado);
        }

        if ($corridas === 0) {
            $this->line('Facturación: hoy no le toca a ninguna sucursal.');
        }
    }
}
