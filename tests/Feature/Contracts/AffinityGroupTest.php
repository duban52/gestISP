<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\AffinityGroup;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Grupos de afinidad: la clasificación y su administración.
 *
 * QUÉ DECIDE UN GRUPO
 * -------------------
 * Por qué camino sale la factura de un contrato: electrónico —se
 * firma, se numera con una resolución de la DIAN y se reporta— o
 * interno, que no se reporta a nadie.
 *
 * Hoy esa decisión todavía no tiene consecuencias técnicas: el camino
 * electrónico no existe hasta más adelante. El grupo se implanta antes
 * a propósito, para que cuando llegue no haya que clasificar la
 * cartera entera a la carrera.
 *
 * LO QUE SE DEFIENDE AQUÍ
 * -----------------------
 *  1. Que ningún contrato se quede sin clasificar sin que se sepa.
 *  2. Que no pueda haber dos predeterminados por empresa — si los
 *     hubiera, qué grupo recibe un contrato nuevo dependería del
 *     orden de una consulta.
 *  3. Que un grupo no se borre si con él se perdería la clasificación
 *     de contratos que ya existen.
 *  4. Que la barrera entre empresas siga cerrada también aquí.
 */
class AffinityGroupTest extends TestCase
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

    /** @param  array<int, Branch>  $sucursales */
    private function entrarEn(Company $empresa, array $sucursales): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);

        foreach ($sucursales as $s) {
            $usuario->branches()->attach($s->id, ['role_id' => $this->rol->id]);
        }

        $ids = collect($sucursales)->pluck('id')->all();

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => (string) $sucursales[0]->id,
            'branch_ids' => $ids,
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer($empresa->id, $ids, $sucursales[0]->id);

        return $usuario;
    }

    /** Empresa con una sucursal y contexto activo. */
    private function empresaLista(): array
    {
        $empresa = Company::factory()->create();
        $sucursal = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sucursal]);

        return [$empresa, $sucursal];
    }

    // ==================== Un solo predeterminado ====================

    public function test_no_puede_haber_dos_predeterminados_en_la_misma_empresa(): void
    {
        // Lo impide la BASE, no solo el código. Si dependiera de PHP,
        // un seeder, un comando o una importación podrían saltárselo, y
        // qué grupo recibe un contrato nuevo pasaría a depender del
        // orden de la consulta.
        $empresa = Company::factory()->create();

        AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);

        $this->expectException(QueryException::class);

        AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);
    }

    public function test_dos_empresas_pueden_tener_cada_una_el_suyo(): void
    {
        $una = Company::factory()->create();
        $otra = Company::factory()->create();

        AffinityGroup::factory()->porDefecto()->create(['company_id' => $una->id]);
        AffinityGroup::factory()->porDefecto()->create(['company_id' => $otra->id]);

        $this->assertSame(
            2,
            AffinityGroup::withoutGlobalScope('empresa')->where('is_default', true)->count(),
        );
    }

    public function test_puede_haber_muchos_grupos_no_predeterminados(): void
    {
        // El índice va sobre una columna generada que vale NULL cuando
        // el grupo no es el predeterminado, y un UNIQUE admite tantos
        // NULL como haga falta. Si se hubiera puesto sobre is_default a
        // secas, el segundo grupo normal ya fallaría.
        $empresa = Company::factory()->create();

        AffinityGroup::factory()->count(4)->create(['company_id' => $empresa->id]);

        $this->assertSame(4, AffinityGroup::withoutGlobalScope('empresa')->count());
    }

    // ==================== El código es único por empresa ====================

    public function test_el_codigo_es_unico_dentro_de_la_empresa(): void
    {
        $empresa = Company::factory()->create();

        AffinityGroup::factory()->create(['company_id' => $empresa->id, 'code' => 'CORP']);

        $this->expectException(QueryException::class);

        AffinityGroup::factory()->create(['company_id' => $empresa->id, 'code' => 'CORP']);
    }

    public function test_dos_empresas_pueden_usar_el_mismo_codigo(): void
    {
        $una = Company::factory()->create();
        $otra = Company::factory()->create();

        AffinityGroup::factory()->create(['company_id' => $una->id, 'code' => 'CORP']);
        AffinityGroup::factory()->create(['company_id' => $otra->id, 'code' => 'CORP']);

        $this->assertSame(
            2,
            AffinityGroup::withoutGlobalScope('empresa')->where('code', 'CORP')->count(),
        );
    }

    // ==================== Aislamiento ====================

    public function test_no_se_ven_los_grupos_de_otra_empresa(): void
    {
        [$empresa] = $this->empresaLista();

        AffinityGroup::factory()->create(['company_id' => $empresa->id, 'name' => 'Propio']);
        AffinityGroup::factory()->create(['name' => 'De otra empresa']);

        $nombres = AffinityGroup::pluck('name');

        $this->assertContains('Propio', $nombres);
        $this->assertNotContains('De otra empresa', $nombres);
    }

    public function test_no_se_puede_editar_un_grupo_de_otra_empresa(): void
    {
        // El global scope hace que ni siquiera se encuentre: 404 y no
        // 403, que es lo correcto — un 403 confirmaría que existe.
        $this->empresaLista();

        $ajeno = AffinityGroup::factory()->create();

        $this->get(route('affinity_groups.edit', $ajeno))->assertNotFound();
    }

    // ==================== El CRUD ====================

    public function test_se_crea_un_grupo(): void
    {
        [$empresa] = $this->empresaLista();

        $this->post(route('affinity_groups.store'), [
            'code' => 'corp',
            'name' => 'Corporativo',
            'requires_electronic_invoicing' => '1',
        ])->assertRedirect(route('affinity_groups.index'));

        $grupo = AffinityGroup::where('code', 'CORP')->firstOrFail();

        $this->assertSame($empresa->id, (int) $grupo->company_id);
        $this->assertTrue($grupo->requires_electronic_invoicing);
        // El código se guarda en mayúsculas venga como venga.
        $this->assertSame('CORP', $grupo->code);
    }

    public function test_marcar_uno_predeterminado_se_lo_quita_al_anterior(): void
    {
        [$empresa] = $this->empresaLista();

        $viejo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);
        $nuevo = AffinityGroup::factory()->create(['company_id' => $empresa->id]);

        $this->patch(route('affinity_groups.default', $nuevo))
            ->assertRedirect(route('affinity_groups.index'));

        $this->assertTrue($nuevo->fresh()->is_default);
        $this->assertFalse($viejo->fresh()->is_default);
    }

    public function test_un_grupo_inactivo_no_puede_ser_el_predeterminado(): void
    {
        // Sería un predeterminado que no aparece en el desplegable: los
        // contratos nuevos lo recibirían sin que nadie pueda verlo.
        [$empresa] = $this->empresaLista();

        $inactivo = AffinityGroup::factory()->inactivo()->create(['company_id' => $empresa->id]);

        $this->patch(route('affinity_groups.default', $inactivo));

        $this->assertFalse($inactivo->fresh()->is_default);
    }

    public function test_no_se_desactiva_el_grupo_predeterminado(): void
    {
        [$empresa] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);

        $this->put(route('affinity_groups.update', $grupo), [
            'code' => $grupo->code,
            'name' => $grupo->name,
            'is_default' => '1',
            // 'active' no viene: la casilla está desmarcada
        ])->assertSessionHasErrors('active');

        $this->assertTrue($grupo->fresh()->active);
    }

    public function test_no_se_le_quita_la_marca_al_predeterminado_desde_su_formulario(): void
    {
        // La empresa se quedaría sin ninguno. Para cambiarlo se marca
        // otro, y ese se la quita solo.
        [$empresa] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);

        $this->put(route('affinity_groups.update', $grupo), [
            'code' => $grupo->code,
            'name' => $grupo->name,
            'active' => '1',
        ])->assertSessionHasErrors('is_default');

        $this->assertTrue($grupo->fresh()->is_default);
    }

    public function test_crear_uno_predeterminado_se_lo_quita_al_anterior(): void
    {
        // El orden importa: hay que quitarselo al anterior ANTES de
        // crear este. Al reves, el UNIQUE de la base rechaza el segundo
        // predeterminado antes de que se quite el primero, y lo que
        // deberia ser una operacion normal sale como un error 500.
        [$empresa] = $this->empresaLista();

        $viejo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);

        $this->post(route('affinity_groups.store'), [
            'code' => 'NUEVO',
            'name' => 'Nuevo predeterminado',
            'active' => '1',
            'is_default' => '1',
        ])->assertRedirect(route('affinity_groups.index'));

        $this->assertFalse($viejo->fresh()->is_default);
        $this->assertTrue(AffinityGroup::where('code', 'NUEVO')->firstOrFail()->is_default);
    }

    public function test_editar_uno_para_hacerlo_predeterminado_se_lo_quita_al_anterior(): void
    {
        [$empresa] = $this->empresaLista();

        $viejo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);
        $otro = AffinityGroup::factory()->create(['company_id' => $empresa->id]);

        $this->put(route('affinity_groups.update', $otro), [
            'code' => $otro->code,
            'name' => $otro->name,
            'active' => '1',
            'is_default' => '1',
        ])->assertRedirect(route('affinity_groups.index'));

        $this->assertFalse($viejo->fresh()->is_default);
        $this->assertTrue($otro->fresh()->is_default);
    }

    // ==================== Borrar: casi nunca ====================

    public function test_no_se_borra_un_grupo_con_contratos(): void
    {
        // Se perdería la clasificación de todos ellos, y con ella la
        // razón por la que cada uno facturaba como facturaba.
        [$empresa, $sucursal] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->create(['company_id' => $empresa->id]);
        $this->contrato($sucursal, $grupo);

        $this->delete(route('affinity_groups.destroy', $grupo));

        $this->assertNotNull($grupo->fresh());
    }

    public function test_no_se_borra_el_grupo_predeterminado(): void
    {
        [$empresa] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->porDefecto()->create(['company_id' => $empresa->id]);

        $this->delete(route('affinity_groups.destroy', $grupo));

        $this->assertNotNull($grupo->fresh());
    }

    public function test_si_se_borra_uno_recien_creado_y_vacio(): void
    {
        [$empresa] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->create(['company_id' => $empresa->id]);

        $this->delete(route('affinity_groups.destroy', $grupo))
            ->assertRedirect(route('affinity_groups.index'));

        $this->assertNull($grupo->fresh());
    }

    // ==================== Una empresa nueva nace clasificable ====================

    public function test_una_empresa_nueva_nace_con_su_grupo_predeterminado(): void
    {
        // Sin esto, los contratos de una empresa creada después de esta
        // fase nacerían sin grupo y nadie se enteraría hasta facturar.
        $this->empresaLista();

        $this->post(route('companies.store'), [
            'legal_name' => 'Nueva Empresa SAS',
            'document_type_code' => '31',
            'document_number' => '900123456',
            'operation_mode' => Company::MODO_INDEPENDIENTE,
        ])->assertSessionHasNoErrors();

        $nueva = Company::withoutGlobalScope('empresa')
            ->where('document_number', '900123456')
            ->firstOrFail();

        $grupo = AffinityGroup::porDefectoDe($nueva->id);

        $this->assertNotNull($grupo, 'La empresa nueva se quedó sin grupo predeterminado.');
        $this->assertSame(AffinityGroup::CODIGO_GENERAL, $grupo->code);
        $this->assertFalse($grupo->requires_electronic_invoicing);
    }

    // ==================== Lectura ====================

    public function test_dice_como_factura_en_una_palabra(): void
    {
        // «Electrónica» o «Interna», nunca «Sí/No»: lo que importa al
        // leerlo de un vistazo es por qué camino sale la factura.
        $electronico = AffinityGroup::factory()->electronico()->make();
        $interno = AffinityGroup::factory()->make();

        $this->assertSame('Electrónica', $electronico->modalidadFacturacion());
        $this->assertSame('Interna', $interno->modalidadFacturacion());
    }

    public function test_la_etiqueta_lleva_codigo_y_nombre(): void
    {
        $grupo = AffinityGroup::factory()->make(['code' => 'CORP', 'name' => 'Corporativo']);

        $this->assertSame('CORP — Corporativo', $grupo->etiqueta());
    }

    // ==================== Las pantallas se pintan ====================

    // Los dos fallos de vistas que ha habido en este proyecto —@php en
    // linea y comillas escapadas dentro de route()— compilan sin
    // quejarse y solo revientan al renderizar. Por eso estas pruebas
    // recorren las pantallas de verdad en vez de mirar el Blade.

    public function test_el_listado_se_pinta(): void
    {
        [$empresa] = $this->empresaLista();

        AffinityGroup::factory()->porDefecto()->create([
            'company_id' => $empresa->id,
            'name' => 'General',
        ]);
        AffinityGroup::factory()->electronico()->create([
            'company_id' => $empresa->id,
            'name' => 'Corporativo',
        ]);

        $this->get(route('affinity_groups.index'))
            ->assertOk()
            ->assertSee('General', escape: false)
            ->assertSee('Corporativo', escape: false)
            ->assertSee('Electrónica', escape: false)
            ->assertSee('Interna', escape: false)
            ->assertSee('Predeterminado', escape: false);
    }

    public function test_el_listado_avisa_si_no_hay_predeterminado(): void
    {
        // Sin uno, los contratos nuevos nacen sin clasificar y eso solo
        // se descubriria al facturar.
        [$empresa] = $this->empresaLista();

        AffinityGroup::factory()->create(['company_id' => $empresa->id]);

        $this->get(route('affinity_groups.index'))
            ->assertOk()
            ->assertSee('No hay grupo predeterminado', escape: false);
    }

    public function test_el_formulario_de_alta_se_pinta(): void
    {
        $this->empresaLista();

        $this->get(route('affinity_groups.create'))
            ->assertOk()
            ->assertSee('name="code"', escape: false)
            ->assertSee('name="requires_electronic_invoicing"', escape: false)
            // El aviso del analisis fiscal: es lo que protege a quien
            // use el documento interno.
            ->assertSee('no puede llamarse factura', escape: false);
    }

    public function test_el_formulario_de_edicion_trae_los_valores(): void
    {
        [$empresa] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $this->get(route('affinity_groups.edit', $grupo))
            ->assertOk()
            ->assertSee('CORP', escape: false)
            ->assertSee('Corporativo', escape: false);
    }

    public function test_la_edicion_avisa_de_cuantos_contratos_afecta(): void
    {
        // Cambiar aqui la modalidad afecta a todos los contratos del
        // grupo a la vez: conviene decirlo ANTES.
        [$empresa, $sucursal] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->create(['company_id' => $empresa->id]);
        $this->contrato($sucursal, $grupo);

        $this->get(route('affinity_groups.edit', $grupo))
            ->assertOk()
            ->assertSee('1 contrato(s)', escape: false);
    }

    public function test_la_ficha_del_contrato_muestra_el_grupo(): void
    {
        [$empresa, $sucursal] = $this->empresaLista();

        $grupo = AffinityGroup::factory()->electronico()->create([
            'company_id' => $empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $contrato = $this->contrato($sucursal, $grupo);

        // Solo el grupo. Que documento emite es una propiedad DEL
        // GRUPO y se consulta en su modulo: esta pantalla la abre a
        // diario quien atiende al cliente y no la necesita.
        $this->get(route('contracts.show', $contrato))
            ->assertOk()
            ->assertSee('CORP — Corporativo', escape: false)
            ->assertDontSee('Facturación electrónica', escape: false)
            ->assertDontSee('Documento interno', escape: false);
    }

    public function test_el_alta_no_dice_que_documento_emite_el_grupo(): void
    {
        // El desplegable dice el grupo y nada mas.
        [$empresa, $sucursal] = $this->empresaLista();

        \App\Models\Plan::create([
            'name' => 'Plan 100M',
            'branch_id' => $sucursal->id,
            'user_id' => User::factory()->create()->id,
        ]);

        AffinityGroup::factory()->electronico()->create([
            'company_id' => $empresa->id,
            'code' => 'CORP',
            'name' => 'Corporativo',
        ]);

        $cliente = \App\Models\Client::factory()->create([
            'company_id' => $empresa->id,
            'branch_id' => $sucursal->id,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->get(route('contracts.create', $cliente))
            ->assertOk()
            ->assertSee('CORP — Corporativo', escape: false)
            ->assertDontSee('Emitirá factura electrónica', escape: false)
            ->assertDontSee('Emitirá documento interno', escape: false);
    }

    public function test_la_ficha_avisa_cuando_el_contrato_no_tiene_grupo(): void
    {
        [, $sucursal] = $this->empresaLista();

        $contrato = $this->contrato($sucursal, null);

        $this->get(route('contracts.show', $contrato))
            ->assertOk()
            ->assertSee('Sin grupo asignado', escape: false);
    }

    // ==================== El menu ====================

    public function test_el_menu_lleva_a_los_grupos(): void
    {
        // El modulo puede estar perfecto y ser inalcanzable si el menu
        // no lo lleva. Paso de verdad: al renombrarlo, el menu quedo
        // pidiendo un permiso que en la base seguia con el nombre
        // viejo, y la entrada desaparecio sin dar ningun error.
        $this->empresaLista();

        $this->get(route('gestisp.index'))
            ->assertOk()
            ->assertSee('Grupos de afinidad', escape: false)
            ->assertSee(route('affinity_groups.index'), escape: false);
    }

    public function test_sin_permiso_el_menu_no_lo_muestra(): void
    {
        [$empresa, $sucursal] = $this->empresaLista();

        $otroRol = Role::where('name', 'tecnico')->firstOrFail();

        $usuario = User::factory()->create();
        $usuario->assignRole($otroRol);
        $usuario->branches()->attach($sucursal->id, ['role_id' => $otroRol->id]);

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => (string) $sucursal->id,
            'branch_ids' => [$sucursal->id],
            'current_role_id' => (string) $otroRol->id,
        ]);

        app(CurrentContext::class)->establecer($empresa->id, [$sucursal->id], $sucursal->id);

        $this->get(route('gestisp.index'))
            ->assertOk()
            ->assertDontSee('Grupos de afinidad', escape: false);
    }

    // ==================== Permisos ====================

    public function test_sin_permiso_no_se_entra_al_modulo(): void
    {
        [$empresa, $sucursal] = $this->empresaLista();

        $otroRol = Role::where('name', 'tecnico')->firstOrFail();

        $usuario = User::factory()->create();
        $usuario->assignRole($otroRol);
        $usuario->branches()->attach($sucursal->id, ['role_id' => $otroRol->id]);

        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => (string) $sucursal->id,
            'branch_ids' => [$sucursal->id],
            'current_role_id' => (string) $otroRol->id,
        ]);

        app(CurrentContext::class)->establecer($empresa->id, [$sucursal->id], $sucursal->id);

        $this->get(route('affinity_groups.index'))->assertForbidden();
    }

    // ==================== Apoyo ====================

    private function contrato(Branch $sucursal, ?AffinityGroup $grupo = null): Contract
    {
        $autor = User::factory()->create();

        $plan = \App\Models\Plan::create([
            'name' => 'Plan ' . fake()->unique()->numerify('###'),
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        $cliente = \App\Models\Client::factory()->create([
            'company_id' => $sucursal->company_id,
            'branch_id' => $sucursal->id,
            'user_id' => $autor->id,
        ]);

        return Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'affinity_group_id' => $grupo?->id,
            'status' => 'Activo',
            'user_id' => $autor->id,
        ]);
    }
}
