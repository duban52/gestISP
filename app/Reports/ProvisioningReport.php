<?php

namespace App\Reports;

use App\Models\Contract;
use App\Models\Ont;
use App\Models\PppoeAccount;
use App\Reports\Support\ContractStatusMap;
use App\Reports\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aprovisionamiento de red.
 *
 * Cuántos equipos hay realmente entregando servicio y si coinciden
 * con los contratos que se están facturando.
 *
 * La pregunta que responde y que ningún otro informe cubre es la
 * COBERTURA: un contrato activo sin ONT ni cuenta PPPoE es un
 * cliente al que se le factura sin que el sistema sepa por dónde
 * recibe el servicio; y al revés, un equipo sin contrato es
 * capacidad instalada que nadie está cobrando. Las dos situaciones
 * cuestan dinero y solo se ven cruzando las tablas.
 */
class ProvisioningReport
{
    /**
     * Tramos de potencia óptica de recepción, en dBm.
     *
     * Son los umbrales habituales de GPON: por debajo de -27 dBm el
     * enlace empieza a perder paquetes, y por encima de -8 dBm la
     * señal satura el receptor. Ambos extremos son problema.
     */
    private const TRAMOS_OPTICOS = [
        ['etiqueta' => 'Excelente (≥ -22 dBm)', 'min' => -22.0, 'max' => -8.0, 'color' => '#28a745'],
        ['etiqueta' => 'Buena (-22 a -25)', 'min' => -25.0, 'max' => -22.0, 'color' => '#8bc34a'],
        ['etiqueta' => 'Aceptable (-25 a -27)', 'min' => -27.0, 'max' => -25.0, 'color' => '#ffc107'],
        ['etiqueta' => 'Crítica (< -27 dBm)', 'min' => -60.0, 'max' => -27.0, 'color' => '#dc3545'],
        ['etiqueta' => 'Saturada (> -8 dBm)', 'min' => -8.0, 'max' => 10.0, 'color' => '#6f42c1'],
    ];

    public function __construct(
        private readonly ReportPeriod $period,
        private readonly ?int $branchId = null,
    ) {
    }

    /**
     * Indicadores del estado actual de la red.
     *
     * Son una foto de HOY, no del período: cuántos equipos hay
     * ahora mismo. El período se usa para las altas.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        $onts = $this->ontsQuery()->count();
        $ontsSinContrato = $this->ontsQuery()->whereNull('contract_id')->count();
        $ontsDeshabilitadas = $this->ontsQuery()->where('admin_enabled', false)->count();

        $pppoe = $this->pppoeQuery()->count();
        $pppoeDeshabilitadas = $this->pppoeQuery()->where('disabled', true)->count();
        $pppoeSinContrato = $this->pppoeQuery()->whereNull('contract_id')->count();

        $cobertura = $this->cobertura();

        return [
            'onts' => $onts,
            'onts_sin_contrato' => $ontsSinContrato,
            'onts_deshabilitadas' => $ontsDeshabilitadas,
            'onts_con_catv' => $this->ontsQuery()->where('catv_enabled', true)->count(),
            'pppoe' => $pppoe,
            'pppoe_activas' => $pppoe - $pppoeDeshabilitadas,
            'pppoe_deshabilitadas' => $pppoeDeshabilitadas,
            'pppoe_sin_contrato' => $pppoeSinContrato,
            'olts' => $this->ontsQuery()->distinct()->count('olt_id'),
            'routers' => $this->pppoeQuery()->distinct()->count('router_id'),
            'contratos_vigentes' => $cobertura['vigentes'],
            'contratos_sin_ont' => $cobertura['sin_ont'],
            'contratos_sin_pppoe' => $cobertura['sin_pppoe'],
            'contratos_sin_nada' => $cobertura['sin_nada'],
        ];
    }

    /**
     * Contratos vigentes cruzados con sus equipos.
     *
     * @return array<string, int>
     */
    public function cobertura(): array
    {
        $base = Contract::query()
            ->when($this->branchId, fn ($q) => $q->where('contracts.branch_id', $this->branchId))
            ->whereIn('contracts.status', ContractStatusMap::vigentes());

        $tieneOnt = fn ($q) => $q->select(DB::raw(1))->from('onts')
            ->whereColumn('onts.contract_id', 'contracts.id');

        $tienePppoe = fn ($q) => $q->select(DB::raw(1))->from('pppoe_accounts')
            ->whereColumn('pppoe_accounts.contract_id', 'contracts.id');

        return [
            'vigentes' => (int) (clone $base)->count(),
            'con_ont' => (int) (clone $base)->whereExists($tieneOnt)->count(),
            'sin_ont' => (int) (clone $base)->whereNotExists($tieneOnt)->count(),
            'con_pppoe' => (int) (clone $base)->whereExists($tienePppoe)->count(),
            'sin_pppoe' => (int) (clone $base)->whereNotExists($tienePppoe)->count(),
            // Ni fibra ni PPPoE: no hay registro de por dónde recibe
            // el servicio este cliente
            'sin_nada' => (int) (clone $base)
                ->whereNotExists($tieneOnt)
                ->whereNotExists($tienePppoe)
                ->count(),
        ];
    }

