<?php

namespace App\Services;

use App\Billing\Enums\InvoiceStatus;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta filtrable del listado de contratos.
 *
 * POR QUÉ EXISTE
 * --------------
 * El listado tenía un solo filtro: un campo y un valor. Servía para
 * "búsqueme a Juan", pero no para las preguntas con las que de verdad
 * se trabaja: *"los contratos de tal barrio con dos meses de mora"*,
 * *"los activados en marzo que todavía no tienen ONT"*, *"los del
 * estrato 1 y 2 suspendidos"*.
 *
 * Aquí todos los filtros son ACUMULABLES: los que vengan se aplican a
 * la vez sobre la misma consulta. Y es la ÚNICA fuente de verdad —la
 * pantalla y la exportación la comparten—, de modo que el Excel que
 * se descarga contiene exactamente lo que se está viendo.
 *
 * SOBRE LOS AGREGADOS
 * -------------------
 * El saldo y el número de facturas vencidas se calculan con
 * subconsultas dentro de la MISMA consulta (`withSum` / `withCount`),
 * no recorriendo los contratos uno por uno. Con dos mil contratos la
 * diferencia es entre una consulta y cuatro mil.
 */
class ContractQuery
{
    /**
     * Catálogo de columnas del listado.
     *
     * `defecto` marca las que se ven sin tocar nada; el resto se
     * activan desde el selector. Se define aquí y no en la vista para
     * que la exportación pueda ofrecer exactamente las mismas.
     *
     * @return array<string, array{titulo: string, grupo: string, defecto: bool}>
     */
    public static function columnas(): array
    {
        return [
            // ---- Identificación ----
            'contract_number' => ['titulo' => 'N.º contrato', 'grupo' => 'Contrato', 'defecto' => true],
            'client_identity' => ['titulo' => 'Identificación', 'grupo' => 'Cliente', 'defecto' => true],
            'client_name' => ['titulo' => 'Cliente', 'grupo' => 'Cliente', 'defecto' => true],
            'client_phone' => ['titulo' => 'Teléfono', 'grupo' => 'Cliente', 'defecto' => true],
            'client_email' => ['titulo' => 'Correo', 'grupo' => 'Cliente', 'defecto' => false],
            'client_type' => ['titulo' => 'Tipo de cliente', 'grupo' => 'Cliente', 'defecto' => false],

            // ---- Ubicación ----
            'address' => ['titulo' => 'Dirección', 'grupo' => 'Ubicación', 'defecto' => true],
            'neighborhood' => ['titulo' => 'Barrio', 'grupo' => 'Ubicación', 'defecto' => false],
            'municipality' => ['titulo' => 'Municipio', 'grupo' => 'Ubicación', 'defecto' => false],
            'department' => ['titulo' => 'Departamento', 'grupo' => 'Ubicación', 'defecto' => false],
            'home_type' => ['titulo' => 'Tipo de vivienda', 'grupo' => 'Ubicación', 'defecto' => false],
            'social_stratum' => ['titulo' => 'Estrato', 'grupo' => 'Ubicación', 'defecto' => false],
            'coordenadas' => ['titulo' => 'Coordenadas', 'grupo' => 'Ubicación', 'defecto' => false],

            // ---- Servicio ----
            'plan' => ['titulo' => 'Plan', 'grupo' => 'Servicio', 'defecto' => true],
            'status' => ['titulo' => 'Estado', 'grupo' => 'Servicio', 'defecto' => true],
            'activation_date' => ['titulo' => 'Activación', 'grupo' => 'Servicio', 'defecto' => true],
            'permanence_clause' => ['titulo' => 'Cláusula permanencia', 'grupo' => 'Servicio', 'defecto' => false],
            'created_at' => ['titulo' => 'Creado', 'grupo' => 'Servicio', 'defecto' => false],

            // ---- Cartera ----
            'saldo_pendiente' => ['titulo' => 'Saldo pendiente', 'grupo' => 'Cartera', 'defecto' => true],
            'facturas_pendientes' => ['titulo' => 'Facturas por pagar', 'grupo' => 'Cartera', 'defecto' => true],
            'saldo_a_favor' => ['titulo' => 'Saldo a favor', 'grupo' => 'Cartera', 'defecto' => false],

            // ---- Técnico ----
            'cpe_sn' => ['titulo' => 'Serial ONT (contrato)', 'grupo' => 'Técnico', 'defecto' => false],
            'ont_sn' => ['titulo' => 'Serial ONT (equipo)', 'grupo' => 'Técnico', 'defecto' => false],
            'ont_olt' => ['titulo' => 'OLT', 'grupo' => 'Técnico', 'defecto' => false],
            'ont_potencia' => ['titulo' => 'Potencia ONT', 'grupo' => 'Técnico', 'defecto' => false],
            'nap_port' => ['titulo' => 'NAP y puerto (anotación)', 'grupo' => 'Técnico', 'defecto' => false],
            // Caja documentada en el módulo de redes. Se ofrece aparte
            // de la anotación de texto porque son cosas distintas: una
            // es lo que alguien escribió, la otra es dónde está de
            // verdad instalado el contrato.
            'nap_caja' => ['titulo' => 'Caja NAP', 'grupo' => 'Técnico', 'defecto' => false],
            'nap_numero' => ['titulo' => 'Puerto de la caja', 'grupo' => 'Técnico', 'defecto' => false],
            'nap_zona' => ['titulo' => 'Zona de red', 'grupo' => 'Técnico', 'defecto' => false],
            'nap_pon' => ['titulo' => 'Puerto PON', 'grupo' => 'Técnico', 'defecto' => false],
            'user_pppoe' => ['titulo' => 'Usuario PPPoE', 'grupo' => 'Técnico', 'defecto' => false],
            'password_pppoe' => ['titulo' => 'Clave PPPoE', 'grupo' => 'Técnico', 'defecto' => false],
            'ssid_wifi' => ['titulo' => 'SSID wifi', 'grupo' => 'Técnico', 'defecto' => false],
            'password_wifi' => ['titulo' => 'Clave wifi', 'grupo' => 'Técnico', 'defecto' => false],

            // ---- Otros ----
            'comment' => ['titulo' => 'Observaciones', 'grupo' => 'Otros', 'defecto' => false],
            'created_by' => ['titulo' => 'Registrado por', 'grupo' => 'Otros', 'defecto' => false],
        ];
    }

