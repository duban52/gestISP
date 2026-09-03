<?php

namespace App\Services\Numbering;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * El único sitio donde se reserva un consecutivo.
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * Había tres copias de la misma lógica —contratos, facturas y notas—,
 * cada una con su propio bloqueo, su propio formateo y su propia idea
 * de qué hacer si el rango se agota. Tres sitios donde arreglar el
 * mismo fallo, y tres suites probando la misma garantía.
 *
 * La garantía es una sola y es la que importa: **dos altas simultáneas
 * nunca reciben el mismo número**.
 *
 * CÓMO SE CONSIGUE
 * ----------------
 * Bloqueo pesimista sobre la fila de la serie (`lockForUpdate`). El
 * segundo proceso espera a que el primero haga commit y entonces lee
 * un contador ya incrementado. No es optimista ni depende de reintentos.
 *
 * Por eso **tiene que correr dentro de una transacción**: el bloqueo
 * vive hasta el commit. Si no hay una abierta se abre aquí, pero lo
 * normal es que quien llama ya la tenga —el número casi siempre se
 * reserva junto con el documento que lo lleva, y si el documento falla
 * el número tiene que volver atrás con él.
 *
 * LA SEMILLA, QUE ES LO QUE EVITA REPETIR
 * ---------------------------------------
 * Cuando la serie no existe todavía, no se crea en cero: se crea a
 * partir del mayor consecutivo REALMENTE usado, que quien llama sabe
 * calcular y pasa en `$semilla`.
 *
 * Sin eso, una serie recién creada entregaría el número 1 a un sistema
 * que ya tiene mil contratos, y el UNIQUE de la base rechazaría el
 * alta. Es la misma protección que ya tenía `ContractNumberGenerator`,
 * y es también lo que permite que el comando de migración de los
 * contadores no sea obligatorio para que el sistema funcione: si no se
 * ha ejecutado, la serie nace bien igualmente.
 */
class DocumentNumberService
{
    /**
     * Reserva el siguiente consecutivo de una serie y lo devuelve
     * formateado.
     *
     * @param  string        $tipo       DocumentSequence::CONTRATO, etc.
     * @param  int           $companyId  Empresa dueña de la serie
     * @param  int|null      $branchId   Sucursal, o null si la serie es de empresa
     * @param  array{prefix?: string, padding?: int}  $porDefecto  Con qué nace si no existe
     * @param  callable|null $semilla    fn (DocumentSequence $s): int — mayor consecutivo ya usado
     */
    public function siguiente(
        string $tipo,
        int $companyId,
        ?int $branchId = null,
        array $porDefecto = [],
        ?callable $semilla = null,
    ): NumeroReservado {
        $reservar = fn () => $this->reservar($tipo, $companyId, $branchId, $porDefecto, $semilla);

        return DB::transactionLevel() > 0
            ? $reservar()
            : DB::transaction($reservar);
    }

    /**
     * Adelanta el contador si un número que viene de fuera lo supera.
     *
     * Al migrar de otro sistema se respeta el consecutivo que el
     * documento ya traía, pero entonces hay que mover el contador o el
     * siguiente documento nuevo intentaría repetir un número usado.
     *
     * No lo retrocede nunca: un contador que baja es un número
     * repetido esperando a ocurrir.
     */
    public function registrarExterno(
        string $tipo,
        int $companyId,
        ?int $branchId,
        int $consecutivo,
        array $porDefecto = [],
        ?callable $semilla = null,
    ): void {
        $ajustar = function () use ($tipo, $companyId, $branchId, $consecutivo, $porDefecto, $semilla) {
            $serie = $this->serieBloqueada($tipo, $companyId, $branchId, $porDefecto, $semilla);

            if ($consecutivo > $serie->current_number) {
                $serie->update(['current_number' => $consecutivo]);
            }
        };

        DB::transactionLevel() > 0 ? $ajustar() : DB::transaction($ajustar);
    }

    /**
     * La serie activa, sin reservar nada.
     *
     * Para pantallas que quieren enseñar el prefijo o cuánto queda del
     * rango. No bloquea: lo que devuelve es una foto, no una reserva.
     */
    public function serie(string $tipo, int $companyId, ?int $branchId = null): ?DocumentSequence
    {
        return DocumentSequence::withoutGlobalScope('empresa')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->deTipo($tipo)
            ->activas()
            ->first();
    }

    // ==================== Dentro de la transacción ====================

    private function reservar(
        string $tipo,
        int $companyId,
        ?int $branchId,
        array $porDefecto,
        ?callable $semilla,
    ): NumeroReservado {
        return $this->reservarEn(
            $this->serieBloqueada($tipo, $companyId, $branchId, $porDefecto, $semilla)
        );
    }

    /**
     * El algoritmo, sobre una serie YA BLOQUEADA.
     *
     * Es el unico sitio donde se suma uno, se comprueba el rango y se
     * formatea. Lo usan las dos tablas de series —la interna y la de
     * facturas— porque esa comprobacion de rango no puede tener dos
     * versiones: emitir pasado el rango autorizado es emitir con
     * numeros que nadie autorizo.
     *
     * Quien llama es responsable de haber bloqueado la fila y de estar
     * dentro de una transaccion. Este metodo no lo comprueba porque no
     * puede: el bloqueo es una propiedad de la consulta que la leyo.
     */
    public function reservarEn(SerieNumerable $serie): NumeroReservado
    {
        $siguiente = max($serie->consecutivoActual() + 1, $serie->rangoDesde() ?? 1);

        $hasta = $serie->rangoHasta();

        if ($hasta !== null && $siguiente > $hasta) {
            throw new RuntimeException(sprintf(
                'La serie %s agotó su rango autorizado (%d-%d). Registre uno nuevo antes de seguir emitiendo.',
                $serie->nombreDeSerie(),
                $serie->rangoDesde() ?? 1,
                $hasta,
            ));
        }

        $serie->avanzarA($siguiente);

        return new NumeroReservado(
            serie: $serie,
            consecutivo: $siguiente,
            completo: $serie->formatearNumero($siguiente),
        );
    }

    /**
     * La serie activa con su fila bloqueada, creándola si falta.
     *
     * El `firstOrCreate` + relectura con lock es deliberado:
     * `firstOrCreate` no bloquea, así que dos procesos podrían crearla
     * a la vez —de eso se encarga el UNIQUE de la base— y después hay
     * que volver a leerla CON el bloqueo para incrementarla.
     */
    private function serieBloqueada(
        string $tipo,
        int $companyId,
        ?int $branchId,
        array $porDefecto,
        ?callable $semilla,
    ): DocumentSequence {
        $buscar = fn () => DocumentSequence::withoutGlobalScope('empresa')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->deTipo($tipo)
            ->activas()
            ->lockForUpdate()
            ->first();

        if ($serie = $buscar()) {
            return $serie;
        }

        $nueva = DocumentSequence::withoutGlobalScope('empresa')->firstOrCreate(
            [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'document_type' => $tipo,
                'active' => true,
            ],
            [
                'prefix' => $porDefecto['prefix'] ?? strtoupper(substr($tipo, 0, 3)),
                'padding' => $porDefecto['padding'] ?? 0,
                'current_number' => 0,
            ],
        );

        // La semilla: se arranca desde el mayor consecutivo ya usado,
        // no desde cero. Solo al crearla, y solo si sube.
        if ($semilla !== null) {
            $usado = (int) $semilla($nueva);

            if ($usado > $nueva->current_number) {
                $nueva->update(['current_number' => $usado]);
            }
        }

        return $buscar() ?? $nueva->refresh();
    }
}
