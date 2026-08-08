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
            // latestMetric da la última muestra del poller: de ahí sale
            // si está conectada ahora y con qué IP.
            ->with(['router', 'contract.client', 'latestMetric'])
            // La ÚLTIMA VEZ QUE ESTUVO CONECTADA va como subconsulta y
            // no como accesor: preguntarlo cuenta por cuenta sería un
            // N+1 de miles de consultas en un listado grande.
            ->withMax(
                ['metrics as ultima_conexion' => fn ($q) => $q->where('connected', true)],
                'measured_at',
            );

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

        return $query->orderBy('username');
    }

    /**
     * Filtros que no se pueden resolver en SQL.
     *
     * La conexión vive en la tabla de métricas y depende de la ÚLTIMA
     * muestra de cada cuenta; filtrarlo en SQL exigiría una subconsulta
     * correlacionada bastante fea para lo poco que aporta frente a
     * hacerlo sobre lo que ya se trajo.
     *
     * @param  Collection<int, PppoeAccount>  $cuentas
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, PppoeAccount>
     */
    public function filtrarEnMemoria(Collection $cuentas, array $filtros): Collection
    {
        $conexion = $filtros['conexion'] ?? '';

        if ($conexion === 'conectada') {
            return $cuentas->filter(fn (PppoeAccount $c) => (bool) $c->latestMetric?->connected)->values();
        }

        if ($conexion === 'desconectada') {
            return $cuentas->reject(fn (PppoeAccount $c) => (bool) $c->latestMetric?->connected)->values();
        }

        if ($conexion === 'nunca') {
            return $cuentas->filter(fn (PppoeAccount $c) => $c->ultima_conexion === null)->values();
        }

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
        $conectadas = $cuentas->filter(fn (PppoeAccount $c) => (bool) $c->latestMetric?->connected);

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
                ->reject(fn (PppoeAccount $c) => (bool) $c->latestMetric?->connected)
                ->count(),
        ];
    }
}
