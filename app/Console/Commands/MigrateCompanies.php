<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        if ($huerfanas->isEmpty() && $duplicadas->isEmpty()) {
            $this->newLine();
            $this->info('Nada que reparar.');

            return self::SUCCESS;
        }

        $this->repararHuerfanas($huerfanas, $aplicar);
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
