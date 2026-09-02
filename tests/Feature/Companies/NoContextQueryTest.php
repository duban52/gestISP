<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\User;
use App\Services\PppoeQuery;
use App\Tenancy\CurrentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Consultar sin contexto de trabajo.
 *
 * EL FALLO QUE ESTO EVITA
 * -----------------------
 * Al llevar cien filtros de `where('branch_id', session('branch_id'))`
 * a `whereIn('branch_id', $contexto->branchIds())` se coló una
 * diferencia que no se ve leyendo el código:
 *
 *     whereIn('branch_id', [])   NO significa «no filtres».
 *     Significa «escóndelo todo».
 *
 * Y `branchIds()` viene vacío justo cuando no hay contexto: una tarea
 * en cola, un comando de consola, el sondeo de los routers. Ninguno de
 * esos pasa por el middleware que lo establece.
 *
 * El resultado era una corrida de facturación que recorría CERO
 * contratos sin dar ningún error — un resultado vacío en silencio, que
 * es la peor forma de fallar. Seis pruebas lo destaparon a la vez.
 *
 * LA REGLA
 * --------
 * Sin contexto **no se filtra**. No es una excepción inventada aquí:
 * es la misma decisión que ya estaba tomada y documentada para el
 * global scope de empresa, precisamente para que los sondeos y las
 * colas puedan atravesar empresas.
 *
 * La barrera de seguridad no es este filtro, es el middleware: toda
 * petición de un usuario tiene contexto, y sin él se le manda a elegir
 * uno antes de ver nada (ver PreLoginTest).
 */
class NoContextQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function cuenta(Branch $sucursal, string $usuario): PppoeAccount
    {
        $router = Router::create([
            'branch_id' => $sucursal->id,
            'name' => 'Router ' . $usuario,
            'ip_address' => '10.0.0.2',
            'username' => 'admin',
            'password' => 'x',
            'api_port' => 8728,
            'active' => true,
        ]);

        return PppoeAccount::create([
            'branch_id' => $sucursal->id,
            'router_id' => $router->id,
            'mikrotik_id' => '*' . fake()->unique()->numerify('###'),
            'username' => $usuario,
            'password' => 'clave',
            'profile' => 'PLAN 150M',
            'service' => 'pppoe',
            'disabled' => false,
        ]);
    }

    // ==================== El método que decide ====================

    public function test_sin_contexto_la_consulta_sale_intacta(): void
    {
        $contexto = app(CurrentContext::class);

        $this->assertFalse($contexto->activo());
        $this->assertSame([], $contexto->branchIds());

        $consulta = PppoeAccount::query();

        // La MISMA instancia, sin cláusula añadida. Si devolviera una
        // consulta con `whereIn(..., [])` no habría nada que ver.
        $this->assertSame($consulta, $contexto->limitarSucursales($consulta));
    }

    public function test_con_contexto_si_acota(): void
    {
        $empresa = Company::factory()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->cuenta($una, 'de.la.una');
        $this->cuenta($otra, 'de.la.otra');

        app(CurrentContext::class)->establecer($empresa->id, [$una->id], $una->id);

        $usuarios = app(CurrentContext::class)
            ->limitarSucursales(PppoeAccount::query())
            ->pluck('username');

        $this->assertContains('de.la.una', $usuarios);
        $this->assertNotContains('de.la.otra', $usuarios);
    }

    public function test_acota_por_la_columna_que_se_le_diga(): void
    {
        // Las consultas con join necesitan la columna cualificada
        // ('contracts.branch_id'), o MySQL se queja de ambigüedad.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        app(CurrentContext::class)->establecer($empresa->id, [$sucursal->id], $sucursal->id);

        $sql = app(CurrentContext::class)
            ->limitarSucursales(PppoeAccount::query(), 'pppoe_accounts.branch_id')
            ->toSql();

        $this->assertStringContainsString('pppoe_accounts', $sql);
    }

    // ==================== Los servicios que se usan en cola ====================

    public function test_el_listado_de_pppoe_funciona_sin_contexto(): void
    {
        // PppoeQuery lo usan la exportación y el muestreador de
        // sesiones, que corren fuera de una petición. Con el filtro
        // imposible devolvían cero cuentas y nadie se enteraba.
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->cuenta($sucursal, 'pedro.gomez');

        $this->assertFalse(app(CurrentContext::class)->activo());

        $cuentas = app(PppoeQuery::class)->construir([])->get();

        $this->assertCount(1, $cuentas);
        $this->assertSame('pedro.gomez', $cuentas->first()->username);
    }

    public function test_una_sucursal_pedida_a_mano_sigue_mandando(): void
    {
        // Sin contexto pero con sucursal explícita —como la pasan los
        // comandos— se filtra por esa y solo por esa.
        $empresa = Company::factory()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->cuenta($una, 'de.la.una');
        $this->cuenta($otra, 'de.la.otra');

        $cuentas = app(PppoeQuery::class)->construir([], $una->id)->get();

        $this->assertCount(1, $cuentas);
        $this->assertSame('de.la.una', $cuentas->first()->username);
    }

    // ==================== La barrera sigue en su sitio ====================

    public function test_un_usuario_con_contexto_no_ve_otras_empresas(): void
    {
        // Que sin contexto no se filtre NO abre nada por HTTP: el
        // middleware garantiza contexto en toda petición de usuario.
        // Esto comprueba que la puerta de atrás no existe.
        $empresa = Company::factory()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);

        $ajena = Branch::factory()->create();

        $this->cuenta($suya, 'propia');
        $this->cuenta($ajena, 'ajena');

        app(CurrentContext::class)->establecer($empresa->id, [$suya->id], $suya->id);

        $usuarios = app(PppoeQuery::class)->construir([])->get()->pluck('username');

        $this->assertContains('propia', $usuarios);
        $this->assertNotContains('ajena', $usuarios);
    }

    public function test_el_usuario_de_prueba_no_hace_falta(): void
    {
        // Deja constancia de que estas consultas no dependen de que
        // haya nadie autenticado: es justo el escenario de una cola.
        $this->assertNull(auth()->user());

        User::factory()->create();

        $this->assertNull(auth()->user());
    }
}
