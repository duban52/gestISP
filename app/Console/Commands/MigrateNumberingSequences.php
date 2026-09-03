<?php

namespace App\Console\Commands;

use App\Billing\Enums\NoteType;
use App\Models\Branch;
use App\Models\Contract;
use App\Models\CreditDebitNote;
use App\Models\DocumentSequence;
use App\Models\NoteNumberingSequence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mueve los contadores viejos a `document_sequences`.
 *
 * QUÉ MUEVE
 * ---------
 *   · Contratos: de `branches.contract_prefix` + `contract_next_number`.
 *   · Notas C/D: de `note_numbering_sequences`.
 *
 * Las FACTURAS no se tocan. Su tabla ya lleva resolución, vigencia y
 * rango, y sus consecutivos irán a la tabla fiscal cuando esa exista;
 * moverlos aquí sería moverlos dos veces.
 *
 * POR QUÉ UN COMANDO Y NO LA MIGRACIÓN
 * ------------------------------------
 * Un contador mal copiado repite números de documentos ya emitidos.
 * Eso tiene que poder revisarse ANTES de escribir nada, y una
 * migración no da esa oportunidad: corre sola en el despliegue.
 *
 *     php artisan numeracion:migrar --dry-run    ← primero esto
 *     php artisan numeracion:migrar              ← y solo después esto
 *
 * NO ES OBLIGATORIO PARA QUE EL SISTEMA FUNCIONE
 * ----------------------------------------------
 * `DocumentNumberService` crea la serie que le falte arrancando desde
 * el mayor consecutivo REALMENTE usado. Si este comando no se ejecuta,
 * la serie nace bien igualmente la primera vez que se pide un número.
 *
 * Lo que aporta el comando es hacerlo de golpe, con el antes y el
 * después a la vista, en vez de una sucursal a la vez y a ciegas.
 *
 * EL CONTADOR SALE DEL MAYOR DE DOS
 * ---------------------------------
 *     current_number = MAX(contador viejo, mayor consecutivo emitido)
 *
 * No es paranoia: el prefijo se puede cambiar y hay contratos
 * importados de otros sistemas con su propio número. El generador
 * viejo ya hacía ese `max()` en cada reserva, y la migración tiene que
 * preservar esa garantía o el primer contrato nuevo repetiría un
 * número existente.
 */
class MigrateNumberingSequences extends Command
{
    protected $signature = 'numeracion:migrar
                            {--dry-run : Muestra qué haría, sin escribir nada}';

    protected $description = 'Mueve los contadores de contratos y notas a document_sequences';

    /** Dígitos del consecutivo de contrato. */
    private const DIGITOS_CONTRATO = 6;

    public function handle(): int
    {
        $simulacro = (bool) $this->option('dry-run');

        $this->info($simulacro
            ? 'SIMULACRO — no se va a escribir nada.'
            : 'Migrando contadores. Ejecute antes con --dry-run si no lo ha hecho.');

        $this->newLine();

        $filas = array_merge(
            $this->contratos(),
            $this->notas(),
        );

        if ($filas === []) {
            $this->warn('No hay contadores que migrar.');

            return self::SUCCESS;
        }

        $this->table(
            ['Empresa', 'Sucursal', 'Tipo', 'Prefijo', 'Contador viejo', 'Mayor emitido', 'Queda en', 'Estado'],
            array_map(fn (array $f) => [
                $f['company_id'],
                $f['branch'],
                $f['tipo'],
                $f['prefijo'],
                $f['contador'],
                $f['mayor'],
                $f['queda'],
                $f['estado'],
            ], $filas),
        );

        if ($simulacro) {
            $this->newLine();
            $this->comment('Revise la columna «Queda en»: es el último número que se dará por usado.');
            $this->comment('El siguiente documento emitido llevará ese número más uno.');

            return self::SUCCESS;
        }

        $escritas = $this->escribir($filas);

        $this->newLine();
        $this->info("Series creadas o actualizadas: {$escritas}.");

        return $this->verificar($filas);
    }

    // ==================== Lo que hay hoy ====================

