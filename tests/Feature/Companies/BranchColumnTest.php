<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\ManagementReportsPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La columna «Sucursal» en los listados.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * Al hacer que el panel consolidado funcionara, los listados pasaron a
 * mezclar contratos, facturas y órdenes de varias sedes **sin decir de
 * cuál era cada fila**. Justo el escenario en el que confundirse sale
 * caro: cobrar la factura de otra sede, mandar un técnico a la ciudad
 * equivocada.
 *
 * DOS MECANISMOS, UNA REGLA
 * -------------------------
 * La columna aparece solo cuando aporta —panel consolidado y más de
 * una sucursal alcanzable—, pero se consigue de dos formas según la
 * tabla:
 *
 *   · **DataTables**: la columna se pinta SIEMPRE y se esconde con
 *     `visible: false`. Tiene que ser así porque el JS de esas tablas
 *     lleva índices numéricos de columna; si la columna apareciera y
 *     desapareciera, los índices dirían una cosa u otra según quién
 *     mire la pantalla. Un columnDef oculto sigue contando.
 *
 *   · **Tablas simples** (muflas, cables, categorías): no hay JS con
 *     índices que desincronizar, así que se condiciona en Blade.
 *
 * Esta prueba comprueba la REGLA, no el mecanismo: en consolidado la
 * columna se ve, en los demás modos no. Y recorre las pantallas de
 * verdad, porque los dos fallos de vistas que ha habido en este
 * proyecto compilan sin quejarse y solo revientan al renderizar.
 */
class BranchColumnTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);
        $this->seed(ManagementReportsPermissionSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        // Notas de crédito/débito no están en RoleSeeder: sus permisos
        // se crean en la migración del módulo, que aquí no corre.
        foreach (['notes.index', 'notes.create', 'notes.void', 'notes.pdf'] as $permiso) {
            \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permiso, 'guard_name' => 'web'],
                ['description' => $permiso],
            );
            $this->rol->givePermissionTo($permiso);
        }
    }

    /**
     * Los listados que dicen de qué sede es cada fila.
     *
     * No están todos a propósito. Se quedan fuera:
     *
     *   · **Clientes**: el cliente pertenece a la EMPRESA, no a la
     *     sucursal — su `branch_id` es opcional y suele venir vacío,
     *     así que una columna casi siempre en blanco engañaría.
     *   · **ONTs sin autorizar**: primero se elige la OLT, y con ella
     *     queda dicha la sucursal.
     *   · **Caja**: es la caja abierta de quien mira, y una caja se
     *     abre en una sola sede por definición.
     *
     * @return array<string, array{0: string}>
     */
    public static function listados(): array
    {
        return [
            'contratos' => ['contracts.index'],
            'facturas' => ['invoices.index'],
            'pagos' => ['payments.index'],
            'notas' => ['notes.index'],
            'retenciones' => ['retentions.index'],
            'órdenes técnicas' => ['technicals_orders.index'],
            'PPPoE' => ['pppoe.index'],
            'ONTs' => ['onts.authorized'],
            'OLTs' => ['olts.index'],
            'routers' => ['routers.index'],
            'planes' => ['plans.index'],
            'servicios' => ['services.index'],
            'materiales' => ['materials.index'],
            'categorías' => ['categories.index'],
            'almacenes' => ['warehouses.index'],
            'movimientos' => ['movements.history'],
            'cajas NAP' => ['naps.index'],
            'muflas' => ['closures.index'],
            'cables' => ['cables.index'],
            // Redes va aparte: se pinta como tarjetas, y sin ninguna
            // red no hay tarjeta en la que mirar. Ver más abajo.
        ];
    }

    /** @param  array<int, Branch>  $sucursales */
    private function entrarEn(Company $empresa, array $sucursales, bool $consolidado = false): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);

        foreach ($sucursales as $s) {
            $usuario->branches()->attach($s->id, ['role_id' => $this->rol->id]);
        }

        $ids = collect($sucursales)->pluck('id')->all();

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => $consolidado ? null : (string) $sucursales[0]->id,
            'branch_ids' => $ids,
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $empresa->id,
            $ids,
            $consolidado ? null : $sucursales[0]->id,
        );

        return $usuario;
    }

    /**
     * ¿Se está mostrando la sucursal en esta pantalla?
     *
     * Vale cualquiera de los dos mecanismos: la columna condicionada en
     * Blade, el columnDef visible de DataTables, o —en redes, que se
     * pinta como tarjetas— el distintivo con el icono de sede.
     */
    private function muestraLaSucursal(TestResponse $respuesta): bool
    {
        $html = $respuesta->getContent();

        // La columna existe en el HTML...
        $hayColumna = str_contains($html, '<th>Sucursal</th>');

        // ...pero DataTables puede estar escondiéndola.
        $escondida = str_contains($html, '{ visible: false, targets: 0 }');

        // Redes se pinta como tarjetas y lleva su propio distintivo.
        // Se busca por una clase propia y no por el icono: `fa-store`
        // sale también en el selector de sucursal y en el menú, así
        // que daba positivo en pantallas que no muestran nada.
        $distintivo = str_contains($html, 'distintivo-sucursal');

        return ($hayColumna && !$escondida) || $distintivo;
    }

    /**
     * @dataProvider listados
     */
    public function test_en_consolidado_el_listado_dice_la_sucursal(string $ruta): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $respuesta = $this->get(route($ruta))->assertOk();

        $this->assertTrue(
            $this->muestraLaSucursal($respuesta),
            'El listado mezcla sedes y no dice de cuál es cada fila.',
        );
    }

    /**
     * @dataProvider listados
     */
    public function test_con_una_sola_sucursal_no_se_muestra(string $ruta): void
    {
        // Repetir el mismo valor en todas las filas no informa de nada,
        // y en móvil empuja las columnas útiles fuera de la pantalla.
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$unica]);

        $respuesta = $this->get(route($ruta))->assertOk();

        $this->assertFalse(
            $this->muestraLaSucursal($respuesta),
            'Se muestra la sucursal trabajando en una sola sede: es ruido.',
        );
    }

    // ==================== Redes: tarjetas, no filas ====================

    public function test_la_red_lleva_su_sucursal_en_la_tarjeta(): void
    {
        // Aquí no hay columna que añadir: las redes se pintan como
        // tarjetas. La sucursal va de distintivo junto al nombre, que
        // es lo que corresponde en ese formato — y basta con decirla
        // una vez, porque todo lo que cuelga de la red (OLTs, muflas,
        // cajas, cables) hereda la suya.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        \App\Models\OpticalNetwork::create([
            'branch_id' => $norte->id,
            'user_id' => User::factory()->create()->id,
            'name' => 'Red Centro',
            'nap_prefix' => 'NAP',
            'active' => true,
        ]);

        $this->get(route('networks.index'))
            ->assertOk()
            ->assertSee('distintivo-sucursal', escape: false)
            ->assertSee('Sede Norte', escape: false);
    }

    public function test_en_una_sola_sede_la_red_no_lo_lleva(): void
    {
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$unica]);

        \App\Models\OpticalNetwork::create([
            'branch_id' => $unica->id,
            'user_id' => User::factory()->create()->id,
            'name' => 'Red Unica',
            'nap_prefix' => 'NAP',
            'active' => true,
        ]);

        $this->get(route('networks.index'))
            ->assertOk()
            ->assertSee('Red Unica', escape: false)
            ->assertDontSee('distintivo-sucursal', escape: false);
    }

    // ==================== Filtrar por sucursal ====================

    public function test_en_consolidado_se_puede_filtrar_por_sucursal(): void
    {
        // Ver de que sede es cada fila no basta: trabajando varias a la
        // vez tambien hace falta poder quedarse con una.
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Norte']);
        $sur = Branch::factory()->create(['company_id' => $empresa->id, 'name' => 'Sede Sur']);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertSee('name="branch_id[]"', escape: false)
            ->assertSee('Sede Norte', escape: false)
            ->assertSee('Sede Sur', escape: false);
    }

    public function test_en_modo_independiente_no_se_ofrece_filtrar_por_sucursal(): void
    {
        // Solo hay una: filtrar por ella no quita nada.
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$unica]);

        $this->get(route('contracts.index'))
            ->assertOk()
            ->assertDontSee('name="branch_id[]"', escape: false);
    }

    public function test_el_filtro_de_sucursal_deja_fuera_las_demas(): void
    {
        $empresa = Company::factory()->consolidada()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$norte, $sur], consolidado: true);

        $delNorte = $this->contrato($norte);
        $delSur = $this->contrato($sur);

        $ids = app(\App\Services\ContractQuery::class)
            ->construir(['branch_id' => [$norte->id]])
            ->pluck('contracts.id');

        $this->assertTrue($ids->contains($delNorte->id));
        $this->assertFalse($ids->contains($delSur->id));
    }

    public function test_pedir_una_sucursal_ajena_no_cuela_sus_contratos(): void
    {
        // El filtro se cruza con el alcance del usuario, no lo
        // sustituye: pedir una sede a la que no llega no devuelve nada
        // en vez de abrirla.
        $empresa = Company::factory()->consolidada()->create();
        $suya = Branch::factory()->create(['company_id' => $empresa->id]);
        $otraSuya = Branch::factory()->create(['company_id' => $empresa->id]);
        $sinAcceso = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$suya, $otraSuya], consolidado: true);

        $vetado = $this->contrato($sinAcceso);

        $ids = app(\App\Services\ContractQuery::class)
            ->construir(['branch_id' => [$sinAcceso->id]])
            ->pluck('contracts.id');

        $this->assertFalse($ids->contains($vetado->id));
        $this->assertSame(0, $ids->count());
    }

    /** Un contrato en la sucursal indicada. */
    private function contrato(Branch $sucursal): \App\Models\Contract
    {
        $autor = User::factory()->create();

        $plan = \App\Models\Plan::create([
            'name' => 'Plan ' . fake()->unique()->numerify('####'),
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        $cliente = \App\Models\Client::factory()->create([
            'company_id' => $sucursal->company_id,
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        return \App\Models\Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $autor->id,
        ]);
    }

    // ==================== El criterio ====================

    public function test_el_criterio_es_consolidado_con_varias_sedes(): void
    {
        $contexto = app(CurrentContext::class);
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        // Sin contexto: no hay nada que mostrar.
        $this->assertFalse($contexto->mostrarSucursal());

        // Independiente: hay UNA sede activa, la columna sería ruido.
        $contexto->establecer($empresa->id, [$una->id, $otra->id], $una->id);
        $this->assertFalse($contexto->mostrarSucursal());

        // Consolidado con una sola alcanzable: tampoco hay qué distinguir.
        $contexto->establecer($empresa->id, [$una->id], null);
        $this->assertFalse($contexto->mostrarSucursal());

        // Consolidado con varias: aquí sí.
        $contexto->establecer($empresa->id, [$una->id, $otra->id], null);
        $this->assertTrue($contexto->mostrarSucursal());
    }

    public function test_el_contrato_la_ofrece_como_columna_elegible(): void
    {
        // El listado de contratos tiene selector de columnas propio, así
        // que la sucursal entra ahí en vez de ir fija: viene marcada de
        // serie en consolidado y se puede activar a mano en cualquier
        // modo.
        $empresa = Company::factory()->consolidada()->create();
        $una = Branch::factory()->create(['company_id' => $empresa->id]);
        $otra = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$una, $otra], consolidado: true);

        $columnas = \App\Services\ContractQuery::columnas();

        $this->assertArrayHasKey('branch', $columnas);
        $this->assertSame('Sucursal', $columnas['branch']['titulo']);
        $this->assertTrue($columnas['branch']['defecto'], 'En consolidado debe venir marcada.');

        $this->assertContains('branch', \App\Services\ContractQuery::columnasPorDefecto());
    }

    public function test_en_una_sola_sede_el_contrato_no_la_marca_de_serie(): void
    {
        $empresa = Company::factory()->create();
        $unica = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$unica]);

        $columnas = \App\Services\ContractQuery::columnas();

        // Se sigue ofreciendo —quien la quiera la activa—, pero no
        // viene marcada.
        $this->assertArrayHasKey('branch', $columnas);
        $this->assertFalse($columnas['branch']['defecto']);
    }
}