    /** Claves de las columnas que se muestran si nadie elige nada. */
    public static function columnasPorDefecto(): array
    {
        return array_keys(array_filter(self::columnas(), fn ($c) => $c['defecto']));
    }

    /**
     * Construye la consulta aplicando TODOS los filtros recibidos.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function construir(array $filtros, ?int $branchId = null): Builder
    {
        $pagables = InvoiceStatus::payable();

        $query = Contract::query()
            ->with([
                'client', 'plan', 'user', 'ont.olt',
                // La caja se precarga aunque sus columnas estén ocultas
                // por defecto: si el usuario las activa, sin esto cada
                // fila dispararía cuatro consultas más.
                'napPort.napBox.zone', 'napPort.napBox.ponPort',
            ])
            // Saldo y facturas por pagar como subconsultas: recorrer
            // los contratos para sumarlos sería un N+1 gigante.
            ->withSum(
                ['invoices as saldo_pendiente' => fn ($q) => $q->whereIn('status', $pagables)],
                'pending_invoice_amount',
            )
            ->withCount([
                'invoices as facturas_pendientes' => fn ($q) => $q
                    ->whereIn('status', $pagables)
                    ->where('pending_invoice_amount', '>', 0),
            ]);

        $branchId ??= session('branch_id');

        if ($branchId) {
            $query->where('contracts.branch_id', $branchId);
        }

        $this->aplicarBusquedaLibre($query, $filtros);
        $this->aplicarListas($query, $filtros);
        $this->aplicarTexto($query, $filtros);
        $this->aplicarFechas($query, $filtros);
        $this->aplicarEquipos($query, $filtros);
        $this->aplicarCartera($query, $filtros);

        return $query->orderBy('contracts.contract_number');
    }

    /**
     * Un solo cuadro que busca por lo que sea: número de contrato,
     * cédula, nombre, dirección, usuario PPPoE o serial.
     *
     * Es lo que se usa el 90% de las veces —"búsqueme a este"— y por
     * eso va aparte de los filtros finos.
     */
    private function aplicarBusquedaLibre(Builder $query, array $filtros): void
    {
        $termino = trim((string) ($filtros['q'] ?? ''));

        if ($termino === '') {
            return;
        }

        $like = '%' . $termino . '%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('contracts.contract_number', 'like', $like)
                ->orWhere('contracts.address', 'like', $like)
                ->orWhere('contracts.user_pppoe', 'like', $like)
                ->orWhere('contracts.cpe_sn', 'like', $like)
                ->orWhereHas('client', function ($c) use ($like) {
                    $c->where('identity_number', 'like', $like)
                        ->orWhereRaw("CONCAT(name, ' ', last_name) LIKE ?", [$like])
                        ->orWhere('number_phone', 'like', $like);
                });
        });
    }

    /** Filtros de selección múltiple (estado, plan, estrato…). */
    private function aplicarListas(Builder $query, array $filtros): void
    {
        foreach ([
            'status' => 'contracts.status',
            'plan_id' => 'contracts.plan_id',
            'social_stratum' => 'contracts.social_stratum',
            'home_type' => 'contracts.home_type',
        ] as $filtro => $columna) {
            $valores = array_filter((array) ($filtros[$filtro] ?? []), fn ($v) => $v !== '' && $v !== null);

            if ($valores) {
                $query->whereIn($columna, $valores);
            }
        }
    }

    /** Filtros de texto parcial sobre columnas del contrato. */
    private function aplicarTexto(Builder $query, array $filtros): void
    {
        foreach ([
            'neighborhood' => 'contracts.neighborhood',
            'address' => 'contracts.address',
            'municipality' => 'contracts.municipality',
            'department' => 'contracts.department',
        ] as $filtro => $columna) {
            $valor = trim((string) ($filtros[$filtro] ?? ''));

            if ($valor !== '') {
                $query->where($columna, 'like', '%' . $valor . '%');
            }
        }
    }

    /** Rangos de fecha de activación y de creación. */
    private function aplicarFechas(Builder $query, array $filtros): void
    {
        foreach ([
            'activation' => 'contracts.activation_date',
            'created' => 'contracts.created_at',
        ] as $prefijo => $columna) {
            if (!empty($filtros[$prefijo . '_from'])) {
                $query->whereDate($columna, '>=', $filtros[$prefijo . '_from']);
            }

            if (!empty($filtros[$prefijo . '_to'])) {
                $query->whereDate($columna, '<=', $filtros[$prefijo . '_to']);
            }
        }
    }

    /**
     * Filtros por equipos: con ONT / sin ONT, con cuenta / sin cuenta.
     *
     * "Sin ONT" es la consulta con la que se detecta lo que quedó a
     * medias: contratos activos que nunca se instalaron del todo.
     */
    private function aplicarEquipos(Builder $query, array $filtros): void
    {
        if (($filtros['has_ont'] ?? '') === 'si') {
            $query->whereHas('ont');
        } elseif (($filtros['has_ont'] ?? '') === 'no') {
            $query->whereDoesntHave('ont');
        }

        if (($filtros['has_pppoe'] ?? '') === 'si') {
            $query->whereHas('pppoeAccounts');
        } elseif (($filtros['has_pppoe'] ?? '') === 'no') {
            $query->whereDoesntHave('pppoeAccounts');
        }

        if (($filtros['permanence_clause'] ?? '') !== '') {
            $query->where('contracts.permanence_clause', $filtros['permanence_clause']);
        }

        // Todos los clientes de una caja: es la consulta que se hace
        // cuando se va a intervenir esa caja y hay que avisar, o cuando
        // varios reportan falla y se sospecha del mismo punto.
        if (!empty($filtros['nap_box_id'])) {
            $query->whereHas(
                'napPort',
                fn ($q) => $q->where('nap_box_id', $filtros['nap_box_id']),
            );
        }

        // Los contratos sin caja documentada son la lista de trabajo
        // mientras se termina de levantar la red.
        if (($filtros['has_nap'] ?? '') === 'si') {
            $query->whereNotNull('contracts.nap_port_id');
        } elseif (($filtros['has_nap'] ?? '') === 'no') {
            $query->whereNull('contracts.nap_port_id');
        }

        // Y los que no están en el mapa son la lista de trabajo de la
        // georreferenciación: como es opcional, sin una forma de
        // sacarlos nadie sabría por dónde va ni cuánto falta.
        if (($filtros['has_location'] ?? '') === 'si') {
            $query->geolocated();
        } elseif (($filtros['has_location'] ?? '') === 'no') {
            $query->geolocated(false);
        }
    }

    /**
     * Filtros de cartera: cuánto debe y desde cuántos meses.
     *
     * `facturas_min` es el que responde "los que tienen dos meses de
     * saldo": cada factura abierta es un mes sin pagar.
     *
     * Van con HAVING y no con WHERE porque operan sobre los agregados
     * calculados arriba, que en SQL no existen todavía cuando se
     * evalúa el WHERE.
     */
    private function aplicarCartera(Builder $query, array $filtros): void
    {
        if (($filtros['saldo_min'] ?? '') !== '') {
            $query->having('saldo_pendiente', '>=', (float) $filtros['saldo_min']);
        }

        if (($filtros['saldo_max'] ?? '') !== '') {
            $query->having('saldo_pendiente', '<=', (float) $filtros['saldo_max']);
        }

        if (($filtros['facturas_min'] ?? '') !== '') {
            $query->having('facturas_pendientes', '>=', (int) $filtros['facturas_min']);
        }
    }

    /**
     * Valor de una columna del catálogo para un contrato dado.
     *
     * Lo usan la tabla y la exportación, de forma que una columna se
     * define UNA vez y sale igual en pantalla y en el Excel.
     */
    public static function valor(Contract $contrato, string $columna): string
    {
        $cliente = $contrato->client;

        return match ($columna) {
            'contract_number' => $contrato->numero_visible,
            'client_identity' => (string) ($cliente?->identity_number ?? ''),
            'client_name' => trim(($cliente?->name ?? '') . ' ' . ($cliente?->last_name ?? '')),
            'client_phone' => (string) ($cliente?->number_phone ?? ''),
            'client_email' => (string) ($cliente?->email ?? ''),
            'client_type' => (string) ($cliente?->type_client ?? ''),

            'address' => (string) $contrato->address,
            'neighborhood' => (string) $contrato->neighborhood,
            'municipality' => (string) $contrato->municipality,
            'department' => (string) $contrato->department,
            'home_type' => (string) $contrato->home_type,
            'social_stratum' => (string) $contrato->social_stratum,
            // Se dice "Sin ubicar" y no se deja vacío: en el Excel una
            // celda en blanco se confunde con un error de exportación.
            'coordenadas' => $contrato->isGeolocated()
                ? $contrato->latitude . ', ' . $contrato->longitude
                : 'Sin ubicar',

            'plan' => (string) ($contrato->plan?->name ?? ''),
            'status' => (string) $contrato->status,
            'activation_date' => $contrato->activation_date
                ? \Illuminate\Support\Carbon::parse($contrato->activation_date)->format('d/m/Y')
                : '',
            'permanence_clause' => $contrato->permanence_clause ? 'Sí' : 'No',
            'created_at' => $contrato->created_at?->format('d/m/Y') ?? '',

            'saldo_pendiente' => number_format((float) ($contrato->saldo_pendiente ?? 0), 2, ',', '.'),
            'facturas_pendientes' => (string) ($contrato->facturas_pendientes ?? 0),
            'saldo_a_favor' => number_format($contrato->saldoAFavor(), 2, ',', '.'),

            'cpe_sn' => (string) $contrato->cpe_sn,
            'ont_sn' => (string) ($contrato->ont?->sn ?? ''),
            'ont_olt' => (string) ($contrato->ont?->olt?->name ?? ''),
            'ont_potencia' => $contrato->ont?->rx_power ? $contrato->ont->rx_power . ' dBm' : '',
            'nap_port' => (string) $contrato->nap_port,
            'nap_caja' => (string) ($contrato->napPort?->napBox?->code ?? ''),
            'nap_numero' => $contrato->napPort ? (string) $contrato->napPort->number : '',
            'nap_zona' => (string) ($contrato->napPort?->napBox?->zone?->name ?? ''),
            'nap_pon' => (string) ($contrato->napPort?->napBox?->ponPort?->etiqueta ?? ''),
            'user_pppoe' => (string) $contrato->user_pppoe,
            'password_pppoe' => (string) $contrato->password_pppoe,
            'ssid_wifi' => (string) $contrato->ssid_wifi,
            'password_wifi' => (string) $contrato->password_wifi,

            'comment' => (string) $contrato->comment,
            'created_by' => trim(($contrato->user?->name ?? '') . ' ' . ($contrato->user?->last_name ?? '')),

            default => '',
        };
    }

    /**
     * Deja solo las columnas que existen en el catálogo, en su orden.
     *
     * Se filtra contra el catálogo a propósito: las claves llegan del
     * navegador y sin esto se podría pedir cualquier cosa.
     *
     * @param  array<int, string>|null  $elegidas
     * @return array<int, string>
     */
    public static function columnasValidas(?array $elegidas): array
    {
        if (empty($elegidas)) {
            return self::columnasPorDefecto();
        }

        return array_values(array_intersect(array_keys(self::columnas()), $elegidas));
    }
}
