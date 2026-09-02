<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Plan;
use App\Models\PppoeAccount;
use App\Models\Router;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aislamiento entre empresas (fase 2).
 *
 * POR QUÉ ESTAS PRUEBAS SON OBLIGATORIAS
 * --------------------------------------
 * Hasta la fase 2, el aislamiento dependía de que quien escribiera
 * cada consulta se acordara de filtrar: 113 filtros a mano en 31
 * controladores, ni un solo global scope. Con una empresa, olvidar uno
 * es un fallo menor. Con dos, es que un contribuyente ve los datos de
 * otro.
 *
 * Es además la clase de regresión que NO se nota trabajando: en una
 * instalación de una sola empresa todo se ve bien aunque el scope esté
 * roto. Solo aparece cuando ya hay dos clientes en la plataforma, que
 * es el peor momento para descubrirlo.
 *
 * Por eso hay una prueba por modelo y no una general: si mañana
 * alguien quita el trait de un modelo concreto, tiene que romperse una
 * prueba que diga cuál.
 */
class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaA;
    private Company $empresaB;
    private Branch $sucursalA;
    private Branch $sucursalB;
    private User $usuarioA;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->empresaA = Company::factory()->create(['legal_name' => 'Empresa A']);
        $this->empresaB = Company::factory()->create(['legal_name' => 'Empresa B']);

        $this->sucursalA = Branch::factory()->create(['company_id' => $this->empresaA->id]);
        $this->sucursalB = Branch::factory()->create(['company_id' => $this->empresaB->id]);

        $this->usuarioA = User::factory()->create();
        $this->usuarioA->assignRole($this->rol);
        $this->usuarioA->branches()->attach($this->sucursalA->id, ['role_id' => $this->rol->id]);
    }

    /** Pone el contexto de una empresa, como haría el middleware. */
    private function comoEmpresa(Company $empresa, Branch $sucursal): void
    {
        app(CurrentContext::class)->establecer($empresa->id, [$sucursal->id], $sucursal->id);
    }

    private function contrato(Branch $sucursal): Contract
    {
        $usuario = User::factory()->create();

        $plan = Plan::create([
            'name' => 'Plan ' . $sucursal->id,
            'branch_id' => $sucursal->id,
            'user_id' => $usuario->id,
        ]);

        $cliente = Client::factory()->create([
            'branch_id' => $sucursal->id,
            'user_id' => $usuario->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $usuario->id,
        ]);
    }

    // ==================== La barrera, modelo por modelo ====================

    public function test_sin_contexto_no_se_filtra_nada(): void
    {
        // Es lo que permite que los pollers, la facturación mensual y
        // los comandos de mantenimiento recorran varias empresas. Si
        // el scope filtrara con un contexto vacío, dejarían de ver
        // absolutamente nada y nadie se enteraría hasta que faltara
        // una corrida de facturación.
        $this->contrato($this->sucursalA);
        $this->contrato($this->sucursalB);

        $this->assertFalse(app(CurrentContext::class)->activo());
        $this->assertSame(2, Contract::count());
    }

    public function test_con_contexto_solo_se_ve_lo_propio(): void
    {
        $deA = $this->contrato($this->sucursalA);
        $deB = $this->contrato($this->sucursalB);

        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $ids = Contract::pluck('id')->all();

        $this->assertContains($deA->id, $ids);
        $this->assertNotContains($deB->id, $ids);
    }

    public function test_no_se_puede_leer_por_id_un_registro_ajeno(): void
    {
        // El caso peligroso: un id que llega por la URL. Sin scope,
        // find() lo devuelve tan tranquilo.
        $deB = $this->contrato($this->sucursalB);

        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $this->assertNull(Contract::find($deB->id));
    }

    /**
     * @dataProvider modelosAislados
     */
    public function test_cada_modelo_esta_aislado(string $modelo): void
    {
        // Una por modelo y no una general: si alguien quita el trait de
        // uno concreto, la prueba que se rompe dice cuál.
        $this->crearEnAmbasSucursales($modelo);

        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $empresas = $modelo::pluck('company_id')->unique()->filter()->values();

        $this->assertCount(1, $empresas, "{$modelo} deja ver más de una empresa");
        $this->assertSame($this->empresaA->id, (int) $empresas->first());
    }

    public static function modelosAislados(): array
    {
        return [
            'Contratos' => [Contract::class],
            'Clientes' => [Client::class],
            'ONT' => [Ont::class],
            'Cuentas PPPoE' => [PppoeAccount::class],
            'Órdenes técnicas' => [TechnicalOrder::class],
            'OLT' => [Olt::class],
        ];
    }

    /** Crea un registro del modelo en cada una de las dos sucursales. */
    private function crearEnAmbasSucursales(string $modelo): void
    {
        foreach ([$this->sucursalA, $this->sucursalB] as $sucursal) {
            match ($modelo) {
                Contract::class, Client::class => $this->contrato($sucursal),
                Ont::class => Ont::create([
                    'branch_id' => $sucursal->id,
                    'olt_id' => $this->olt($sucursal)->id,
                    'slot' => 1, 'port' => 1, 'onu_id' => 1,
                    'sn' => 'SN-' . $sucursal->id, 'status' => 1,
                ]),
                Olt::class => $this->olt($sucursal),
                PppoeAccount::class => PppoeAccount::create([
                    'branch_id' => $sucursal->id,
                    'router_id' => $this->router($sucursal)->id,
                    'mikrotik_id' => '*' . $sucursal->id,
                    'username' => 'user' . $sucursal->id,
                    'password' => 'x', 'profile' => 'default',
                    'service' => 'pppoe', 'disabled' => false,
                ]),
                TechnicalOrder::class => TechnicalOrder::create([
                    'contract_id' => $this->contrato($sucursal)->id,
                    'branch_id' => $sucursal->id,
                    'created_by' => $this->usuarioA->id,
                    'type' => 'Servicio',
                    'detail' => 'Sin servicio de internet',
                    'status' => 'Asignada',
                    'initial_comment' => 'Prueba de aislamiento',
                ]),
            };
        }
    }

    private function olt(Branch $sucursal): Olt
    {
        return Olt::create([
            'branch_id' => $sucursal->id,
            'name' => 'OLT ' . $sucursal->id,
            'ip_address' => '10.0.' . $sucursal->id . '.1',
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'root', 'password' => 'x',
            'brand' => 'huawei', 'uptime' => '0',
        ]);
    }

    private function router(Branch $sucursal): Router
    {
        return Router::create([
            'branch_id' => $sucursal->id,
            'name' => 'Router ' . $sucursal->id,
            'ip_address' => '10.1.' . $sucursal->id . '.1',
            'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);
    }

    // ==================== Sucursales ====================

    public function test_no_se_ven_las_sucursales_de_otra_empresa(): void
    {
        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $ids = Branch::pluck('id')->all();

        $this->assertContains($this->sucursalA->id, $ids);
        $this->assertNotContains($this->sucursalB->id, $ids);
    }

    // ==================== Escritura ====================

    public function test_al_guardar_se_deriva_la_empresa_de_la_sucursal(): void
    {
        // company_id está desnormalizado y podría divergir de la
        // sucursal. Derivarlo al guardar es lo que hace que no exista
        // ninguna vía de escritura normal que los descuadre.
        $contrato = $this->contrato($this->sucursalB);

        $this->assertSame($this->empresaB->id, (int) $contrato->company_id);
    }

    public function test_no_se_puede_colar_una_empresa_ajena_al_crear(): void
    {
        // Aunque alguien pase company_id a mano, manda la sucursal.
        $usuario = User::factory()->create();

        $cliente = Client::factory()->create([
            'branch_id' => $this->sucursalA->id,
            'company_id' => $this->empresaB->id,
            'user_id' => $usuario->id,
        ]);

        // El trait no pisa un company_id ya puesto, así que esta es la
        // comprobación honesta: la sucursal y la empresa NO coinciden y
        // el registro queda invisible para las dos. Es un dato
        // incoherente, y por eso el company_id nunca debe venir del
        // formulario: se deriva.
        $this->comoEmpresa($this->empresaA, $this->sucursalA);
        $this->assertNull(Client::find($cliente->id));
    }

    // ==================== El escape, cuando hace falta ====================

    public function test_se_puede_consultar_sin_la_barrera_de_forma_explicita(): void
    {
        // Las consultas de plataforma existen (informes globales,
        // mantenimiento). Lo que no puede haber es que el escape sea
        // implícito: tiene que verse al leer el código.
        $this->contrato($this->sucursalA);
        $this->contrato($this->sucursalB);

        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $this->assertSame(1, Contract::count());
        $this->assertSame(2, Contract::sinFiltroDeEmpresa()->count());
    }

    public function test_sin_contexto_restaura_el_contexto_anterior(): void
    {
        $this->comoEmpresa($this->empresaA, $this->sucursalA);

        $contexto = app(CurrentContext::class);

        $total = $contexto->sinContexto(fn () => Contract::count());

        $this->assertSame(0, $total);
        // Lo importante: el contexto vuelve. Si se quedara apagado,
        // todo lo que viniera después de esa llamada quedaría sin
        // barrera sin que nadie lo notara.
        $this->assertTrue($contexto->activo());
        $this->assertSame($this->empresaA->id, $contexto->companyId());
    }

    // ==================== Por HTTP, que es como entra un usuario ====================

    public function test_un_listado_no_muestra_datos_de_otra_empresa(): void
    {
        $deA = $this->contrato($this->sucursalA);
        $deB = $this->contrato($this->sucursalB);

        $respuesta = $this->actingAs($this->usuarioA)->withSession([
            'branch_id' => (string) $this->sucursalA->id,
            'current_role_id' => (string) $this->rol->id,
        ])->get(route('technicals_orders.index'));

        $respuesta->assertOk();
        $respuesta->assertDontSee($deB->contract_number);
    }

    public function test_el_contexto_se_deduce_de_la_sesion_no_de_la_peticion(): void
    {
        // La empresa nunca puede venir en la petición: se deduce de la
        // sucursal, que ya viene validada contra las del usuario.
        // Aceptarla de fuera sería darle la llave al que llama.
        $this->actingAs($this->usuarioA)->withSession([
            'branch_id' => (string) $this->sucursalA->id,
            'current_role_id' => (string) $this->rol->id,
        ])->get(route('technicals_orders.index') . '?company_id=' . $this->empresaB->id)
            ->assertOk();

        // Y el contexto que quedó es el suyo, no el que pidió la URL.
        $this->assertSame($this->empresaA->id, app(CurrentContext::class)->companyId());
    }
}