    /**
     * Contadores de contrato, uno por sucursal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function contratos(): array
    {
        $filas = [];

        // withoutGlobalScopes: un comando recorre TODAS las empresas.
        foreach (Branch::withoutGlobalScopes()->orderBy('company_id')->orderBy('id')->get() as $sucursal) {
            $prefijo = $sucursal->contract_prefix ?: 'CTR';
            $contador = (int) $sucursal->contract_next_number;
            $mayor = $this->mayorContratoEmitido((int) $sucursal->id, $prefijo);

            $filas[] = [
                'company_id' => (int) $sucursal->company_id,
                'branch_id' => (int) $sucursal->id,
                'branch' => $sucursal->name,
                'tipo' => DocumentSequence::CONTRATO,
                'prefijo' => $prefijo,
                'padding' => self::DIGITOS_CONTRATO,
                'contador' => $contador,
                'mayor' => $mayor,
                'queda' => max($contador, $mayor),
                'estado' => $this->estadoDe(
                    DocumentSequence::CONTRATO,
                    (int) $sucursal->company_id,
                    (int) $sucursal->id,
                ),
            ];
        }

        return $filas;
    }

    /**
     * Contadores de nota, uno por sucursal y tipo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function notas(): array
    {
        $filas = [];

        foreach (NoteNumberingSequence::withoutGlobalScopes()->get() as $secuencia) {
            $sucursal = Branch::withoutGlobalScopes()->find($secuencia->branch_id);

            if (!$sucursal) {
                continue;
            }

            $tipo = $secuencia->type === NoteType::Credito->value
                ? DocumentSequence::NOTA_CREDITO
                : DocumentSequence::NOTA_DEBITO;

            $contador = (int) $secuencia->current_number;

            $mayor = (int) CreditDebitNote::withoutGlobalScopes()
                ->where('branch_id', $secuencia->branch_id)
                ->where('type', $secuencia->type)
                ->max('number');

            $filas[] = [
                'company_id' => (int) $sucursal->company_id,
                'branch_id' => (int) $sucursal->id,
                'branch' => $sucursal->name,
                'tipo' => $tipo,
                // El separador pasa a vivir DENTRO del prefijo: así el
                // formato entero es un dato de la serie. El número
                // completo sigue saliendo igual (NC-1).
                'prefijo' => $secuencia->prefix . '-',
                'padding' => 0,
                'contador' => $contador,
                'mayor' => $mayor,
                'queda' => max($contador, $mayor),
                'estado' => $this->estadoDe($tipo, (int) $sucursal->company_id, (int) $sucursal->id),
            ];
        }

        return $filas;
    }

    /** Mayor consecutivo de contrato emitido con ese prefijo. */
    private function mayorContratoEmitido(int $branchId, string $prefijo): int
    {
        $numeros = Contract::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNotNull('contract_number')
            ->where('contract_number', 'like', $prefijo . '%')
            ->pluck('contract_number');

        $mayor = 0;

        foreach ($numeros as $numero) {
            $digitos = preg_replace('/\D/', '', substr((string) $numero, strlen($prefijo)));

            if ($digitos !== '' && (int) $digitos > $mayor) {
                $mayor = (int) $digitos;
            }
        }

        return $mayor;
    }

    /** ¿La serie ya existe? */
    private function estadoDe(string $tipo, int $companyId, int $branchId): string
    {
        $existe = DocumentSequence::withoutGlobalScope('empresa')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('document_type', $tipo)
            ->where('active', true)
            ->exists();

        return $existe ? 'ya existe' : 'se crea';
    }

    // ==================== Escribir ====================

    /** @param  array<int, array<string, mixed>>  $filas */
    private function escribir(array $filas): int
    {
        $escritas = 0;

        DB::transaction(function () use ($filas, &$escritas) {
            foreach ($filas as $fila) {
                $serie = DocumentSequence::withoutGlobalScope('empresa')->firstOrCreate(
                    [
                        'company_id' => $fila['company_id'],
                        'branch_id' => $fila['branch_id'],
                        'document_type' => $fila['tipo'],
                        'active' => true,
                    ],
                    [
                        'prefix' => $fila['prefijo'],
                        'padding' => $fila['padding'],
                        'current_number' => 0,
                    ],
                );

                // El contador SOLO sube. Si la serie ya existía y va
                // más adelantada que lo que dice el contador viejo, se
                // respeta lo que ya entregó: bajarlo repetiría números.
                if ($fila['queda'] > $serie->current_number) {
                    $serie->update(['current_number' => $fila['queda']]);
                    $escritas++;
                } elseif ($serie->wasRecentlyCreated) {
                    $escritas++;
                }
            }
        });

        return $escritas;
    }

    /**
     * Comprueba que ningún documento emitido supere su contador.
     *
     * Es la verificación de después: si alguno lo supera, el próximo
     * documento repetiría un número que ya existe.
     */
    private function verificar(array $filas): int
    {
        $problemas = [];

        foreach ($filas as $fila) {
            $serie = DocumentSequence::withoutGlobalScope('empresa')
                ->where('company_id', $fila['company_id'])
                ->where('branch_id', $fila['branch_id'])
                ->where('document_type', $fila['tipo'])
                ->where('active', true)
                ->first();

            if (!$serie) {
                $problemas[] = "{$fila['branch']} / {$fila['tipo']}: la serie no se creó.";

                continue;
            }

            if ($fila['mayor'] > $serie->current_number) {
                $problemas[] = sprintf(
                    '%s / %s: hay un documento con el número %d y el contador quedó en %d.',
                    $fila['branch'],
                    $fila['tipo'],
                    $fila['mayor'],
                    $serie->current_number,
                );
            }
        }

        if ($problemas === []) {
            $this->info('Verificación correcta: ningún documento emitido supera su contador.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('VERIFICACIÓN FALLIDA. No emita documentos hasta revisar esto:');

        foreach ($problemas as $problema) {
            $this->line('  · ' . $problema);
        }

        return self::FAILURE;
    }
}
