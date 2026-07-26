<?php

namespace App\Services\Import;

use App\Billing\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\ContractNumberGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Importación de clientes y contratos desde un archivo único.
 *
 * Al migrar desde otro software toda la información viene mezclada en
 * una sola hoja: los datos de la persona y los del servicio que tiene
 * contratado. Este servicio la separa y la deja donde corresponde:
 * una fila del archivo produce UN cliente y UN contrato.
 *
 * Decisiones que conviene conocer:
 *
 *  - El cliente se identifica por su documento DENTRO de la sucursal.
 *    Si ya existe, no se duplica: se le agrega el contrato nuevo (un
 *    cliente puede tener varios servicios).
 *  - Si el archivo trae el número de contrato del sistema anterior, se
 *    respeta tal cual y se adelanta el consecutivo de la sucursal para
 *    que los contratos futuros no lo repitan.
 *  - El saldo pendiente NO se ignora ni se mete a mano en la
 *    contabilidad: se convierte en una factura de "Saldo migrado" que
 *    entra al circuito normal de cobro, y se deja un comentario en el
 *    contrato explicando su origen.
 *
 * Cada fila se procesa en su propia transacción: una fila con datos
 * malos se reporta y se salta, sin arrastrar a las demás.
 */
class ClientContractImporter
{
    /**
     * Marca del período de las facturas de saldo migrado.
     *
     * No es un mes real a propósito. La facturación mensual busca
     * duplicados por (contrato, período): si el saldo migrado usara el
     * mes en curso, el contrato se quedaría SIN su factura del mes.
     * Además, al ser único por contrato, impide importar dos veces el
     * mismo saldo.
     */
    public const PERIODO_MIGRACION = 'MIGRACION';

    /** Nombre con el que aparece este tipo de factura. */
    public const TIPO_MIGRACION = 'Saldo migrado';

    public function __construct(
        private readonly ContractNumberGenerator $numerador,
    ) {
    }

    /**
     * Equivalencias de encabezados. La clave es el nombre normalizado
     * (sin acentos, minúsculas, sin espacios) que puede traer el
     * archivo; el valor es el campo del sistema.
     *
     * @return array<string, string>
     */
    public static function equivalencias(): array
    {
        return [
            // ---- Cliente ----
            'documento' => 'identity_number',
            'identificacion' => 'identity_number',
            'numerodedocumento' => 'identity_number',
            'numerodeidentificacion' => 'identity_number',
            'cedula' => 'identity_number',
            'nit' => 'identity_number',
            'tipodocumento' => 'type_document',
            'tipodedocumento' => 'type_document',
            'nombre' => 'name',
            'nombres' => 'name',
            'apellido' => 'last_name',
            'apellidos' => 'last_name',
            'tipocliente' => 'type_client',
            'tipodecliente' => 'type_client',
            'telefono' => 'number_phone',
            'celular' => 'number_phone',
            'telefonoadicional' => 'aditional_phone',
            'telefono2' => 'aditional_phone',
            'correo' => 'email',
            'email' => 'email',
            'correoelectronico' => 'email',
            'fechanacimiento' => 'birthday',

            // ---- Contrato ----
            'contrato' => 'contract_number',
            'numerocontrato' => 'contract_number',
            'numerodecontrato' => 'contract_number',
            'codigo' => 'contract_number',
            'plan' => 'plan',
            'planinternet' => 'plan',
            'servicio' => 'plan',
            'direccion' => 'address',
            'barrio' => 'neighborhood',
            'municipio' => 'municipality',
            'ciudad' => 'municipality',
            'departamento' => 'department',
            'estado' => 'status',
            'estrato' => 'social_stratum',
            'tipovivienda' => 'home_type',
            'fechaactivacion' => 'activation_date',
            'fechadeactivacion' => 'activation_date',
            'fechainstalacion' => 'activation_date',
            'usuariopppoe' => 'user_pppoe',
            'usuario' => 'user_pppoe',
            'clavepppoe' => 'password_pppoe',
            'passwordpppoe' => 'password_pppoe',
            'serial' => 'cpe_sn',
            'serialont' => 'cpe_sn',
            'sn' => 'cpe_sn',
            'ssid' => 'ssid_wifi',
            'ssidwifi' => 'ssid_wifi',
            'clavewifi' => 'password_wifi',
            'passwordwifi' => 'password_wifi',
            'nap' => 'nap_port',
            'puertonap' => 'nap_port',

            // ---- Saldo ----
            'saldo' => 'saldo',
            'saldopendiente' => 'saldo',
            'deuda' => 'saldo',
            'saldoanterior' => 'saldo',
            'montopendiente' => 'saldo',
        ];
    }

