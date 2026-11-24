<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\Olt;
use App\Models\OltUplink;
use App\Models\PonPort;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Services\PppoePoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los sondeos no escriben en la auditoría.
 *
 * LO QUE TUMBÓ PRODUCCIÓN (noviembre de 2026)
 * -------------------------------------------
 * `audits` llegó a 21 GB y 37,5 millones de filas con 950 contratos.
 * Junto con los binlogs de MySQL llenó el disco, y el sitio entero
 * respondió 500 hasta que se liberó espacio.
 *
 * La causa: la lista de campos ignorados de `PppoeAccount` decía
 * `is_online`, `rx_bytes` y `tx_bytes` — columnas que ya no existen.
 * Se renombraron y la lista se quedó con los nombres viejos, sin
 * ignorar nada. El sondeo escribe `connected`, `last_address` y
 * `last_polled_at` en cada cuenta cada cinco minutos, y cada una de
 * esas escrituras era una fila de auditoría. Lo mismo con puertos PON
 * y uplinks, que nunca estuvieron en la lista.
 *
 * POR QUÉ ESTA PRUEBA EJECUTA EL SONDEO DE VERDAD
 * -----------------------------------------------
 * Lo que se desfasó fue una lista escrita a mano contra otra lista
 * escrita a mano. Una prueba que copiara los nombres de los campos se
 * desfasaría igual. Aquí se corre el `PppoePoller` real contra un
 * router simulado: si mañana el sondeo empieza a escribir un campo
 * nuevo y nadie lo añade a `config/audit.php`, esta prueba falla — en
 * vez de enterarse alguien cuando el disco esté lleno.
 */
class AuditTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;
    private PppoeAccount $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::factory()->create();

        $this->router = Router::create([
            'branch_id' => $branch->id,
            'name' => 'Router de pruebas',
            'ip_address' => '10.0.0.2',
            'api_port' => 80,
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $this->cuenta = PppoeAccount::create([
            'branch_id' => $branch->id,
            'router_id' => $this->router->id,
            'username' => 'cliente.prueba',
            'password' => 'clave',
            'profile' => '10M',
        ]);
    }

    /** Lo que el sondeo dejó en la auditoría sobre cuentas PPPoE. */
    private function auditoriaDeCuentas(): int
    {
        return Audit::where('auditable_type', PppoeAccount::class)
            ->where('action', 'updated')
            ->count();
    }

    private function routerConLaCuentaConectada(int $bytes): void
    {
        Http::fake([
            '*/rest/interface' => Http::response([
                [
                    'name' => '<pppoe-cliente.prueba>',
                    'rx-byte' => (string) $bytes,
                    'tx-byte' => (string) ($bytes * 3),
                    'running' => 'true',
                ],
            ], 200),
        ]);
    }

    // ==================== PPPoE: la que llenó el disco ====================

    public function test_sondear_una_cuenta_conectada_no_se_audita(): void
    {
        $poller = app(PppoePoller::class);

        // Dos vueltas: la segunda es la que de verdad CAMBIA valores
        // (contadores, hora del sondeo), que es lo que se auditaba.
        $this->routerConLaCuentaConectada(1_000_000);
        $poller->poll($this->router);

        $this->routerConLaCuentaConectada(2_000_000);
        $poller->poll($this->router);

        $this->assertSame(0, $this->auditoriaDeCuentas(), 'El sondeo de PPPoE vuelve a escribir en la auditoría.');
    }

    public function test_sondear_una_cuenta_desconectada_tampoco(): void
    {
        // El otro camino del sondeo: la cuenta no aparece en el router
        // y se marca desconectada.
        $this->routerConLaCuentaConectada(1_000_000);
        app(PppoePoller::class)->poll($this->router);

        Http::fake(['*/rest/interface' => Http::response([], 200)]);
        app(PppoePoller::class)->poll($this->router);

        $this->assertSame(0, $this->auditoriaDeCuentas());
    }

    /**
     * Arreglar el ruido no puede dejar ciego al registro.
     *
     * Cambiar el plan de una cuenta lo hace una persona, y tiene que
     * seguir quedando.
     */
    public function test_lo_que_cambia_una_persona_en_la_cuenta_si_se_audita(): void
    {
        $this->cuenta->update(['profile' => '50M']);

        $this->assertSame(1, $this->auditoriaDeCuentas());
    }

    // ==================== Puertos PON, uplinks y OLT ====================

    /**
     * Se escriben los campos exactos de `OltPortPoller::poll()`.
     *
     * Aquí no se corre el sondeo real porque necesita SNMP, que no hay
     * en las pruebas. Si el sondeo de puertos añade un campo, hay que
     * añadirlo en los dos sitios: en este y en `config/audit.php`.
     */
    public function test_el_trafico_de_puertos_y_uplinks_no_se_audita(): void
    {
        $olt = $this->olt();

        $puerto = PonPort::create(['olt_id' => $olt->id, 'slot' => 0, 'port' => 1, 'name' => '0/0/1']);
        $uplink = OltUplink::create(['olt_id' => $olt->id, 'if_index' => 1, 'name' => 'GE 0/9/0']);

        $antes = Audit::where('action', 'updated')->count();

        $puerto->update(['in_bps' => 1000, 'out_bps' => 2000, 'tx_power' => 4.5, 'measured_at' => now()]);
        $uplink->update(['in_bps' => 1000, 'out_bps' => 2000, 'measured_at' => now()]);

        $this->assertSame($antes, Audit::where('action', 'updated')->count());
    }

    public function test_que_un_puerto_caiga_si_se_audita(): void
    {
        // El estado operativo NO está en la lista a propósito: que un
        // puerto PON se caiga es un hecho que se quiere encontrar
        // después.
        $puerto = PonPort::create(['olt_id' => $this->olt()->id, 'slot' => 0, 'port' => 1, 'name' => '0/0/1']);

        $antes = Audit::where('action', 'updated')->count();

        $puerto->update(['oper_status' => 'down']);

        $this->assertSame($antes + 1, Audit::where('action', 'updated')->count());
    }

    public function test_consultar_el_estado_de_una_olt_no_se_audita(): void
    {
        // Lo escribe la ficha y el listado en CADA visita.
        $olt = $this->olt();

        $antes = Audit::where('action', 'updated')->count();

        $olt->update([
            'status' => false,
            'status_checked_at' => now(),
            'last_error' => 'No se pudo autenticar',
            'auth_failed_at' => now(),
        ]);

        $this->assertSame($antes, Audit::where('action', 'updated')->count());
    }

    private function olt(): Olt
    {
        return Olt::create([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'secreta',
            'uptime' => 0,
            'temperature' => 0,
        ]);
    }
}
