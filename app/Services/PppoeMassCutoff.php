<?php

namespace App\Services;

use App\Billing\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Cortes masivos de servicio sobre cuentas PPPoE.
 *
 * QUÉ ES UN CORTE AQUÍ
 * --------------------
 * Deshabilitar el secret en el Mikrotik y tumbar la sesión activa,
 * que es lo que deja al cliente sin navegar de inmediato. Sin lo
 * segundo el corte no se siente: el secret queda deshabilitado pero
 * la sesión en curso sigue viva hasta que el cliente reinicie.
 *
 * POR QUÉ EN DOS PASOS
 * --------------------
 * El servicio separa RESOLVER de EJECUTAR a propósito. Un corte
 * masivo es una operación que deja a decenas de clientes sin
 * servicio y no se puede deshacer con un botón: primero se muestra
 * exactamente a quién se va a cortar, y solo después se ejecuta.
 * Resolver no toca nada —ni la base ni el router—, así que se puede
 * revisar cuantas veces haga falta.
 *
 * QUÉ SE ACEPTA COMO IDENTIFICADOR
 * --------------------------------
 * Cada línea puede ser un NÚMERO DE CONTRATO (EGP000123) o un
 * USUARIO PPPoE (pepito.perez). Se prueba primero como contrato
 * porque es lo que tiene a mano quien hace la cartera. Un contrato
 * puede tener varias cuentas y en ese caso se cortan todas.
 *
 * EL CONTRATO QUEDA SUSPENDIDO
 * ----------------------------
 * Si la cuenta está vinculada a un contrato, éste pasa a
 * **Suspendido**. No es un adorno: es lo que hace que, cuando el
 * cliente pague, `PaymentRegistrar` genere sola la orden técnica de
 * reconexión y lo deje en "Por Reconexión". Sin eso el contrato
 * seguiría diciendo "Activo" con el servicio cortado, y al pagar no
 * se avisaría a nadie de que hay que ir a reconectarlo.
 *
 * Las cuentas SIN contrato (enlaces propios, cámaras) simplemente se
 * cortan: no hay estado que mover.
 */
class PppoeMassCutoff
{
    /**
     * Tope de identificadores por operación.
     *
     * Cada corte son dos llamadas HTTP al router, así que una lista
     * enorme se convierte en una petición eterna. Con este tope una
     * tanda grande se parte en varias, que además es más fácil de
     * revisar antes de ejecutar.
     */
    public const MAXIMO = 1000;

    /** Encabezados que se reconocen al leer un CSV o un Excel. */
    private const ENCABEZADOS = [
        'contrato', 'numerodecontrato', 'numerocontrato', 'ndecontrato', 'nrocontrato',
        'usuario', 'usuariopppoe', 'pppoe', 'cuenta', 'username', 'user',
    ];

