<?php

namespace App\Services;

use App\Models\PppoeAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Filtros y columnas del listado de cuentas PPPoE.
 *
 * Lo usan la pantalla Y la exportación. Es deliberado: si el Excel
 * armara su propia consulta, con el tiempo devolvería cosas distintas
 * de las que el usuario acaba de ver en pantalla, y nadie se daría
 * cuenta hasta que alguien tomara una decisión con el archivo
 * equivocado.
 */
class PppoeQuery
{
    /**
     * Construye la consulta con los filtros aplicados.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function construir(array $filtros, ?int $branchId = null): Builder
    {
        $branchId ??= session('branch_id');

        $query = PppoeAccount::query()
            ->where('branch_id', $branchId)
            // Nada de subconsultas contra el historial: el estado de
            // conexión y la última vez conectada viven en la propia
            // cuenta, mantenidos por el muestreador. Deducirlos de
            // pppoe_session_metrics costaba una subconsulta
            // correlacionada por fila sobre millones de registros, y la
            // pantalla tardaba veinte segundos en abrir.
            ->with(['router', 'contract.client']);

        $this->aplicarBusqueda($query, $filtros);

        if (!empty($filtros['router_id'])) {
            $query->where('router_id', $filtros['router_id']);
        }

        // El estado ADMINISTRATIVO: habilitada o cortada. Es distinto de
        // estar conectada, que depende de si el cliente tiene el equipo
        // encendido.
        if (($filtros['estado'] ?? '') === 'activa') {
            $query->where('disabled', false);
        } elseif (($filtros['estado'] ?? '') === 'suspendida') {
            $query->where('disabled', true);
        }

        if (($filtros['contrato'] ?? '') === 'si') {
            $query->whereNotNull('contract_id');
        } elseif (($filtros['contrato'] ?? '') === 'no') {
            $query->whereNull('contract_id');
        }

        if (!empty($filtros['profile'])) {
            $query->where('profile', $filtros['profile']);
        }

        // Ahora sí se puede filtrar en SQL, que es donde debe estar:
        // antes había que traerse todo y filtrarlo en memoria porque el
        // dato vivía en el historial.
        $conexion = $filtros['conexion'] ?? '';

        if ($conexion === 'conectada') {
            $query->where('connected', true);
        } elseif ($conexion === 'desconectada') {
            $query->where('connected', false)->whereNotNull('last_polled_at');
        } elseif ($conexion === 'nunca') {
            $query->whereNull('last_seen_at');
        }

        return $query->orderBy('username');
    }

    /**
     * Se conserva por compatibilidad con quien la llame.
     *
     * Ya no filtra nada: el estado de conexión pasó a ser una columna
     * de la cuenta y se resuelve en SQL dentro de construir(). Se deja
     * como paso vacío en vez de borrarla para no obligar a tocar todos
     * los llamadores de golpe.
     *
     * @param  Collection<int, PppoeAccount>  $cuentas
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, PppoeAccount>
     */
    public function filtrarEnMemoria(Collection $cuentas, array $filtros): Collection
    {
        return $cuentas;
    }

    private function aplicarBusqueda(Builder $query, array $filtros): void
    {
        $termino = trim((string) ($filtros['q'] ?? ''));

        if ($termino === '') {
            return;
        }

        $like = '%' . $termino . '%';

        // Un solo cuadro que busca por lo que se tiene a mano: el
        // usuario, el comentario, la IP, el número de contrato o el
        // nombre del cliente.
        $query->where(function (Builder $q) use ($like) {
            $q->where('username', 'like', $like)
                ->orWhere('comment', 'like', $like)
                ->orWhere('remote_address', 'like', $like)
                ->orWhereHas('contract', function ($c) use ($like) {
                    $c->where('contract_number', 'like', $like)
                        ->orWhereHas('client', function ($cl) use ($like) {
                            $cl->where('identity_number', 'like', $like)
                                ->orWhereRaw("CONCAT(name, ' ', last_name) LIKE ?", [$like]);
                        });
                });
        });
    }

    /**
     * Cifras de cabecera, calculadas sobre lo FILTRADO.
     *
     * Si alguien está mirando un router concreto, los números tienen
     * que ser los de ese router o no significan nada.
     *
     * @param  Collection<int, PppoeAccount>  $cuentas
     * @return array<string, int>
     */
    public function resumen(Collection $cuentas): array
    {
        $conectadas = $cuentas->where('connected', true);

        return [
            'total' => $cuentas->count(),
            'activas' => $cuentas->where('disabled', false)->count(),
            'suspendidas' => $cuentas->where('disabled', true)->count(),
            'conectadas' => $conectadas->count(),
            'sin_contrato' => $cuentas->whereNull('contract_id')->count(),
            // Cuentas habilitadas que NO están conectadas: es la lista
            // que interesa cuando alguien pregunta "¿cuántos clientes
            // tengo caídos ahora?".
            'caidas' => $cuentas
                ->where('disabled', false)
                ->where('connected', false)
                ->count(),
        ];
    }
}
