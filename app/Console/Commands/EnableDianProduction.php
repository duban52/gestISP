<?php

namespace App\Console\Commands;

use App\Billing\Dian\DianReadiness;
use App\Models\Company;
use App\Models\DianConfiguration;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;

/**
 * Enciende la facturación electrónica de una empresa.
 *
 *     php artisan dian:habilitar --empresa=3
 *     php artisan dian:habilitar --empresa=3 --forzar
 *
 * ES EL INTERRUPTOR MÁS DELICADO DEL SISTEMA
 * ------------------------------------------
 * A partir del momento en que se acciona, las facturas de los grupos de
 * afinidad electrónicos dejan de ser documentos internos y pasan a
 * gastar consecutivos de un rango autorizado por la DIAN. Eso no se
 * deshace: un consecutivo gastado en un documento que la DIAN rechaza
 * deja un hueco que hay que justificar.
 *
 * POR ESO PREGUNTA ANTES AL DIAGNÓSTICO
 * -------------------------------------
 * Si falta cualquier cosa bloqueante —datos fiscales, certificado,
 * resolución vigente, rango, la habilitación de la DIAN— no enciende
 * nada y dice qué falta. `--forzar` existe para el caso en que el
 * diagnóstico se equivoque, pero avisa de lo que se está saltando: no
 * es un atajo, es una decisión.
 *
 * QUEDA EN LA TRAZABILIDAD
 * ------------------------
 * Quién la encendió y cuándo. Es de las cosas que un día habrá que
 * poder explicar.
 */
class EnableDianProduction extends Command
{
    protected $signature = 'dian:habilitar
                            {--empresa= : La empresa que se habilita}
                            {--forzar : Enciende aunque el diagnóstico encuentre bloqueos}
                            {--apagar : Vuelve a pruebas y desactiva la emisión electrónica}';

    protected $description = 'Enciende (o apaga) la facturación electrónica de una empresa';

    public function handle(DianReadiness $revision, AuditLogger $trazabilidad): int
    {
        $empresa = $this->empresa();

        if (!$empresa) {
            return self::FAILURE;
        }

        if ($this->option('apagar')) {
            return $this->apagar($empresa, $trazabilidad);
        }

        $bloqueos = $revision->bloqueos($empresa);

        if ($bloqueos !== [] && !$this->option('forzar')) {
            $this->error('No se puede habilitar todavía. Falta:');

            foreach ($bloqueos as $bloqueo) {
                $this->line('  · ' . $bloqueo);
            }

            $this->newLine();
            $this->line('Revise el detalle con: <options=bold>php artisan dian:diagnostico --empresa=' . $empresa->id . '</>');

            return self::FAILURE;
        }

        if ($bloqueos !== []) {
            $this->warn('Se está habilitando SALTÁNDOSE estos bloqueos:');

            foreach ($bloqueos as $bloqueo) {
                $this->line('  · ' . $bloqueo);
            }
        }

        $this->line(sprintf(
            'Se va a encender la facturación electrónica de %s (%s).',
            $empresa->nombreVisible(),
            $empresa->identificacion(),
        ));
        $this->warn('A partir de ahora sus contratos de grupos electrónicos gastarán consecutivos autorizados por la DIAN.');

        if (!$this->confirm('¿Continuar?', false)) {
            $this->line('No se ha cambiado nada.');

            return self::SUCCESS;
        }

        $configuracion = DianConfiguration::withoutGlobalScopes()->firstOrNew([
            'company_id' => $empresa->id,
        ]);

        $configuracion->environment_code = DianConfiguration::PRODUCCION;
        $configuracion->enabled_at = $configuracion->enabled_at ?? now();
        $configuracion->save();

        $empresa->update(['electronic_invoicing_enabled' => true]);

        $trazabilidad->action(
            'dian.habilitada',
            sprintf('Encendió la facturación electrónica de %s', $empresa->nombreVisible()),
            [
                'empresa' => $empresa->identificacion(),
                'bloqueos_saltados' => $bloqueos,
                'forzado' => (bool) $this->option('forzar'),
            ],
            $empresa,
            'facturacion',
        );

        $this->info('Facturación electrónica encendida.');

        return self::SUCCESS;
    }

    /**
     * Vuelve a pruebas.
     *
     * NO borra `enabled_at`: la habilitación ante la DIAN se consiguió y
     * eso es un hecho histórico. Lo que se apaga es la emisión.
     */
    private function apagar(Company $empresa, AuditLogger $trazabilidad): int
    {
        if (!$this->confirm(sprintf('¿Apagar la facturación electrónica de %s?', $empresa->nombreVisible()), false)) {
            $this->line('No se ha cambiado nada.');

            return self::SUCCESS;
        }

        $empresa->update(['electronic_invoicing_enabled' => false]);

        $empresa->dianConfiguration?->update([
            'environment_code' => DianConfiguration::PRUEBAS,
        ]);

        $trazabilidad->action(
            'dian.apagada',
            sprintf('Apagó la facturación electrónica de %s', $empresa->nombreVisible()),
            ['empresa' => $empresa->identificacion()],
            $empresa,
            'facturacion',
        );

        $this->info('Apagada. Las facturas vuelven a salir como documento interno.');
        $this->line('Las ya emitidas no cambian: su tipo quedó congelado al emitirlas.');

        return self::SUCCESS;
    }

    private function empresa(): ?Company
    {
        $id = $this->option('empresa');

        if (!$id) {
            $this->error('Indique la empresa: --empresa=ID');
            $this->line('Puede verlas con: php artisan dian:diagnostico');

            return null;
        }

        $empresa = Company::find($id);

        if (!$empresa) {
            $this->error('No existe ninguna empresa con id ' . $id . '.');
        }

        return $empresa;
    }
}