    public function __construct(
        private readonly MikrotikApiService $mikrotik,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    // ==================== Entrada ====================

    /**
     * Separa el texto pegado en identificadores.
     *
     * Se acepta un identificador por línea, pero también separados
     * por comas, punto y coma o tabulaciones: quien copia de un Excel
     * o de un WhatsApp no siempre trae saltos de línea limpios.
     *
     * @return array<int, string>
     */
    public function identificadoresDesdeTexto(?string $texto): array
    {
        if (!$texto) {
            return [];
        }

        return $this->normalizarLista(preg_split('/[\r\n,;\t]+/', $texto) ?: []);
    }

    /**
     * Extrae los identificadores de un archivo .txt, .csv, .xlsx o .xls.
     *
     * Reglas de lectura:
     *  - Si la primera fila trae un encabezado reconocible (contrato,
     *    usuario, pppoe...), se usa ESA columna y se salta la fila.
     *  - Si no, se usa la primera columna y se lee desde la fila uno,
     *    que es como llega un .txt pelado.
     *
     * @return array<int, string>
     */
    public function identificadoresDesdeArchivo(UploadedFile $archivo): array
    {
        $ruta = $archivo->getRealPath();
        $extension = strtolower($archivo->getClientOriginalExtension());

        $filas = in_array($extension, ['txt', 'csv'], true)
            ? $this->leerTexto($ruta)
            : $this->leerHoja($ruta);

        if ($filas->isEmpty()) {
            return [];
        }

        $columna = $this->columnaConIdentificadores($filas->first());

        // Sin encabezado reconocible se lee todo desde la primera fila
        $datos = $columna === null ? $filas : $filas->skip(1);
        $indice = $columna ?? 0;

        return $this->normalizarLista(
            $datos->map(fn ($fila) => $fila[$indice] ?? null)->all()
        );
    }

    /**
     * Limpia, descarta vacíos, quita repetidos y aplica el tope.
     *
     * @param  array<int, mixed>  $valores
     * @return array<int, string>
     */
    private function normalizarLista(array $valores): array
    {
        $limpios = collect($valores)
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            // Un archivo exportado puede traer el mismo contrato dos
            // veces; cortarlo dos veces no rompe nada pero ensucia el
            // informe y duplica llamadas al router.
            ->unique(fn ($v) => mb_strtolower($v))
            ->values();

        if ($limpios->count() > self::MAXIMO) {
            throw new RuntimeException(sprintf(
                'La lista trae %d identificadores y el máximo por tanda es %d. Divídala en varias.',
                $limpios->count(),
                self::MAXIMO,
            ));
        }

        return $limpios->all();
    }

    // ==================== Resolución ====================

    /**
     * Busca a quién corresponde cada identificador, SIN tocar nada.
     *
     * Devuelve una fila por identificador con su estado:
     *   lista           → se puede cortar
     *   ya_suspendida   → todas sus cuentas ya están deshabilitadas
     *   sin_cuenta      → el contrato existe pero no tiene PPPoE
     *   otra_sucursal   → existe, pero no en la sucursal activa
     *   no_encontrado   → no corresponde a nada
     *
     * @param  array<int, string>  $identificadores
     * @return array<int, array<string, mixed>>
     */
    public function resolver(array $identificadores, int $branchId): array
    {
        if (empty($identificadores)) {
            return [];
        }

        $porContrato = $this->cuentasPorNumeroDeContrato($identificadores, $branchId);
        $porUsuario = $this->cuentasPorUsuario($identificadores, $branchId);
        $contratosSinCuenta = $this->contratosSinCuenta($identificadores, $branchId);

        return collect($identificadores)
            ->map(function (string $identificador) use ($porContrato, $porUsuario, $contratosSinCuenta, $branchId) {
                $clave = mb_strtolower($identificador);

                $cuentas = $porContrato->get($clave)
                    ?? $porUsuario->get($clave)
                    ?? collect();

                if ($cuentas->isNotEmpty()) {
                    return $this->filaConCuentas($identificador, $cuentas);
                }

                if ($contratosSinCuenta->has($clave)) {
                    return $this->fila($identificador, 'sin_cuenta',
                        'El contrato existe pero no tiene ninguna cuenta PPPoE vinculada.');
                }

                if ($this->existeEnOtraSucursal($identificador, $branchId)) {
                    return $this->fila($identificador, 'otra_sucursal',
                        'Pertenece a otra sucursal. Cámbiese a esa sucursal para cortarlo.');
                }

                return $this->fila($identificador, 'no_encontrado',
                    'No corresponde a ningún contrato ni a ninguna cuenta PPPoE.');
            })
            ->all();
    }

    /**
     * Resumen por estado, para el encabezado de la revisión.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<string, int>
     */
    public function resumen(array $filas): array
    {
        $conteo = collect($filas)->countBy('estado');

        return [
            'total' => count($filas),
            'lista' => $conteo->get('lista', 0),
            'ya_suspendida' => $conteo->get('ya_suspendida', 0),
            'sin_cuenta' => $conteo->get('sin_cuenta', 0),
            'otra_sucursal' => $conteo->get('otra_sucursal', 0),
            'no_encontrado' => $conteo->get('no_encontrado', 0),
            // Cuentas que se van a tocar (un contrato puede tener varias)
            'cuentas' => collect($filas)
                ->where('estado', 'lista')
                ->sum(fn ($fila) => count($fila['cuentas'])),
        ];
    }

    // ==================== Ejecución ====================

    /**
     * Corta el servicio de todo lo que esté en estado "lista".
     *
     * Se vuelve a resolver desde los identificadores en vez de
     * confiar en lo que mandó el navegador: entre la revisión y el
     * clic pudo cambiar algo, y lo que se corta debe salir siempre de
     * la base, no de un formulario que se puede manipular.
     *
     * Un error en una cuenta NO detiene el resto: si el router de una
     * sucursal está caído, las demás cuentas deben cortarse igual y
     * el informe dice cuáles fallaron.
     *
     * @param  array<int, string>  $identificadores
     * @return array{filas: array<int, array<string, mixed>>, cortadas: int, errores: int}
     */
    public function ejecutar(array $identificadores, int $branchId, ?int $userId = null): array
    {
        // Cada corte son dos llamadas al router; una tanda grande
        // supera de largo el tiempo de una petición normal.
        //
        // SOLO en peticiones web. En consola PHP corre SIN límite, y
        // set_time_limit() no lo amplía: lo IMPONE y reinicia el
        // contador. Llamarlo desde la suite de pruebas convertía el
        // proceso entero en uno de 600 segundos a partir de ese test,
        // y los cientos de pruebas que venían después morían con un
        // "Maximum execution time exceeded" que no tenía nada que ver
        // con ellas.
        if (!app()->runningInConsole()) {
            set_time_limit(600);
        }

        $filas = $this->resolver($identificadores, $branchId);

        // Los routers se resuelven una sola vez: en una tanda de 200
        // cuentas suelen ser dos o tres routers repetidos.
        $routers = Router::whereIn('id', collect($filas)
            ->where('estado', 'lista')
            ->flatMap(fn ($fila) => collect($fila['cuentas'])->pluck('router_id'))
            ->unique()
            ->all())
            ->get()
            ->keyBy('id');

        $cortadas = 0;
        $errores = 0;

        foreach ($filas as $i => $fila) {
            if ($fila['estado'] !== 'lista') {
                continue;
            }

            foreach ($fila['cuentas'] as $j => $datos) {
                $resultado = $this->cortarCuenta($datos, $routers, $userId);

                $filas[$i]['cuentas'][$j] = array_merge($datos, $resultado);

                $resultado['resultado'] === 'cortada' ? $cortadas++ : $errores++;
            }

            // El estado de la fila resume lo que pasó con sus cuentas
            $filas[$i]['estado'] = collect($filas[$i]['cuentas'])
                ->every(fn ($c) => ($c['resultado'] ?? null) === 'cortada')
                ? 'cortada'
                : 'error';
        }

        $this->auditarOperacion($filas, $cortadas, $errores);

        return [
            'filas' => $filas,
            'cortadas' => $cortadas,
            'errores' => $errores,
        ];
    }

    /**
     * Corta una cuenta concreta y deja constancia.
     *
     * @param  array<string, mixed>  $datos
     * @param  Collection<int, Router>  $routers
     * @return array{resultado: string, error?: string}
     */
    private function cortarCuenta(array $datos, Collection $routers, ?int $userId): array
    {
        $cuenta = PppoeAccount::find($datos['id']);
        $router = $routers->get($datos['router_id']);

        if (!$cuenta || !$router) {
            return ['resultado' => 'error', 'error' => 'La cuenta o su router ya no existen.'];
        }

        try {
            // setPppSecretState deshabilita el secret Y tumba la
            // sesión activa: sin lo segundo el cliente sigue
            // navegando hasta que reinicie el equipo.
            $this->mikrotik->setPppSecretState($router, $cuenta, true);
        } catch (\Throwable $e) {
            Log::error('Corte masivo: fallo al cortar una cuenta', [
                'cuenta' => $cuenta->username,
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);

            return ['resultado' => 'error', 'error' => $e->getMessage()];
        }

        $cuenta->update(['disabled' => true]);

        $this->suspenderContrato($cuenta);

        // Auditoría POR CUENTA: es la que responde "¿por qué me
        // cortaron?" cuando el cliente llama tres semanas después.
        $this->auditLogger->action(
            'pppoe.cut',
            sprintf(
                'Cortó el servicio de %s%s',
                $cuenta->username,
                $datos['contrato'] ? ' (contrato ' . $datos['contrato'] . ')' : '',
            ),
            [
                'usuario_pppoe' => $cuenta->username,
                'contrato' => $datos['contrato'],
                'cliente' => $datos['cliente'],
                'router' => $router->name,
                'origen' => 'corte_masivo',
            ],
            $cuenta,
            'red',
        );

        return ['resultado' => 'cortada'];
    }

    /**
     * Deja el contrato en Suspendido al cortarle el servicio.
     *
     * Se salta los que ya lo están para no reescribir lo mismo, y los
     * que están "Por Reconexión" —esos ya pagaron y esperan la visita
     * del técnico: devolverlos a Suspendido borraría esa señal.
     */
    private function suspenderContrato(PppoeAccount $cuenta): void
    {
        $contrato = $cuenta->contract;

        if (!$contrato) {
            return;
        }

        $estadosQueNoSeTocan = [
            ContractStatus::Suspendido->value,
            ContractStatus::PorReconexion->value,
        ];

        if (in_array($contrato->status, $estadosQueNoSeTocan, true)) {
            return;
        }

        $anterior = $contrato->status;

        $contrato->update(['status' => ContractStatus::Suspendido->value]);

        $this->auditLogger->action(
            'contracts.suspended',
            sprintf(
                'Suspendió el contrato %s por corte masivo (estaba %s)',
                $contrato->numero_visible,
                $anterior,
            ),
            [
                'contrato' => $contrato->numero_visible,
                'estado_anterior' => $anterior,
                'estado_nuevo' => ContractStatus::Suspendido->value,
                'usuario_pppoe' => $cuenta->username,
                'origen' => 'corte_masivo',
            ],
            $contrato,
            'contratos',
        );
    }

    /**
     * Registra la operación completa en la trazabilidad.
     *
     * Va aparte de los registros por cuenta: esta fila responde
     * "¿quién ordenó el corte del 3 de agosto y a cuántos?", y las
     * otras "¿a mí por qué me cortaron?".
     *
     * @param  array<int, array<string, mixed>>  $filas
     */
    private function auditarOperacion(array $filas, int $cortadas, int $errores): void
    {
        $noAplicadas = collect($filas)
            ->whereNotIn('estado', ['cortada', 'error'])
            ->pluck('identificador');

        $this->auditLogger->action(
            'pppoe.mass_cut',
            sprintf('Ejecutó un corte masivo: %d cuenta(s) cortada(s), %d con error', $cortadas, $errores),
            [
                'identificadores_recibidos' => count($filas),
                'cuentas_cortadas' => $cortadas,
                'cuentas_con_error' => $errores,
                'no_aplicados' => $noAplicadas->count(),
                // Se guardan los identificadores para poder
                // reconstruir la tanda; recortados si son muchos.
                'detalle' => collect($filas)
                    ->where('estado', 'cortada')
                    ->pluck('identificador')
                    ->take(200)
                    ->all(),
                'con_error' => collect($filas)
                    ->where('estado', 'error')
                    ->pluck('identificador')
                    ->take(200)
                    ->all(),
            ],
            null,
            'red',
        );
    }

    // ==================== Consultas ====================

    /**
     * Cuentas agrupadas por número de contrato (en minúsculas).
     *
     * @return Collection<string, Collection<int, PppoeAccount>>
     */
    private function cuentasPorNumeroDeContrato(array $identificadores, int $branchId): Collection
    {
        return PppoeAccount::where('branch_id', $branchId)
            ->whereHas('contract', fn ($q) => $q->whereIn('contract_number', $identificadores))
            ->with(['contract.client', 'router'])
            ->get()
            ->groupBy(fn (PppoeAccount $c) => mb_strtolower((string) $c->contract?->contract_number));
    }

    /**
     * Cuentas agrupadas por usuario PPPoE (en minúsculas).
     *
     * @return Collection<string, Collection<int, PppoeAccount>>
     */
    private function cuentasPorUsuario(array $identificadores, int $branchId): Collection
    {
        return PppoeAccount::where('branch_id', $branchId)
            ->whereIn('username', $identificadores)
            ->with(['contract.client', 'router'])
            ->get()
            ->groupBy(fn (PppoeAccount $c) => mb_strtolower($c->username));
    }

    /**
     * Contratos que existen pero no tienen ninguna cuenta PPPoE.
     * Se distinguen de los inexistentes porque el mensaje al operador
     * es muy distinto: aquí el contrato está bien, falta vincular.
     *
     * @return Collection<string, Contract>
     */
    private function contratosSinCuenta(array $identificadores, int $branchId): Collection
    {
        return Contract::where('branch_id', $branchId)
            ->whereIn('contract_number', $identificadores)
            ->whereDoesntHave('pppoeAccounts')
            ->get()
            ->keyBy(fn (Contract $c) => mb_strtolower((string) $c->contract_number));
    }

    /**
     * ¿El identificador existe, pero en otra sucursal?
     *
     * Se responde sí o no, sin decir en cuál ni de quién: el objetivo
     * es que el operador entienda por qué no lo encuentra, no darle
     * acceso a datos de otra sucursal.
     */
    private function existeEnOtraSucursal(string $identificador, int $branchId): bool
    {
        return PppoeAccount::where('branch_id', '!=', $branchId)
                ->where('username', $identificador)
                ->exists()
            || Contract::where('branch_id', '!=', $branchId)
                ->where('contract_number', $identificador)
                ->exists();
    }

    // ==================== Armado de filas ====================

    /**
     * @param  Collection<int, PppoeAccount>  $cuentas
     * @return array<string, mixed>
     */
    private function filaConCuentas(string $identificador, Collection $cuentas): array
    {
        $detalle = $cuentas->map(fn (PppoeAccount $cuenta) => [
            'id' => $cuenta->id,
            'router_id' => $cuenta->router_id,
            'username' => $cuenta->username,
            'router' => $cuenta->router?->name,
            'contrato' => $cuenta->contract?->numero_visible,
            'cliente' => $cuenta->contract?->client
                ? trim($cuenta->contract->client->name . ' ' . $cuenta->contract->client->last_name)
                : null,
            'disabled' => (bool) $cuenta->disabled,
        ])->values()->all();

        // Ya suspendidas: no es un error, simplemente no hay nada que
        // hacer. Se informan aparte para que el operador no crea que
        // se cortaron ahora.
        if ($cuentas->every(fn (PppoeAccount $c) => $c->disabled)) {
            return array_merge(
                $this->fila($identificador, 'ya_suspendida', 'Su servicio ya estaba cortado.'),
                ['cuentas' => $detalle],
            );
        }

        // De un contrato con varias cuentas solo se cortan las activas
        $porCortar = array_values(array_filter($detalle, fn ($c) => !$c['disabled']));

        return array_merge(
            $this->fila($identificador, 'lista', count($porCortar) > 1
                ? count($porCortar) . ' cuentas se cortarán.'
                : 'Lista para cortar.'),
            ['cuentas' => $porCortar],
        );
    }

    /** @return array<string, mixed> */
    private function fila(string $identificador, string $estado, string $mensaje): array
    {
        return [
            'identificador' => $identificador,
            'estado' => $estado,
            'mensaje' => $mensaje,
            'cuentas' => [],
        ];
    }

    // ==================== Lectura de archivos ====================

    /**
     * Lee un .txt o .csv como filas de celdas.
     *
     * No se usa la librería de Excel para estos: convierte los
     * valores y un identificador como "00123" perdería los ceros.
     *
     * @return Collection<int, array<int, string>>
     */
    private function leerTexto(string $ruta): Collection
    {
        $manejador = fopen($ruta, 'r');

        if ($manejador === false) {
            return collect();
        }

        $primeraLinea = fgets($manejador) ?: '';
        // BOM que agrega Excel al guardar en UTF-8
        $primeraLinea = preg_replace('/^\xEF\xBB\xBF/', '', $primeraLinea);

        $separador = substr_count($primeraLinea, ';') > substr_count($primeraLinea, ',') ? ';' : ',';

        rewind($manejador);

        $filas = collect();
        $primera = true;

        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            if ($primera) {
                $fila[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($fila[0] ?? ''));
                $primera = false;
            }

            $filas->push(array_map(fn ($v) => (string) $v, $fila));
        }

        fclose($manejador);

        return $filas->reject(fn ($fila) => collect($fila)->filter(fn ($v) => trim($v) !== '')->isEmpty())
            ->values();
    }

    /**
     * Lee un .xlsx o .xls como filas de celdas.
     *
     * @return Collection<int, array<int, string>>
     */
    private function leerHoja(string $ruta): Collection
    {
        $hoja = Excel::toCollection(null, $ruta)->first() ?? collect();

        return $hoja
            ->map(fn ($fila) => collect($fila)->map(fn ($v) => (string) $v)->all())
            ->reject(fn ($fila) => collect($fila)->filter(fn ($v) => trim($v) !== '')->isEmpty())
            ->values();
    }

    /**
     * Índice de la columna con los identificadores, o null si la
     * primera fila no parece un encabezado.
     *
     * @param  array<int, string>  $primeraFila
     */
    private function columnaConIdentificadores(array $primeraFila): ?int
    {
        foreach ($primeraFila as $indice => $titulo) {
            $normalizado = preg_replace('/[^a-z]/', '', mb_strtolower(
                iconv('UTF-8', 'ASCII//TRANSLIT', (string) $titulo) ?: (string) $titulo
            ));

            if (in_array($normalizado, self::ENCABEZADOS, true)) {
                return (int) $indice;
            }
        }

        return null;
    }
}