    /**
     * Altas de ONTs y cuentas PPPoE por período.
     *
     * @return array{labels: Collection, onts: Collection, pppoe: Collection}
     */
    public function series(): array
    {
        $onts = $this->period->completarSerie(
            $this->ontsQuery()
                ->whereBetween('onts.created_at', [$this->period->from, $this->period->to])
                ->selectRaw($this->period->sqlBucket('onts.created_at') . ' as periodo, COUNT(*) as total')
                ->groupBy('periodo')
                ->pluck('total', 'periodo')
        );

        $pppoe = $this->period->completarSerie(
            $this->pppoeQuery()
                ->whereBetween('pppoe_accounts.created_at', [$this->period->from, $this->period->to])
                ->selectRaw($this->period->sqlBucket('pppoe_accounts.created_at') . ' as periodo, COUNT(*) as total')
                ->groupBy('periodo')
                ->pluck('total', 'periodo')
        );

        return [
            'labels' => $this->period->etiquetas(),
            'onts' => $onts->values(),
            'pppoe' => $pppoe->values(),
        ];
    }

    /**
     * ONTs por OLT, con su ocupación y estado.
     *
     * @return Collection<int, array>
     */
    public function porOlt(): Collection
    {
        return $this->ontsQuery()
            ->join('olts', 'olts.id', '=', 'onts.olt_id')
            ->selectRaw('olts.name as olt, olts.ip_address as ip')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN onts.contract_id IS NULL THEN 1 ELSE 0 END) as sin_contrato')
            ->selectRaw('SUM(CASE WHEN onts.admin_enabled = 0 THEN 1 ELSE 0 END) as deshabilitadas')
            ->selectRaw('COUNT(DISTINCT CONCAT(onts.slot, "/", onts.port)) as puertos')
            ->selectRaw('AVG(onts.rx_power) as rx_promedio')
            ->groupBy('olts.id', 'olts.name', 'olts.ip_address')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'olt' => $f->olt,
                'ip' => $f->ip,
                'total' => (int) $f->total,
                'sin_contrato' => (int) $f->sin_contrato,
                'deshabilitadas' => (int) $f->deshabilitadas,
                'puertos' => (int) $f->puertos,
                'rx_promedio' => $f->rx_promedio !== null ? round((float) $f->rx_promedio, 2) : null,
            ]);
    }

    /**
     * Cuentas PPPoE por router.
     *
     * @return Collection<int, array>
     */
    public function porRouter(): Collection
    {
        return $this->pppoeQuery()
            ->join('routers', 'routers.id', '=', 'pppoe_accounts.router_id')
            ->selectRaw('routers.name as router, routers.ip_address as ip')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN pppoe_accounts.disabled = 1 THEN 1 ELSE 0 END) as deshabilitadas')
            ->selectRaw('SUM(CASE WHEN pppoe_accounts.contract_id IS NULL THEN 1 ELSE 0 END) as sin_contrato')
            ->groupBy('routers.id', 'routers.name', 'routers.ip_address')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'router' => $f->router,
                'ip' => $f->ip,
                'total' => (int) $f->total,
                'activas' => (int) $f->total - (int) $f->deshabilitadas,
                'deshabilitadas' => (int) $f->deshabilitadas,
                'sin_contrato' => (int) $f->sin_contrato,
            ]);
    }

    /**
     * Cuentas PPPoE por perfil de velocidad.
     *
     * Es la lectura comercial de la red: qué planes se están
     * entregando de verdad en los equipos.
     *
     * @return Collection<int, array>
     */
    public function porPerfil(): Collection
    {
        return $this->pppoeQuery()
            ->selectRaw('COALESCE(NULLIF(pppoe_accounts.profile, ""), "Sin perfil") as perfil')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN pppoe_accounts.disabled = 1 THEN 1 ELSE 0 END) as deshabilitadas')
            ->groupBy('perfil')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [
                'perfil' => $f->perfil,
                'total' => (int) $f->total,
                'activas' => (int) $f->total - (int) $f->deshabilitadas,
            ]);
    }

    /**
     * Distribución de la potencia óptica recibida por las ONTs.
     *
     * Se usa onts.rx_power, que es la última lectura guardada por el
     * sondeo SNMP. Las ONTs que nunca se han sondeado no tienen
     * valor y se informan aparte en vez de contarse como buenas.
     *
     * @return Collection<int, array{etiqueta: string, total: int, color: string}>
     */
    public function calidadOptica(): Collection
    {
        $valores = $this->ontsQuery()
            ->whereNotNull('rx_power')
            ->pluck('rx_power')
            ->map(fn ($v) => (float) $v);

        // Los tramos parten la escala sin solaparse: cada lectura
        // cae en uno y solo uno (mínimo incluido, máximo excluido)
        $filas = collect(self::TRAMOS_OPTICOS)->map(fn ($t) => [
            'etiqueta' => $t['etiqueta'],
            'color' => $t['color'],
            'total' => $valores->filter(fn ($v) => $v >= $t['min'] && $v < $t['max'])->count(),
        ]);

        $sinLectura = $this->ontsQuery()->whereNull('rx_power')->count();

        if ($sinLectura > 0) {
            $filas->push([
                'etiqueta' => 'Sin lectura SNMP',
                'color' => '#adb5bd',
                'total' => $sinLectura,
            ]);
        }

        return $filas->values();
    }

    /**
     * ONTs registradas sin contrato asociado.
     *
     * Suelen venir de la importación desde la OLT: están en el
     * equipo dando servicio pero nadie las ha vinculado a un cliente.
     *
     * @return Collection<int, array>
     */
    public function ontsHuerfanas(int $limite = 25): Collection
    {
        return $this->ontsQuery()
            ->leftJoin('olts', 'olts.id', '=', 'onts.olt_id')
            ->whereNull('onts.contract_id')
            ->selectRaw('onts.sn, onts.description, onts.slot, onts.port, onts.onu_id, onts.rx_power')
            ->selectRaw('olts.name as olt')
            ->orderBy('olts.name')
            ->orderBy('onts.slot')
            ->orderBy('onts.port')
            ->limit($limite)
            ->get()
            ->map(fn ($f) => [
                'sn' => $f->sn,
                'descripcion' => $f->description,
                'ubicacion' => "0/{$f->slot}/{$f->port}:{$f->onu_id}",
                'olt' => $f->olt,
                'rx_power' => $f->rx_power !== null ? round((float) $f->rx_power, 2) : null,
            ]);
    }

    private function ontsQuery()
    {
        return Ont::query()
            ->when($this->branchId, fn ($q) => $q->where('onts.branch_id', $this->branchId));
    }

    private function pppoeQuery()
    {
        return PppoeAccount::query()
            ->when($this->branchId, fn ($q) => $q->where('pppoe_accounts.branch_id', $this->branchId));
    }
}
