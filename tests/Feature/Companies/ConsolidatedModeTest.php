<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Router;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Panel consolidado: la sucursal de escritura (fase 4).
 *
 * EL AGUJERO QUE CIERRA
 * ---------------------
 * Antes, crear cualquier cosa era `'branch_id' => session('branch_id')`
 * — trece sitios repartidos por doce controladores. Eso funciona
 * mientras SIEMPRE haya una sucursal activa.
 *
 * El panel consolidado rompe ese supuesto: el usuario trabaja varias
 * sedes a la vez y no hay una activa. Con la sesión a null, esas trece
 * escrituras guardaban branch_id nulo. En veinticuatro tablas eso
 * revienta con un error de la base —feo, pero seguro—; pero `invoices`
 * y `cash_registers` lo admiten nulo, así que se habría creado una
 * factura SIN SUCURSAL, invisible en todos los listados y en toda la
 * facturación.
 *
 * Lo que se defiende aquí:
 *
 *  1. Que en modo independiente nada cambie.
 *  2. Que en consolidado no se pueda guardar sin decir la sucursal.
 *  3. Que la sucursal que se diga tenga que ser una de las suyas.
 *  4. Que lo que cuelga de un padre herede SU sucursal y no se
 *     pregunte: una ONT no puede estar en una sede distinta de la OLT
 *     a la que está conectada.
 */
class ConsolidatedModeTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();
    }

    private function contexto(): CurrentContext
    {
        return app(CurrentContext::class);
    }

    // ==================== Modo independiente ====================

    public function test_en_modo_independiente_manda_la_sucursal_activa(): void
    {
        $sucursal = Branch::factory()->create();

        $this->contexto()->establecer($sucursal->company_id, [$sucursal->id], $sucursal->id);

        $this->assertSame($sucursal->id, $this->contexto()->branchParaEscritura());
    }

    public function test_en_modo_independiente_se_ignora_lo_que_llegue_del_formulario(): void
    {
        // El usuario ya eligió sucursal al entrar. Que pueda mandar
        // otra en el formulario y que se le haga caso sería una forma
        // de escribir en una sede en la que no está.
        $empresa = Company::factory()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contexto()->establecer($empresa->id, [$suya->id], $suya->id);

        $this->assertSame($suya->id, $this->contexto()->branchParaEscritura($otra->id));
    }

    // ==================== Panel consolidado ====================

    public function test_en_consolidado_hay_que_decir_la_sucursal(): void
    {
        // Este es el fallo concreto: sin esto se guardaba null.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contexto()->establecer($empresa->id, [$una->id, $otra->id], null);

        $this->expectException(RuntimeException::class);

        $this->contexto()->branchParaEscritura(null);
    }

    public function test_en_consolidado_se_guarda_en_la_sucursal_elegida(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contexto()->establecer($empresa->id, [$una->id, $otra->id], null);

        $this->assertSame($otra->id, $this->contexto()->branchParaEscritura($otra->id));
        $this->assertSame($una->id, $this->contexto()->branchParaEscritura((string) $una->id));
    }

    public function test_en_consolidado_no_vale_una_sucursal_ajena(): void
    {
        // De la misma empresa, pero sin acceso concedido.
        //
        // Hacen falta DOS sucursales alcanzables para que este camino
        // se ejercite: con una sola no hay nada que elegir y se asume,
        // ignorando lo que llegue del formulario. La version anterior
        // de esta prueba usaba una sola y comprobaba, sin querer, ese
        // otro camino.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contexto()->establecer($empresa->id, [$una->id, $otra->id], null);

        $this->expectException(RuntimeException::class);

        $this->contexto()->branchParaEscritura($sinAcceso->id);
    }

    public function test_con_una_sola_sucursal_se_asume_y_se_ignora_la_ajena(): void
    {
        // Cuando no hay alternativa, lo que llegue del formulario da
        // igual: se usa la unica alcanzable. Es el mismo criterio del
        // modo independiente, y es seguro — no puede escribir en la
        // ajena, solo deja de quejarse.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $ajena = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->contexto()->establecer($empresa->id, [$suya->id], null);

        $this->assertSame($suya->id, $this->contexto()->branchParaEscritura($ajena->id));
    }

    public function test_en_consolidado_no_vale_una_sucursal_de_otra_empresa(): void
    {
        // Igual que arriba: dos alcanzables para que la comprobacion
        // llegue a correr.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);
        $ajena = Branch::factory()->create();

        $this->contexto()->establecer($empresa->id, [$una->id, $otra->id], null);

        $this->expectException(RuntimeException::class);

        $this->contexto()->branchParaEscritura($ajena->id);
    }

    // ==================== Sin contexto ====================

    public function test_sin_contexto_no_se_puede_determinar_la_sucursal(): void
    {
        // Falla en voz alta en vez de devolver algo inventado. Si
        // devolviera null, volveríamos al problema de origen.
        $this->assertFalse($this->contexto()->activo());

        $this->expectException(RuntimeException::class);

        $this->contexto()->branchParaEscritura(1);
    }

    // ==================== Lo que hereda no pregunta ====================

    public function test_la_cuenta_pppoe_hereda_la_sucursal_de_su_router(): void
    {
        // Una cuenta PPPoE vive en el equipo donde se crea el secret,
        // no en la sede desde la que se está mirando. Preguntarlo
        // permitiría crearla en una sucursal distinta de su router.
        $empresa = Company::factory()->consolidada()->create();
        $conRouter = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $router = Router::create([
            'branch_id' => $conRouter->id,
            'name' => 'Router', 'ip_address' => '10.0.0.2',
            'username' => 'admin', 'password' => 'x',
            'api_port' => 8728, 'active' => true,
        ]);

        $this->contexto()->establecer($empresa->id, [$conRouter->id, $otra->id], null);

        // No se consulta el contexto: la sucursal sale del router
        $this->assertSame($conRouter->id, $router->branch_id);
        $this->assertNotSame($otra->id, $router->branch_id);
    }

    // ==================== Aislamiento en consolidado ====================

    public function test_el_consolidado_no_deja_ver_otras_empresas(): void
    {
        // Consolidado amplía el alcance DENTRO de la empresa. La
        // barrera entre empresas sigue igual de cerrada.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);
        $ajena = Branch::factory()->create();

        $this->contexto()->establecer($empresa->id, [$una->id, $otra->id], null);

        $ids = Branch::pluck('id')->all();

        $this->assertContains($una->id, $ids);
        $this->assertContains($otra->id, $ids);
        $this->assertNotContains($ajena->id, $ids);
    }

    // ==================== Por HTTP ====================

    public function test_el_cliente_si_se_crea_en_consolidado_porque_es_de_la_empresa(): void
    {
        // Esta prueba decia lo contrario, y su premisa cambio a
        // proposito: el cliente pertenece a la EMPRESA, no a la
        // sucursal, asi que no necesita una para existir. Lo que si
        // sigue necesitando sucursal es el CONTRATO — el servicio se
        // presta en una sede concreta.
        //
        // Ver ClientBelongsToCompanyTest para el detalle.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);
        $usuario->branches()->attach([$una->id, $otra->id], ['role_id' => $this->rol->id]);

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => null,
            'branch_ids' => [$una->id, $otra->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        $this->post(route('clients.store'), [
            'type_document' => 'Cedula de ciudadania',
            'identity_number' => '1234567890',
            'name' => 'Juan',
            'last_name' => 'Perez',
            'type_client' => 'Residencial',
            'number_phone' => '3001234567',
            'email' => 'juan@ejemplo.com',
        ])->assertSessionHasNoErrors();

        $cliente = \App\Models\Client::withoutGlobalScope('empresa')
            ->where('identity_number', '1234567890')->firstOrFail();

        $this->assertSame($empresa->id, (int) $cliente->company_id);
        $this->assertNull($cliente->branch_id);
    }
}
