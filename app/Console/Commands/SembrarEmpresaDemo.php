<?php

namespace App\Console\Commands;

use App\Billing\Enums\ContractStatus;
use App\Models\AffinityGroup;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\ContractNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Una empresa de mentira para probar el flujo real en producción.
 *
 * PARA QUÉ
 * --------
 * El comportamiento de la facturación con cientos de contratos —la
 * corrida mensual, la numeración, la mora, los cortes, la caja— no se
 * parece al de tres contratos de prueba en local. Esto crea una
 * empresa aparte, con su sucursal y sus clientes inventados, para
 * poder mirarlo en el servidor de verdad sin tocar a nadie real.
 *
 * POR QUÉ ES SEGURO EJECUTARLO EN PRODUCCIÓN
 * ------------------------------------------
 * 1. **Empresa aparte.** Todo cuelga de un `company_id` propio, y el
 *    scope global de empresa separa estos datos de los de cualquier
 *    cliente real. Ningún listado, informe ni corrida los mezcla.
 *
 * 2. **Clientes INALCANZABLES por construcción.** Nacen sin correo y
 *    con el teléfono vacío. `RespetaCanales` decide los canales con
 *    `filled($notifiable->routeNotificationFor(...))`, así que sin
 *    esos datos no hay correo ni WhatsApp que enviar — no importa qué
 *    interruptor esté encendido ni quién lo encienda mañana. Es una
 *    garantía estructural y no una de configuración, que es la que de
 *    verdad aguanta.
 *
 * 3. **Documentos internos, no electrónicos.** El grupo de afinidad
 *    nace con `requires_electronic_invoicing = false`: estas facturas
 *    no se firman ni se transmiten a la DIAN, y no gastan un solo
 *    consecutivo del rango autorizado —que no se recupera—.
 *
 * 4. **Sin equipos.** No se crean OLT, routers ni ONT: nada de esto
 *    toca hardware real. Un corte por mora sobre estos contratos no
 *    tiene a qué router conectarse.
 *
 * 5. **Reversible.** `demo:borrar` lo quita todo por company_id.
 *
 * SIN FAKER A PROPÓSITO
 * ---------------------
 * `fakerphp/faker` está en `require-dev`: un servidor instalado con
 * `composer install --no-dev` no lo tiene, y las factories dependen
 * de él. Los nombres salen de listas fijas de aquí abajo, así que
 * esto corre igual en producción que en local.
 *
 *   php artisan demo:sembrar
 *   php artisan demo:sembrar --clientes=200 --nombre="DEMO Pruebas"
 */
class SembrarEmpresaDemo extends Command
{
    protected $signature = 'demo:sembrar
                            {--clientes=200 : Cuántos clientes con contrato crear}
                            {--nombre=DEMO Pruebas : Nombre de la empresa de prueba}
                            {--force : No preguntar}';

    protected $description = 'Crea una empresa de prueba aislada, con clientes y contratos inventados';

    /** Lo que marca a esta empresa como de mentira, en todas partes. */
    public const MARCA = 'DEMO';

    private const NOMBRES = [
        'Ana', 'Carlos', 'Beatriz', 'Diego', 'Elena', 'Fabio', 'Gloria', 'Hernán',
        'Isabel', 'Jorge', 'Karen', 'Luis', 'Marta', 'Néstor', 'Olga', 'Pedro',
        'Rocío', 'Samuel', 'Teresa', 'Víctor',
    ];

    private const APELLIDOS = [
        'Álvarez', 'Bermúdez', 'Cárdenas', 'Duarte', 'Echeverri', 'Franco',
        'Gaviria', 'Hoyos', 'Ibáñez', 'Jaramillo', 'Lozano', 'Mejía',
        'Naranjo', 'Ospina', 'Peláez', 'Quintero', 'Restrepo', 'Salazar',
        'Tabares', 'Uribe',
    ];

    private const BARRIOS = [
        'Centro', 'La Esperanza', 'San José', 'El Carmen', 'Villa Nueva',
        'Los Pinos', 'La Floresta', 'Buenos Aires',
    ];

    public function handle(): int
    {
        $cuantos = max(1, (int) $this->option('clientes'));
        $nombre = trim((string) $this->option('nombre')) ?: 'DEMO Pruebas';

        if (!str_contains(mb_strtoupper($nombre), self::MARCA)) {
            // El nombre es lo único que ve quien abre el selector de
            // empresas. Sin la marca, dentro de un mes nadie sabrá
            // cuál de las dos era la de mentira.
            $nombre = self::MARCA . ' ' . $nombre;
        }

        $this->avisar($nombre, $cuantos);

        if (!$this->option('force') && !$this->confirm('¿Crear estos datos de prueba?', false)) {
            $this->info('Cancelado. No se creó nada.');

            return self::SUCCESS;
        }

        $empresa = DB::transaction(function () use ($nombre, $cuantos) {
            $empresa = $this->crearEmpresa($nombre);
            $sucursal = $this->crearSucursal($empresa, $nombre);
            $grupo = $this->crearGrupo($empresa);
            $planes = $this->crearPlanes($sucursal);

            $this->sembrarClientes($empresa, $sucursal, $grupo, $planes, $cuantos);

            return $empresa;
        });

        $this->newLine();
        $this->info("Listo. Empresa «{$nombre}» creada con id {$empresa->id}.");
        $this->line('Para verla: inicie sesión y cambie de contexto a esa empresa.');
        $this->line("Para borrarla: php artisan demo:borrar --empresa={$empresa->id}");

        return self::SUCCESS;
    }

