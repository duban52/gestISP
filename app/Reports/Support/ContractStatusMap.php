<?php

namespace App\Reports\Support;

use App\Billing\Enums\ContractStatus;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Unifica los estados de contrato para los informes.
 *
 * En la base conviven DOS vocabularios distintos para lo mismo:
 *
 *  - La automatización de facturación escribe los valores del enum
 *    ContractStatus: Activo, Pre-suspensión, Suspendido,
 *    Por Reconexión, Por Instalar.
 *  - El formulario manual valida contra otra lista
 *    (App\Http\Requests\ContractRequest): Activo, Por Instalar,
 *    Cortado, Retirado, Por Reconectar.
 *
 * Solo coinciden en "Activo" y "Por Instalar". "Cortado" y
 * "Suspendido" significan lo mismo, igual que "Por Reconectar" y
 * "Por Reconexión"; y "Retirado" (la baja del cliente) no existe
 * en el enum.
 *
 * Contar sin unificarlos daría cifras equivocadas: los suspendidos
 * aparecerían repartidos en dos grupos y las bajas se perderían.
 *
 * Esta clase NO corrige la base: solo agrupa al leer. La
 * corrección de fondo es unificar el vocabulario en el origen.
 */
class ContractStatusMap
{
    /**
     * Grupo canónico => valores que se han encontrado en la base.
     */
    private const GRUPOS = [
        'activo' => [ContractStatus::Activo->value],
        'por_instalar' => [ContractStatus::PorInstalar->value],
        'en_riesgo' => [ContractStatus::PreSuspension->value],
        'suspendido' => [ContractStatus::Suspendido->value, 'Cortado'],
        'por_reconectar' => [ContractStatus::PorReconexion->value, 'Por Reconectar'],
        'retirado' => ['Retirado'],
    ];

    private const ETIQUETAS = [
        'activo' => 'Activos',
        'por_instalar' => 'Por instalar',
        'en_riesgo' => 'En riesgo de corte',
        'suspendido' => 'Suspendidos',
        'por_reconectar' => 'Por reconectar',
        'retirado' => 'Retirados',
        'otro' => 'Sin clasificar',
    ];

    /**
     * Colores de cada grupo, para que las gráficas del módulo
     * signifiquen siempre lo mismo.
     */
    private const COLORES = [
        'activo' => '#28a745',
        'por_instalar' => '#17a2b8',
        'en_riesgo' => '#ffc107',
        'suspendido' => '#dc3545',
        'por_reconectar' => '#fd7e14',
        'retirado' => '#6c757d',
        'otro' => '#adb5bd',
    ];

    /**
     * Estados que cuentan como cliente con servicio contratado.
     *
     * Un suspendido sigue siendo cliente (puede pagar y volver);
     * un retirado no. Es la base del "activos" del tablero.
     *
     * @return array<int, string>
     */
    public static function vigentes(): array
    {
        return array_merge(
            self::GRUPOS['activo'],
            self::GRUPOS['por_instalar'],
            self::GRUPOS['en_riesgo'],
            self::GRUPOS['suspendido'],
            self::GRUPOS['por_reconectar'],
        );
    }

    /**
     * Estados que representan una baja definitiva.
     *
     * @return array<int, string>
     */
    public static function bajas(): array
    {
        return self::GRUPOS['retirado'];
    }

    /**
     * Estados con servicio efectivamente prestándose.
     *
     * @return array<int, string>
     */
    public static function conServicio(): array
    {
        return array_merge(self::GRUPOS['activo'], self::GRUPOS['en_riesgo']);
    }

    /**
     * Expresión SQL que traduce contracts.status al grupo canónico.
     *
     * Se agrupa por esta expresión en lugar de por la columna, así
     * "Cortado" y "Suspendido" caen en la misma fila del informe.
     */
    public static function sqlGrupo(string $columna = 'status'): string
    {
        $casos = '';

        foreach (self::GRUPOS as $grupo => $valores) {
            $lista = collect($valores)
                ->map(fn ($v) => DB::getPdo()->quote($v))
                ->implode(', ');

            $casos .= " WHEN {$columna} IN ({$lista}) THEN " . DB::getPdo()->quote($grupo);
        }

        // Cualquier valor nuevo cae en "otro" y queda visible en el
        // informe, en vez de desaparecer sin que nadie lo note
        return "CASE{$casos} ELSE 'otro' END";
    }

    public static function etiqueta(string $grupo): string
    {
        return self::ETIQUETAS[$grupo] ?? ucfirst($grupo);
    }

    public static function color(string $grupo): string
    {
        return self::COLORES[$grupo] ?? self::COLORES['otro'];
    }

    /**
     * @return array<int, string>
     */
    public static function grupos(): array
    {
        return array_keys(self::GRUPOS);
    }

    /**
     * Estados presentes en la base que no encajan en ningún grupo.
     *
     * Alimenta el aviso de calidad de datos de la pantalla: si
     * alguien introduce un estado nuevo, el gerente se entera en
     * lugar de leer un total silenciosamente incompleto.
     *
     * @return array<int, string>
     */
    public static function estadosSinClasificar(?int $branchId = null): array
    {
        $conocidos = array_merge(...array_values(self::GRUPOS));

        return Contract::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('status')
            ->whereNotIn('status', $conocidos)
            ->distinct()
            ->pluck('status')
            ->all();
    }
}
