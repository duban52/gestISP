<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Ont;
use App\Models\PppoeAccount;
use Illuminate\Support\Facades\Log;

/**
 * Diagnóstico rápido de la conexión de un contrato.
 *
 * PARA QUIÉN ES
 * -------------
 * Para quien contesta el teléfono. Cuando un cliente llama diciendo
 * "no tengo internet", la persona de oficina necesita responder en
 * treinta segundos y sin entrar a tres módulos distintos:
 *
 *   ¿Su cuenta PPPoE está conectada ahora mismo?
 *   ¿Con qué IP? (y poder abrir el equipo desde ahí)
 *   ¿La ONT está en línea y con qué potencia?
 *   ¿La ONT está deshabilitada por un corte?
 *
 * Con eso se distingue en el acto un corte por facturación, una fibra
 * partida, un equipo apagado en la casa del cliente o un problema de
 * credenciales — que son diagnósticos con respuestas muy distintas.
 *
 * POR QUÉ VA APARTE DE LA VISTA
 * -----------------------------
 * Consultar el Mikrotik es una llamada de red que puede tardar o
 * fallar. La ficha del contrato tiene que abrir al instante; este
 * diagnóstico lo pide el navegador después. Si el router no responde,
 * se dice, y el resto de la ficha sigue funcionando.
 */
class ContractDiagnostics
{
    public function __construct(
        private readonly MikrotikApiService $mikrotik,
    ) {
    }

    /**
     * Radiografía completa de la conexión.
     *
     * @return array{pppoe: array<string, mixed>|null, ont: array<string, mixed>|null, nap: array<string, mixed>|null}
     */
    public function para(Contract $contrato): array
    {
        return [
            'pppoe' => $this->pppoe($contrato),
            'ont' => $this->ont($contrato),
            'nap' => $this->nap($contrato),
        ];
    }

    /**
     * Caja NAP donde está instalado el contrato.
     *
     * Va en el diagnóstico y no solo en los datos técnicos porque
     * responde una pregunta distinta: si además de este cliente hay
     * otros caídos en la misma caja, el problema no está en la casa
     * sino en la caja o en el puerto PON que la alimenta.
     *
     * Es información de la base, no del equipo, así que nunca falla ni
     * demora; se entrega junto al resto para que la vista tenga todo
     * en una sola respuesta.
     *
     * @return array<string, mixed>|null  null si el contrato no está en una caja documentada
     */
    private function nap(Contract $contrato): ?array
    {
        $puerto = $contrato->napPort;

        if (!$puerto) {
            return null;
        }

        $caja = $puerto->napBox;

        if (!$caja) {
            return null;
        }

        $caja->loadMissing('ports.contract', 'ponPort.olt', 'zone');
        $ocupacion = $caja->ocupacion();

        return [
            'caja_id' => $caja->id,
            'caja' => $caja->code,
            'nombre' => $caja->name,
            'puerto' => $puerto->number,
            'direccion' => $caja->address,
            'zona' => $caja->zone?->name,
            'estado_caja' => $caja->estado_legible,
            'ocupados' => $ocupacion['ocupados'],
            'capacidad' => $ocupacion['capacidad'],
            'porcentaje' => $ocupacion['porcentaje'],
            // Cuántos clientes más dependen de la misma caja: es el
            // número que dice si vale la pena mandar un técnico.
            'otros_clientes' => max(0, $ocupacion['ocupados'] - 1),
            'olt' => $caja->ponPort?->olt?->name,
            'puerto_pon' => $caja->ponPort?->etiqueta,
            'url' => route('naps.show', $caja->id),
        ];
    }

    /**
     * Estado de la cuenta PPPoE y de su sesión activa.
     *
     * @return array<string, mixed>|null  null si el contrato no tiene cuenta
     */
    private function pppoe(Contract $contrato): ?array
    {
        /** @var PppoeAccount|null $cuenta */
        $cuenta = $contrato->pppoeAccounts()->with('router')->first();

        if (!$cuenta) {
            return null;
        }

        $base = [
            'id' => $cuenta->id,
            'usuario' => $cuenta->username,
            'perfil' => $cuenta->profile,
            'router' => $cuenta->router?->name,
            // Deshabilitada = cortada a propósito. Es la primera
            // explicación que hay que descartar (o confirmar).
            'habilitada' => !$cuenta->disabled,
            'conectada' => false,
            'ip' => null,
            'uptime' => null,
            'consulta_ok' => false,
            'mensaje' => null,
        ];

        if (!$cuenta->router) {
            return array_merge($base, ['mensaje' => 'La cuenta no tiene router asignado.']);
        }

        try {
            $sesion = $this->mikrotik->getActiveSession($cuenta->router, $cuenta->username);
        } catch (\Throwable $e) {
            Log::warning('Diagnóstico de contrato: el router no respondió', [
                'contrato' => $contrato->numero_visible,
                'router' => $cuenta->router->name,
                'error' => $e->getMessage(),
            ]);

            return array_merge($base, [
                'mensaje' => 'No se pudo consultar el router: ' . $e->getMessage(),
            ]);
        }

        return array_merge($base, [
            'consulta_ok' => true,
            'conectada' => $sesion !== null,
            'ip' => $sesion['address'] ?? null,
            'uptime' => $sesion['uptime'] ?? null,
            'caller_id' => $sesion['caller_id'] ?? null,
        ]);
    }

    /**
     * Estado de la ONT según lo último que se sabe.
     *
     * No se consulta la OLT: la potencia y el estado los mantienen al
     * día `onts:poll` y `onts:sync-power`, y preguntarle al equipo en
     * cada llamada de soporte añadiría medio minuto de espera por una
     * lectura que en la práctica es la misma.
     *
     * @return array<string, mixed>|null  null si el contrato no tiene ONT
     */
    private function ont(Contract $contrato): ?array
    {
        /** @var Ont|null $ont */
        $ont = $contrato->ont()->with('olt')->first();

        if (!$ont) {
            return null;
        }

        $potencia = $ont->rx_power !== null && $ont->rx_power !== ''
            ? (float) $ont->rx_power
            : null;

        $banda = OltStatistics::bandaDe($potencia);

        return [
            'id' => $ont->id,
            'sn' => $ont->sn,
            'olt' => $ont->olt?->name,
            'ubicacion' => "{$ont->slot}/{$ont->port}/{$ont->onu_id}",
            'en_linea' => (int) $ont->status === 1,
            // Deshabilitada por nosotros: igual que en PPPoE, es lo
            // primero que hay que descartar antes de culpar a la fibra.
            'habilitada' => $ont->admin_enabled !== false,
            'potencia' => $potencia,
            'banda' => $banda,
            'banda_etiqueta' => $this->etiquetaDeBanda($banda),
            'banda_color' => $this->colorDeBanda($banda),
            'medida' => $ont->updated_at?->diffForHumans(),
            'vlan' => $ont->vlan,
            'descripcion' => $ont->description,
        ];
    }

    /** Nombre legible de la banda de potencia. */
    private function etiquetaDeBanda(?string $banda): ?string
    {
        return match ($banda) {
            'saturacion' => 'Saturación',
            'optima' => 'Óptima',
            'aceptable' => 'Aceptable',
            'debil' => 'Débil',
            'critica' => 'Crítica',
            default => null,
        };
    }

    /** Color del badge según la banda. */
    private function colorDeBanda(?string $banda): string
    {
        return match ($banda) {
            'optima' => 'success',
            'aceptable' => 'info',
            'debil' => 'warning',
            'saturacion', 'critica' => 'danger',
            default => 'secondary',
        };
    }
}