    private function avisar(string $nombre, int $cuantos): void
    {
        $this->newLine();
        $this->line("Se va a crear la empresa «{$nombre}» con:");
        $this->line("  · 1 sucursal");
        $this->line("  · {$cuantos} clientes con un contrato activo cada uno");
        $this->line('  · 3 planes y sus servicios');
        $this->newLine();
        $this->line('<fg=green>Garantías:</> los clientes nacen SIN correo y SIN teléfono, así que');
        $this->line('no se les puede enviar correo ni WhatsApp. Las facturas son INTERNAS:');
        $this->line('no se firman ni se transmiten a la DIAN. No se crea ningún equipo.');
        $this->newLine();
    }

    private function crearEmpresa(string $nombre): Company
    {
        return Company::create([
            'legal_name' => $nombre,
            'trade_name' => $nombre,
            // NIT inventado y fuera de rango real para que no choque
            // con el de ninguna empresa de verdad.
            'document_number' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'active' => true,
        ]);
    }

    private function crearSucursal(Company $empresa, string $nombre): Branch
    {
        return Branch::create([
            'company_id' => $empresa->id,
            'name' => mb_substr(self::MARCA . ' Sede Única', 0, 40),
            'nit' => $empresa->document_number,
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Medellín',
            'address' => 'Calle 1 # 1-01',
            // La sucursal sí lleva teléfono: es el remitente, no un
            // destinatario. Nada le escribe a este número.
            'number_phone' => '6040000000',
            // Prefijo propio para que sus contratos no se confundan
            // con los de ninguna sucursal real en un listado.
            'contract_prefix' => 'DEMO',
        ]);
    }

    private function crearGrupo(Company $empresa): AffinityGroup
    {
        return AffinityGroup::create([
            'company_id' => $empresa->id,
            'code' => 'DEMO',
            'name' => 'DEMO — Documento interno',
            'description' => 'Grupo de pruebas: NO emite factura electrónica.',
            // LO MÁS IMPORTANTE DE TODO EL COMANDO.
            //
            // En true, cada factura de estas se firmaría y se
            // transmitiría a la DIAN gastando un consecutivo del rango
            // autorizado, que no se recupera.
            'requires_electronic_invoicing' => false,
            'active' => true,
            'is_default' => false,
        ]);
    }

    /** @return array<int, Plan> */
    private function crearPlanes(Branch $sucursal): array
    {
        $usuario = User::query()->orderBy('id')->firstOrFail();
        $planes = [];

        foreach ([['Básico 50M', 50000], ['Hogar 100M', 80000], ['Premium 300M', 120000]] as [$nombre, $precio]) {
            $servicio = Service::create([
                'name' => mb_substr(self::MARCA . ' ' . $nombre, 0, 40),
                'base_price' => $precio,
                'tax_percentage' => 0,
                'branch_id' => $sucursal->id,
                'user_id' => $usuario->id,
            ]);

            $plan = Plan::create([
                'name' => mb_substr(self::MARCA . ' ' . $nombre, 0, 40),
                'branch_id' => $sucursal->id,
                'user_id' => $usuario->id,
            ]);

            $plan->services()->attach($servicio->id);
            $planes[] = $plan;
        }

        return $planes;
    }

    /** @param  array<int, Plan>  $planes */
    private function sembrarClientes(
        Company $empresa,
        Branch $sucursal,
        AffinityGroup $grupo,
        array $planes,
        int $cuantos,
    ): void {
        $usuario = User::query()->orderBy('id')->firstOrFail();
        $numerador = app(ContractNumberGenerator::class);
        $barra = $this->output->createProgressBar($cuantos);
        $barra->start();

        for ($i = 1; $i <= $cuantos; $i++) {
            $cliente = Client::create([
                'company_id' => $empresa->id,
                'branch_id' => $sucursal->id,
                'type_document' => 'Cédula de ciudadanía',
                // Prefijo 99 y el correlativo: imposible que choque
                // con una cédula real y evidente a simple vista.
                'identity_number' => '99' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'name' => mb_substr(self::MARCA . ' ' . self::NOMBRES[$i % count(self::NOMBRES)], 0, 40),
                'last_name' => self::APELLIDOS[($i * 7) % count(self::APELLIDOS)],
                'type_client' => 'Persona Natural',
                // SIN CORREO Y SIN TELÉFONO: ver la cabecera. Es lo que
                // hace imposible escribirle a nadie. `number_phone` es
                // NOT NULL, así que va vacío en vez de nulo; el
                // enrutado usa `?:` y la cadena vacía no es un canal.
                'email' => null,
                'number_phone' => '',
                'aditional_phone' => null,
                'user_id' => $usuario->id,
            ]);

            $contrato = new Contract([
                'branch_id' => $sucursal->id,
                'client_id' => $cliente->id,
                'plan_id' => $planes[$i % count($planes)]->id,
                'affinity_group_id' => $grupo->id,
                'neighborhood' => self::BARRIOS[$i % count(self::BARRIOS)],
                'address' => 'Calle ' . (10 + ($i % 80)) . ' # ' . (1 + ($i % 50)) . '-' . (10 + ($i % 60)),
                'home_type' => 'Propia',
                'social_stratum' => (string) (1 + ($i % 6)),
                'department' => $sucursal->department,
                'municipality' => $sucursal->municipality,
                'status' => ContractStatus::Activo->value,
                // Escalonadas hacia atrás: así la corrida mensual y la
                // mora tienen contratos de distintas antigüedades que
                // tratar, que es lo que se quiere mirar.
                'activation_date' => now()->subDays($i % 180)->startOfDay(),
                'user_id' => $usuario->id,
            ]);

            $contrato->save();
            $numerador->asignar($contrato);

            $barra->advance();
        }

        $barra->finish();
        $this->newLine();
    }
}
