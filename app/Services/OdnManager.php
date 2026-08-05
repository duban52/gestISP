<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\NapBox;
use App\Models\NapPort;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\OpticalNetwork;
use App\Models\PonPort;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Operaciones de la red óptica que tocan más de una tabla.
 *
 * Aquí vive todo lo que no puede quedar a medias: crear una caja con
 * sus puertos, cambiarle la capacidad, ocupar o liberar un puerto.
 * Todas van en transacción, porque una caja con la capacidad cambiada
 * pero sin sus puertos nuevos es peor que no haberla tocado.
 */
class OdnManager
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ==================== Cajas ====================

    /**
     * Crea la caja con sus puertos.
     *
     * El código se saca del consecutivo de la red bloqueando su fila:
     * dos altas simultáneas no pueden llevarse el mismo NAP-014.
     *
     * @param  array<string, mixed>  $datos
     */
    public function crearCaja(OpticalNetwork $red, array $datos): NapBox
    {
        return DB::transaction(function () use ($red, $datos) {
            $caja = NapBox::create(array_merge($datos, [
                'optical_network_id' => $red->id,
                'code' => $datos['code'] ?? $this->siguienteCodigo($red),
                'user_id' => auth()->id(),
            ]));

            $this->generarPuertos($caja);

            $this->auditLogger->action(
                'naps.created',
                sprintf(
                    'Creó la caja %s de %d puertos en %s',
                    $caja->code,
                    $caja->capacity,
                    $caja->address ?: 'sin dirección',
                ),
                [
                    'caja' => $caja->code,
                    'red' => $red->name,
                    'capacidad' => $caja->capacity,
                    'puerto_pon' => $caja->ponPort?->etiqueta,
                    'coordenadas' => $caja->estaGeorreferenciada()
                        ? "{$caja->latitude}, {$caja->longitude}"
                        : null,
                ],
                $caja,
                'red',
            );

            return $caja;
        });
    }

    /**
     * Ajusta la capacidad creando o quitando puertos.
     *
     * Reducir NO borra puertos ocupados: si el puerto 12 tiene un
     * cliente, bajar la caja a 8 dejaría a ese contrato apuntando a un
     * puerto inexistente. Se avisa y no se toca.
     */
    public function ajustarCapacidad(NapBox $caja, int $nuevaCapacidad): void
    {
        if ($nuevaCapacidad === $caja->capacity) {
            return;
        }

        DB::transaction(function () use ($caja, $nuevaCapacidad) {
            $anterior = $caja->capacity;

            if ($nuevaCapacidad < $anterior) {
                $sobrantes = $caja->ports()
                    ->where('number', '>', $nuevaCapacidad)
                    ->with('contract')
                    ->get();

                $ocupados = $sobrantes->filter(fn (NapPort $p) => $p->estaOcupado());

                if ($ocupados->isNotEmpty()) {
                    throw new RuntimeException(sprintf(
                        'No se puede reducir a %d puertos: el %s todavía tiene cliente. Traslade esos servicios primero.',
                        $nuevaCapacidad,
                        $ocupados->count() === 1
                            ? 'puerto ' . $ocupados->first()->number
                            : 'puertos ' . $ocupados->pluck('number')->implode(', '),
                    ));
                }

                NapPort::whereIn('id', $sobrantes->pluck('id'))->delete();
            }

            $caja->update(['capacity' => $nuevaCapacidad]);

            $this->generarPuertos($caja->refresh());

            $this->auditLogger->action(
                'naps.capacity_changed',
                sprintf('Cambió la capacidad de la caja %s de %d a %d puertos', $caja->code, $anterior, $nuevaCapacidad),
                ['caja' => $caja->code, 'antes' => $anterior, 'ahora' => $nuevaCapacidad],
                $caja,
                'red',
            );
        });
    }

    /**
     * Crea las filas de puerto que falten.
     *
     * Es idempotente: se puede llamar tras cualquier cambio de
     * capacidad sin duplicar nada.
     */
    public function generarPuertos(NapBox $caja): void
    {
        $existentes = $caja->ports()->pluck('number')->flip();

        $nuevos = [];

        for ($numero = 1; $numero <= $caja->capacity; $numero++) {
            if (isset($existentes[$numero])) {
                continue;
            }

            $nuevos[] = [
                'nap_box_id' => $caja->id,
                'number' => $numero,
                'status' => NapPort::LIBRE,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($nuevos) {
            NapPort::insert($nuevos);
        }
    }

    /**
     * Siguiente código de caja de la red (NAP001, NAP002…).
     *
     * Bloquea la fila de la red hasta el commit, igual que la
     * numeración de contratos: dos altas a la vez no pueden llevarse
     * el mismo número.
     */
    private function siguienteCodigo(OpticalNetwork $red): string
    {
        $bloqueada = OpticalNetwork::whereKey($red->id)->lockForUpdate()->firstOrFail();

        $numero = $bloqueada->nap_next_number;

        $bloqueada->update(['nap_next_number' => $numero + 1]);

        return $bloqueada->nap_prefix . str_pad((string) $numero, 3, '0', STR_PAD_LEFT);
    }

    // ==================== Puertos y contratos ====================

    /**
     * Instala un contrato en un puerto de una caja.
     *
     * Comprueba lo que la base ya impide con su índice único, pero con
     * un mensaje que se entiende: "ese puerto ya lo tiene Fulano" es
     * accionable; un error de clave duplicada no.
     */
    public function asignarPuerto(Contract $contrato, NapPort $puerto): void
    {
        $puerto->loadMissing('contract.client', 'napBox');

        if ($puerto->estaOcupado() && $puerto->contract->id !== $contrato->id) {
            $ocupante = $puerto->contract;

            throw new RuntimeException(sprintf(
                'El puerto %d de la caja %s ya está ocupado por el contrato %s (%s).',
                $puerto->number,
                $puerto->napBox->code,
                $ocupante->numero_visible,
                trim(($ocupante->client?->name ?? '') . ' ' . ($ocupante->client?->last_name ?? '')) ?: 'sin cliente',
            ));
        }

        if ($puerto->status === NapPort::DANADO) {
            throw new RuntimeException(
                "El puerto {$puerto->number} de la caja {$puerto->napBox->code} está marcado como dañado."
            );
        }

        $anterior = $contrato->napPort;

        DB::transaction(function () use ($contrato, $puerto) {
            $contrato->update([
                'nap_port_id' => $puerto->id,
                // Se mantiene el texto legible en sintonía: es lo que
                // ve quien abre la ficha sin entrar al módulo de redes.
                'nap_port' => $puerto->napBox->code . ' / P' . $puerto->number,
            ]);
        });

        $this->auditLogger->action(
            'naps.port_assigned',
            sprintf(
                'Asignó el contrato %s al puerto %d de la caja %s%s',
                $contrato->numero_visible,
                $puerto->number,
                $puerto->napBox->code,
                $anterior ? ' (antes estaba en ' . $anterior->napBox->code . '/P' . $anterior->number . ')' : '',
            ),
            [
                'contrato' => $contrato->numero_visible,
                'caja' => $puerto->napBox->code,
                'puerto' => $puerto->number,
                'puerto_anterior' => $anterior
                    ? $anterior->napBox->code . '/P' . $anterior->number
                    : null,
            ],
            $contrato,
            'red',
        );
    }

    /** Saca un contrato de su puerto y lo deja libre. */
    public function liberarPuerto(Contract $contrato): void
    {
        $puerto = $contrato->napPort;

        if (!$puerto) {
            return;
        }

        $puerto->loadMissing('napBox');

        $contrato->update(['nap_port_id' => null]);

        $this->auditLogger->action(
            'naps.port_released',
            sprintf(
                'Liberó el puerto %d de la caja %s (contrato %s)',
                $puerto->number,
                $puerto->napBox->code,
                $contrato->numero_visible,
            ),
            [
                'contrato' => $contrato->numero_visible,
                'caja' => $puerto->napBox->code,
                'puerto' => $puerto->number,
            ],
            $contrato,
            'red',
        );
    }

    // ==================== Puertos PON ====================

    /**
     * Da de alta los puertos PON que ya están en uso según las ONTs.
     *
     * Documentar a mano una red tendida es tedioso y se hace mal. Las
     * ONTs ya dicen de qué slot/port cuelgan, así que se siembran de
     * ahí y después se completan los datos que el equipo no sabe
     * (splitter, zona, descripción).
     *
     * @return int Cuántos puertos se crearon
     */
    public function detectarPuertosPon(OpticalNetwork $red, Olt $olt): int
    {
        // Primero se le pregunta al equipo: así aparecen TODOS los
        // puertos que tiene, incluidos los vacíos, que son justo los
        // que interesan al planear dónde crecer. Deducirlos de las ONTs
        // solo encuentra los que ya están en uso.
        try {
            $resumen = app(OltHardwareDiscovery::class)->descubrir($olt);

            return $resumen['pon_nuevos'];
        } catch (RuntimeException $e) {
            // La OLT puede estar caída, sin SNMP o sin la extensión
            // instalada. En ese caso se cae al método viejo: da menos
            // puertos, pero es mejor que no dar ninguno.
            Log::info(
                "Descubrimiento SNMP no disponible para la OLT {$olt->name} "
                . "({$e->getMessage()}); se deducen los puertos de las ONTs conectadas."
            );
        }

        $enUso = Ont::where('olt_id', $olt->id)
            ->select('slot', 'port')
            ->distinct()
            ->get();

        $existentes = PonPort::where('olt_id', $olt->id)
            ->get()
            ->keyBy(fn (PonPort $p) => "{$p->frame}/{$p->slot}/{$p->port}");

        $creados = 0;

        foreach ($enUso as $fila) {
            $clave = "0/{$fila->slot}/{$fila->port}";

            if ($existentes->has($clave)) {
                continue;
            }

            PonPort::create([
                'optical_network_id' => $red->id,
                'olt_id' => $olt->id,
                'frame' => 0,
                'slot' => (int) $fila->slot,
                'port' => (int) $fila->port,
                'description' => 'Detectado desde las ONTs conectadas',
            ]);

            $creados++;
        }

        if ($creados > 0) {
            $this->auditLogger->action(
                'pon_ports.detected',
                sprintf('Detectó %d puerto(s) PON en uso en la OLT %s', $creados, $olt->name),
                ['olt' => $olt->name, 'red' => $red->name, 'creados' => $creados],
                $olt,
                'red',
            );
        }

        return $creados;
    }
}