    /**
     * Lee el archivo y devuelve lo que ocurriría, SIN escribir nada.
     *
     * @return array{filas: array, resumen: array, columnas: array}
     */
    public function previsualizar(string $rutaArchivo, int $branchId, int $limite = 20): array
    {
        $filas = $this->leerArchivo($rutaArchivo);

        $resumen = ['total' => 0, 'nuevos' => 0, 'existentes' => 0, 'con_saldo' => 0, 'saldo_total' => 0.0, 'errores' => 0];
        $muestra = [];
        $documentosVistos = [];

        foreach ($filas as $numero => $fila) {
            $resumen['total']++;

            $datos = $this->interpretarFila($fila, $branchId);

            if ($datos['errores']) {
                $resumen['errores']++;
            } else {
                $yaEstaba = isset($documentosVistos[$datos['cliente']['identity_number']])
                    || $datos['cliente_existente'] !== null;

                $yaEstaba ? $resumen['existentes']++ : $resumen['nuevos']++;
                $documentosVistos[$datos['cliente']['identity_number']] = true;

                if ($datos['saldo'] > 0) {
                    $resumen['con_saldo']++;
                    $resumen['saldo_total'] += $datos['saldo'];
                }
            }

            if (count($muestra) < $limite) {
                $muestra[] = ['linea' => $numero + 2] + $datos;
            }
        }

        return [
            'filas' => $muestra,
            'resumen' => $resumen,
            'columnas' => $filas->isNotEmpty() ? array_keys($filas->first()) : [],
        ];
    }

    /**
     * Importa de verdad.
     *
     * @return array{creados: int, clientes_nuevos: int, con_saldo: int, saldo_total: float, errores: array}
     */
    public function importar(string $rutaArchivo, int $branchId, bool $crearSaldos = true): array
    {
        $filas = $this->leerArchivo($rutaArchivo);

        $resultado = [
            'creados' => 0,
            'clientes_nuevos' => 0,
            'con_saldo' => 0,
            'saldo_total' => 0.0,
            'errores' => [],
        ];

        foreach ($filas as $numero => $fila) {
            $linea = $numero + 2; // +1 por el encabezado, +1 porque el índice arranca en 0

            $datos = $this->interpretarFila($fila, $branchId);

            if ($datos['errores']) {
                $resultado['errores'][] = ['linea' => $linea, 'motivos' => $datos['errores']];

                continue;
            }

            try {
                // Una transacción por fila: si una falla, las demás
                // ya importadas se conservan.
                DB::transaction(function () use ($datos, $branchId, $crearSaldos, &$resultado) {
                    $cliente = $datos['cliente_existente'];

                    if (!$cliente) {
                        $cliente = Client::create($datos['cliente'] + [
                            'branch_id' => $branchId,
                            'user_id' => Auth::id(),
                        ]);
                        $resultado['clientes_nuevos']++;
                    }

                    $contrato = $this->crearContrato($datos, $cliente, $branchId);
                    $resultado['creados']++;

                    if ($crearSaldos && $datos['saldo'] > 0) {
                        $this->registrarSaldoMigrado($contrato, $datos['saldo']);
                        $resultado['con_saldo']++;
                        $resultado['saldo_total'] += $datos['saldo'];
                    }
                });

            } catch (\Throwable $e) {
                Log::error('Error al importar la fila ' . $linea, ['error' => $e->getMessage()]);

                $resultado['errores'][] = [
                    'linea' => $linea,
                    'motivos' => ['No se pudo guardar: ' . $e->getMessage()],
                ];
            }
        }

        return $resultado;
    }

    /**
     * Crea el contrato respetando el número que traiga el archivo.
     */
    private function crearContrato(array $datos, Client $cliente, int $branchId): Contract
    {
        $contrato = Contract::create($datos['contrato'] + [
            'branch_id' => $branchId,
            'client_id' => $cliente->id,
            'user_id' => Auth::id(),
        ]);

        if (!empty($datos['contrato']['contract_number'])) {
            // Número heredado del sistema anterior: se adelanta el
            // consecutivo de la sucursal para no repetirlo después.
            $this->numerador->registrarNumeroExterno($branchId, $datos['contrato']['contract_number']);
        } else {
            $this->numerador->asignar($contrato);
        }

        return $contrato;
    }

