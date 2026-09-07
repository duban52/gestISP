<?php

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\Branch;
use App\Models\FiscalCatalog;
use App\Models\Olt;
use App\Models\OltPortMetric;
use App\Models\Plan;
use App\Models\PonPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La telemetría no entra en la trazabilidad.
 *
 * DE DÓNDE SALE ESTO
 * ------------------
 * De un caso real: `audits` llegó a **4,31 GB y 7.319.292 filas** en
 * menos de un año, en un sistema con **15 contratos y 20 facturas**.
 * Unas 20.000 filas al día, que ningún operador humano produce.
 *
 * `audits:prune --days=365` no encontraba nada que borrar, y eso era la
 * pista: el problema no era acumulación de años, era el RITMO.
 *
 * LAS DOS CAUSAS
 * --------------
 * 1. `OltPortMetric` — `olt:poll-ports` escribe una lectura por puerto
 *    PON cada cinco minutos. `OntMetric` y `PppoeSessionMetric` sí
 *    estaban excluidos; este se olvidó. 1.965.252 filas.
 * 2. `FiscalCatalog` — el seeder recorre ~2.000 códigos de la DIAN con
 *    `updateOrCreate` en cada ejecución. El 90% de la tabla.
 *
 * POR QUÉ VOLVERÍA A PASAR SIN ESTO
 * ---------------------------------
 * El diseño falla **abierto**: `AuditServiceProvider` escucha
 * `eloquent.*: *` y audita TODO salvo lo que esté en una lista de
 * `config/audit.php`. Olvidarse de una tabla no produce ningún error —
 * solo hace crecer la base en silencio.
 *
 * Por eso la exclusión vive ahora en el propio modelo (`NotAudited`) y
 * esta prueba fija el comportamiento: la lista de configuración se
 * puede olvidar, un trait en el modelo lo tiene delante quien lo
 * escribe.
 *
 * LO QUE NO SE DEFIENDE AQUÍ
 * --------------------------
 * Que se audite poco. Se defiende que se audite **lo que hace una
 * persona** — y eso también se comprueba abajo: si por arreglar el
 * ruido se dejara de registrar un cambio real, el arreglo sería peor
 * que el problema.
 */
class AuditNoiseTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_lecturas_de_puertos_no_se_auditan(): void
    {
        // La que costó 1.965.252 filas.
        $puerto = $this->unPuertoPon();

        $antes = Audit::count();

        for ($i = 0; $i < 5; $i++) {
            OltPortMetric::create([
                'port_type' => 'pon',
                'port_id' => $puerto->id,
                'in_octets' => 1000 * $i,
                'out_octets' => 2000 * $i,
                'measured_at' => now(),
            ]);
        }

        $this->assertSame($antes, Audit::count(), 'Las lecturas de puertos siguen auditándose.');
    }

    public function test_los_catalogos_de_la_dian_no_se_auditan(): void
    {
        // El seeder recorre ~2.000 códigos en cada ejecución.
        $antes = Audit::count();

        FiscalCatalog::create([
            'catalog' => FiscalCatalog::MUNICIPIO,
            'code' => '05001',
            'name' => 'Medellín',
            'active' => true,
            'sort_order' => 0,
        ]);

        $this->assertSame($antes, Audit::count());
    }

    public function test_el_trait_manda_aunque_no_este_en_la_configuracion(): void
    {
        // La razón de ser del cambio: la lista de `config/audit.php` se
        // puede olvidar —de hecho se olvidó—, el trait no.
        config(['audit.excluded_models' => []]);

        $puerto = $this->unPuertoPon();
        $antes = Audit::count();

        OltPortMetric::create([
            'port_type' => 'pon',
            'port_id' => $puerto->id,
            'in_octets' => 1,
            'out_octets' => 1,
            'measured_at' => now(),
        ]);

        $this->assertSame($antes, Audit::count(), 'Sin la lista, el trait no bastó.');
    }

    public function test_lo_que_hace_una_persona_SI_se_audita(): void
    {
        // El contrapeso. Si por quitar ruido se dejara de registrar un
        // cambio real, el arreglo sería peor que el problema.
        $sucursal = Branch::factory()->create();

        $antes = Audit::count();

        Plan::create(['name' => 'Plan que alguien creó', 'branch_id' => $sucursal->id]);

        $this->assertGreaterThan($antes, Audit::count(), 'Dejó de auditarse un cambio real.');
    }

    // ==================== El diagnóstico ====================

    public function test_el_resumen_no_borra_nada(): void
    {
        $sucursal = Branch::factory()->create();
        Plan::create(['name' => 'Un plan', 'branch_id' => $sucursal->id]);

        $antes = Audit::count();

        $this->artisan('audits:prune --resumen')->assertSuccessful();

        $this->assertSame($antes, Audit::count());
    }

    public function test_la_poda_programada_no_se_queda_esperando_una_respuesta(): void
    {
        // `confirm()` en un proceso sin terminal devuelve el valor por
        // defecto —false— y la tarea programada no borraría NUNCA nada,
        // sin dar ningún error. Por eso el planificador la llama con
        // `--force`.
        $sucursal = Branch::factory()->create();
        Plan::create(['name' => 'Viejo', 'branch_id' => $sucursal->id]);

        Audit::query()->update(['created_at' => now()->subYears(3)]);

        $this->artisan('audits:prune --days=30 --force')->assertSuccessful();

        $this->assertSame(0, Audit::where('created_at', '<', now()->subDays(30))->count());
    }

    public function test_la_poda_no_permite_conservar_menos_de_treinta_dias(): void
    {
        $this->artisan('audits:prune --days=5 --force')->assertFailed();
    }

    /**
     * Una OLT con un puerto PON.
     *
     * Se arma a mano y no con una factoria porque `Olt` no tiene:
     * crearla aqui es media docena de campos y no merece una factoria
     * nueva solo para esto.
     */
    private function unPuertoPon(): PonPort
    {
        $olt = Olt::create([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'OLT de prueba',
            'ip_address' => '10.0.0.1',
            'username' => 'admin',
            'password' => 'secreta',
            // `uptime` y `temperature` no tienen valor por defecto en
            // la tabla: los rellena el sondeo, no el alta.
            'uptime' => 0,
            'temperature' => 0,
        ]);

        return PonPort::create([
            'olt_id' => $olt->id,
            'slot' => 0,
            'port' => 1,
            'name' => '0/0/1',
        ]);
    }
}