    /**
     * Convierte el saldo que traía el cliente en una factura real y
     * lo deja explicado en el contrato.
     *
     * Se hace así, y no anotándolo en un campo suelto, para que el
     * dinero entre al circuito normal: aparece en el estado de cuenta,
     * suma en la deuda del contrato y se puede cobrar y abonar con el
     * mismo flujo de pagos de siempre.
     */
    private function registrarSaldoMigrado(Contract $contrato, float $saldo): Invoice
    {
        $hoy = Carbon::today();

        $factura = Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $contrato->branch_id,
            'user_id' => Auth::id(),
            'type' => self::TIPO_MIGRACION,
            'billed_period' => 'Saldo anterior migrado',
            'billed_period_short' => 'Migración',
            'billed_month_name' => 'Saldo anterior',
            'billed_year_month' => self::PERIODO_MIGRACION,
            'period_start' => $hoy,
            'period_end' => $hoy,
            'issue_date' => $hoy,
            'due_date' => $hoy,
            'subtotal' => $saldo,
            'discount' => 0,
            'tax' => 0,
            'total' => $saldo,
            'pending_invoice_amount' => $saldo,
            // Nace vencida: es deuda que el cliente ya arrastraba.
            'status' => InvoiceStatus::Vencida->value,
        ]);

        $contrato->comments()->create([
            'user_id' => Auth::id(),
            'body' => sprintf(
                'SALDO DE MIGRACIÓN: el contrato llegó desde el sistema anterior con un saldo pendiente de $%s. '
                . 'Se registró como la factura de "%s" para poder cobrarlo con el flujo normal. '
                . 'Importado el %s.',
                // Formato colombiano: punto para los miles
                number_format($saldo, 2, ',', '.'),
                self::TIPO_MIGRACION,
                $hoy->format('d/m/Y'),
            ),
        ]);

        return $factura;
    }

    /**
     * Interpreta una fila del archivo: separa cliente y contrato,
     * valida lo indispensable y reporta lo que falta.
     */
    private function interpretarFila(array $fila, int $branchId): array
    {
        $errores = [];

        $documento = $this->texto($fila['identity_number'] ?? null);
        $nombre = $this->texto($fila['name'] ?? null);

        if ($documento === null) {
            $errores[] = 'Falta el documento del cliente.';
        }

        if ($nombre === null) {
            $errores[] = 'Falta el nombre del cliente.';
        }

        // El plan puede venir por nombre o por id
        $plan = $this->resolverPlan($fila['plan'] ?? null, $branchId);

        if (!$plan) {
            $errores[] = 'No se encontró el plan indicado' .
                (isset($fila['plan']) && $fila['plan'] !== null ? ' ("' . $fila['plan'] . '").' : ' (columna vacía).');
        }

        $clienteExistente = $documento
            ? Client::where('branch_id', $branchId)->where('identity_number', $documento)->first()
            : null;

        $numeroContrato = $this->texto($fila['contract_number'] ?? null);

        if ($numeroContrato && Contract::where('contract_number', $numeroContrato)->exists()) {
            $errores[] = "El número de contrato {$numeroContrato} ya existe en el sistema.";
        }

        return [
            'errores' => $errores,
            'cliente_existente' => $clienteExistente,
            'cliente' => [
                'identity_number' => $documento,
                'type_document' => $this->texto($fila['type_document'] ?? null) ?? 'Cédula de ciudadanía',
                'name' => $nombre,
                'last_name' => $this->texto($fila['last_name'] ?? null) ?? '',
                'type_client' => $this->texto($fila['type_client'] ?? null) ?? 'Natural',
                'number_phone' => $this->texto($fila['number_phone'] ?? null) ?? '',
                'aditional_phone' => $this->texto($fila['aditional_phone'] ?? null),
                'email' => $this->texto($fila['email'] ?? null),
                'birthday' => $this->fecha($fila['birthday'] ?? null),
            ],
            'contrato' => [
                'contract_number' => $numeroContrato,
                'plan_id' => $plan?->id,
                'address' => $this->texto($fila['address'] ?? null) ?? '',
                'neighborhood' => $this->texto($fila['neighborhood'] ?? null) ?? '',
                'municipality' => $this->texto($fila['municipality'] ?? null),
                'department' => $this->texto($fila['department'] ?? null),
                'home_type' => $this->texto($fila['home_type'] ?? null) ?? 'Casa',
                'social_stratum' => $this->texto($fila['social_stratum'] ?? null),
                'status' => $this->texto($fila['status'] ?? null) ?? 'Activo',
                'activation_date' => $this->fecha($fila['activation_date'] ?? null),
                'user_pppoe' => $this->texto($fila['user_pppoe'] ?? null),
                'password_pppoe' => $this->texto($fila['password_pppoe'] ?? null),
                'cpe_sn' => $this->texto($fila['cpe_sn'] ?? null),
                'ssid_wifi' => $this->texto($fila['ssid_wifi'] ?? null),
                'password_wifi' => $this->texto($fila['password_wifi'] ?? null),
                'nap_port' => $this->texto($fila['nap_port'] ?? null),
            ],
            'plan' => $plan?->name,
            'saldo' => $this->dinero($fila['saldo'] ?? null),
        ];
    }

    /**
     * Busca el plan por nombre (sin distinguir mayúsculas) o por id.
     */
    private function resolverPlan(mixed $valor, int $branchId): ?Plan
    {
        $valor = $this->texto($valor);

        if ($valor === null) {
            return null;
        }

        $consulta = Plan::where('branch_id', $branchId);

        if (ctype_digit($valor)) {
            $porId = (clone $consulta)->find((int) $valor);

            if ($porId) {
                return $porId;
            }
        }

        return $consulta->whereRaw('LOWER(name) = ?', [mb_strtolower($valor)])->first()
            ?? Plan::where('branch_id', $branchId)->where('name', 'like', '%' . $valor . '%')->first();
    }

    /**
     * Lee el archivo y normaliza los encabezados a campos del sistema.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function leerArchivo(string $ruta)
    {
        $hoja = $this->esCsv($ruta)
            ? $this->leerCsv($ruta)
            : (Excel::toCollection(null, $ruta)->first() ?? collect());

        if ($hoja->isEmpty()) {
            return collect();
        }

        $equivalencias = self::equivalencias();

        // Primera fila: encabezados
        $encabezados = [];

        foreach ($hoja->first() as $indice => $titulo) {
            $clave = $this->normalizarEncabezado((string) $titulo);
            $encabezados[$indice] = $equivalencias[$clave] ?? null;
        }

        return $hoja->skip(1)
            ->map(function ($fila) use ($encabezados) {
                $datos = [];

                foreach ($encabezados as $indice => $campo) {
                    if ($campo !== null) {
                        $datos[$campo] = $fila[$indice] ?? null;
                    }
                }

                return $datos;
            })
            // Se descartan las filas totalmente vacías del final del archivo
            ->reject(fn ($fila) => collect($fila)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty())
            ->values();
    }

    private function esCsv(string $ruta): bool
    {
        return in_array(strtolower(pathinfo($ruta, PATHINFO_EXTENSION)), ['csv', 'txt'], true);
    }

    /**
     * Lee un CSV conservando los valores TAL CUAL vienen.
     *
     * No se delega en la librería de Excel a propósito: al leer un CSV
     * interpreta los números y convierte "45.000" en 45, con lo que un
     * saldo de cuarenta y cinco mil pesos se importaría como cuarenta
     * y cinco. Leyéndolo como texto, el importe se interpreta después
     * con las reglas de dinero (miles y decimales).
     *
     * Detecta el separador porque los archivos exportados desde Excel
     * en español usan punto y coma.
     *
     * @return \Illuminate\Support\Collection<int, array<int, string>>
     */
    private function leerCsv(string $ruta)
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
                $fila[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $fila[0]);
                $primera = false;
            }

            $filas->push($fila);
        }

        fclose($manejador);

        return $filas;
    }

    /**
     * "Número de Documento" → "numerodedocumento".
     */
    private function normalizarEncabezado(string $titulo): string
    {
        $titulo = mb_strtolower(trim($titulo));
        $titulo = strtr($titulo, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);

        return preg_replace('/[^a-z0-9]/', '', $titulo);
    }

    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : Str::limit($texto, 250, '');
    }

    /**
     * Convierte importes escritos de cualquier forma habitual:
     * "45.000", "45,000.50", "$ 45000".
     */
    private function dinero(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        // Solo los números REALES (los que trae un Excel) se toman tal
        // cual. Un texto como "45.000" no puede pasar por aquí aunque
        // PHP lo considere numérico: lo leería como cuarenta y cinco
        // en lugar de cuarenta y cinco mil.
        if (is_int($valor) || is_float($valor)) {
            return round(max((float) $valor, 0), 2);
        }

        $limpio = preg_replace('/[^\d,.\-]/', '', (string) $valor);

        // Con ambos separadores, el último es el decimal
        if (str_contains($limpio, ',') && str_contains($limpio, '.')) {
            $limpio = strrpos($limpio, ',') > strrpos($limpio, '.')
                ? str_replace(['.', ','], ['', '.'], $limpio)
                : str_replace(',', '', $limpio);
        } elseif (str_contains($limpio, ',')) {
            // Una sola coma: decimal si deja 1-2 dígitos detrás
            $decimales = strlen($limpio) - strrpos($limpio, ',') - 1;
            $limpio = $decimales <= 2 ? str_replace(',', '.', $limpio) : str_replace(',', '', $limpio);
        } elseif (substr_count($limpio, '.') >= 1) {
            $decimales = strlen($limpio) - strrpos($limpio, '.') - 1;
            if ($decimales === 3) {
                $limpio = str_replace('.', '', $limpio); // 45.000 = miles
            }
        }

        return round(max((float) $limpio, 0), 2);
    }

    /**
     * Acepta fechas de Excel (número de serie) y de texto.
     */
    private function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            try {
                return Carbon::createFromTimestamp(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float) $valor)
                )->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'd/m/y'] as $formato) {
            try {
                return Carbon::createFromFormat($formato, trim((string) $valor))->toDateString();
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }
}
