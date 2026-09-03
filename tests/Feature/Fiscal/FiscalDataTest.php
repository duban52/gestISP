<?php

namespace Tests\Feature\Fiscal;

use App\Models\AffinityGroup;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\FiscalCatalog;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Reports\FiscalCompletenessReport;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Datos fiscales y catálogos.
 *
 * QUÉ RESUELVE ESTA FASE
 * ----------------------
 * Del cliente faltaba todo lo que el XML de una factura electrónica
 * exige: el CÓDIGO del tipo de documento —era texto libre—, el dígito
 * de verificación, el tipo de organización, la dirección fiscal y los
 * códigos DANE.
 *
 * Y faltaban los catálogos: las listas de códigos que publica la DIAN.
 * Van en tabla y no en enums de PHP porque cambian por resolución, y
 * un código nuevo no puede exigir un despliegue.
 *
 * EL FALLO QUE DESTAPÓ
 * --------------------
 * El formulario tenía las opciones escritas a mano, y una de ellas
 * decía «Pasaporte» y guardaba «Persona Jurídica». Nadie se enteraba:
 * el valor guardado no se enseñaba en ninguna parte junto a la etiqueta
 * que lo produjo. Sacar la lista al catálogo lo corrige de raíz.
 *
 * TODO NACE VACÍO, Y ES DELIBERADO
 * --------------------------------
 * Exigir datos fiscales al dar de alta dejaría sin poder trabajar a
 * quien solo quiere instalar internet. Lo que hay en su lugar es un
 * informe que dice qué falta, para poder completarlo antes de que
 * haga falta de verdad.
 */
class FiscalDataTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;
    private Company $empresa;
    private Branch $sucursal;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        Permission::firstOrCreate(
            ['name' => 'fiscal.completeness', 'guard_name' => 'web'],
            ['description' => 'Ver el informe de completitud fiscal'],
        );
        $this->rol->givePermissionTo('fiscal.completeness');

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $this->empresa->id,
            [$this->sucursal->id],
            $this->sucursal->id,
        );
    }

    // ==================== Los catálogos ====================

    public function test_los_codigos_salen_del_catalogo_y_no_de_una_lista_en_el_codigo(): void
    {
        // Cambian por resolución: un código nuevo no puede exigir un
        // despliegue.
        $opciones = FiscalCatalog::opciones(FiscalCatalog::TIPO_DOCUMENTO);

        $this->assertSame('Cédula de ciudadanía', $opciones['13']);
        $this->assertSame('NIT', $opciones['31']);
        $this->assertSame('Cédula de extranjería', $opciones['22']);
    }

    public function test_un_codigo_retirado_sigue_teniendo_nombre(): void
    {
        // Un documento emitido lo lleva, y borrarlo dejaría sin nombre
        // a lo que ya se emitió. Por eso se desactiva, no se borra.
        FiscalCatalog::where('catalog', FiscalCatalog::TIPO_DOCUMENTO)
            ->where('code', '41')
            ->update(['active' => false]);

        $this->assertSame('Pasaporte', FiscalCatalog::nombre(FiscalCatalog::TIPO_DOCUMENTO, '41'));
        $this->assertFalse(FiscalCatalog::vigente(FiscalCatalog::TIPO_DOCUMENTO, '41'));

        // Y deja de ofrecerse al dar de alta.
        $this->assertArrayNotHasKey('41', FiscalCatalog::opciones(FiscalCatalog::TIPO_DOCUMENTO)->all());
    }

    public function test_un_codigo_desconocido_devuelve_el_codigo_en_vez_de_vacio(): void
    {
        // Un documento viejo con un código que ya no está en el
        // catálogo tiene que seguir diciendo algo.
        $this->assertSame('999', FiscalCatalog::nombre(FiscalCatalog::TIPO_DOCUMENTO, '999'));
        $this->assertNull(FiscalCatalog::nombre(FiscalCatalog::TIPO_DOCUMENTO, null));
    }

    public function test_los_municipios_cuelgan_de_su_departamento(): void
    {
        FiscalCatalog::insert([
            // parent_code explicito aunque sea nulo: un insert masivo
            // exige que todas las filas tengan las mismas claves.
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05887', 'name' => 'Yarumal',
             'parent_code' => '05', 'active' => true, 'sort_order' => 1,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '25001', 'name' => 'Agua De Dios',
             'parent_code' => '25', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $deAntioquia = FiscalCatalog::opciones(FiscalCatalog::MUNICIPIO, '05');

        $this->assertCount(2, $deAntioquia);
        $this->assertSame('Medellín', $deAntioquia['05001']);
        $this->assertArrayNotHasKey('25001', $deAntioquia->all());
    }

    public function test_los_municipios_se_consultan_por_departamento(): void
    {
        // Son 1.122: cargarlos todos en cada formulario es medio
        // megabyte de HTML y un desplegable inmanejable en móvil.
        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->getJson(route('fiscal.municipios', ['departamento' => '05']))
            ->assertOk()
            ->assertJson(['05001' => 'Medellín']);

        $this->getJson(route('fiscal.municipios'))->assertOk()->assertExactJson([]);
    }

    // ==================== El cliente ====================

    public function test_el_tipo_de_documento_se_guarda_como_codigo(): void
    {
        $this->post(route('clients.store'), $this->datosCliente())
            ->assertSessionHasNoErrors();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->assertSame('13', $cliente->document_type_code);
        // El texto se sigue rellenando durante la transición: hay
        // pantallas y exportaciones que todavía lo imprimen.
        $this->assertSame('Cédula de ciudadanía', $cliente->type_document);
    }

    public function test_no_vale_un_codigo_que_no_este_en_el_catalogo(): void
    {
        $this->post(route('clients.store'), $this->datosCliente(['document_type_code' => '99']))
            ->assertSessionHasErrors('document_type_code');
    }

    public function test_no_vale_un_codigo_retirado(): void
    {
        // Un código que ya no está vigente es tan inservible como uno
        // inventado.
        FiscalCatalog::where('catalog', FiscalCatalog::TIPO_DOCUMENTO)
            ->where('code', '41')
            ->update(['active' => false]);

        $this->post(route('clients.store'), $this->datosCliente(['document_type_code' => '41']))
            ->assertSessionHasErrors('document_type_code');
    }

    public function test_el_documento_repetido_se_detecta_por_codigo_y_no_por_texto(): void
    {
        // Antes el unique iba por el texto libre, y «Cedula de
        // ciudadania» y «Cédula de ciudadanía» eran dos tipos
        // distintos: el mismo cliente entraba dos veces.
        $this->post(route('clients.store'), $this->datosCliente())->assertSessionHasNoErrors();

        $this->post(route('clients.store'), $this->datosCliente())
            ->assertSessionHasErrors('identity_number');

        $this->assertSame(1, Client::where('identity_number', '1042772330')->count());
    }

    public function test_los_datos_fiscales_son_opcionales(): void
    {
        // Exigirlos dejaría sin poder trabajar a quien solo viene a
        // instalar internet.
        $this->post(route('clients.store'), $this->datosCliente())
            ->assertSessionHasNoErrors();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->assertNull($cliente->fiscal_address);
        $this->assertNull($cliente->municipality_dane_code);
    }

    public function test_se_guardan_los_datos_fiscales_cuando_se_dan(): void
    {
        $this->post(route('clients.store'), $this->datosCliente([
            'organization_type_code' => '2',
            'fiscal_address' => 'Calle 50 # 40-30',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05887',
            'postal_code' => '057040',
            'tax_responsibilities' => ['O-13', 'O-15'],
        ]))->assertSessionHasNoErrors();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->assertSame('Calle 50 # 40-30', $cliente->fiscal_address);
        $this->assertSame('05887', $cliente->municipality_dane_code);
        $this->assertCount(2, $cliente->taxResponsibilities);
    }

    public function test_guardar_sin_responsabilidades_no_borra_las_que_habia(): void
    {
        // Otros formularios comparten esta acción y no las mandan. Sin
        // la comprobación de `has()` las borraría sin que nadie lo
        // pidiera.
        $this->post(route('clients.store'), $this->datosCliente([
            'tax_responsibilities' => ['O-13'],
        ]))->assertSessionHasNoErrors();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->put(route('clients.update', $cliente), [
            'number_phone' => '3009999999',
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $cliente->fresh()->taxResponsibilities);
    }

    public function test_el_formulario_ofrece_los_tipos_del_catalogo(): void
    {
        // Y no la lista escrita a mano que tenía el fallo del
        // «Pasaporte» que guardaba «Persona Jurídica».
        $this->get(route('clients.create'))
            ->assertOk()
            ->assertSee('name="document_type_code"', escape: false)
            ->assertSee('Cédula de ciudadanía', escape: false)
            ->assertDontSee('Cédula de extrangería', escape: false);
    }

    // ==================== El cliente: completar lo que faltaba ====================

    /**
     * Un cliente de antes de la fase 8 nació sin `document_type_code`
     * -la migración no pudo traducir su texto libre, o simplemente no
     * existía la columna-. El informe de completitud fiscal lo pedía
     * sin que hubiera ningún sitio donde ponerlo: la pantalla de
     * edición lo mostraba siempre bloqueado.
     */
    public function test_un_cliente_sin_tipo_de_documento_lo_puede_completar(): void
    {
        $cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
            'document_type_code' => null,
        ]);

        $this->put(route('clients.update', $cliente), [
            'document_type_code' => '13',
            'number_phone' => $cliente->number_phone,
        ])->assertSessionHasNoErrors();

        $cliente->refresh();

        $this->assertSame('13', $cliente->document_type_code);
        // Se rellena tambien el texto libre, por la misma razon que en
        // el alta: hay pantallas que todavia lo imprimen.
        $this->assertSame('Cédula de ciudadanía', $cliente->type_document);
    }

    public function test_el_tipo_de_documento_no_se_puede_cambiar_una_vez_fijado(): void
    {
        // Cambiarlo identificaria a otra persona: para eso se da de
        // alta un cliente nuevo, no se edita este.
        $cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
            'document_type_code' => '13',
        ]);

        $this->put(route('clients.update', $cliente), [
            'document_type_code' => '31',
            'number_phone' => $cliente->number_phone,
        ])->assertSessionHasErrors('document_type_code');

        $this->assertSame('13', $cliente->fresh()->document_type_code);
    }

    public function test_la_pantalla_de_edicion_ofrece_completar_el_tipo_si_falta(): void
    {
        $sinTipo = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
            'document_type_code' => null,
        ]);

        $this->get(route('clients.edit', $sinTipo))
            ->assertOk()
            ->assertSee('name="document_type_code"', false)
            ->assertDontSee('disabled="disabled" id="document_type_code"', false);
    }

    // ==================== La empresa ====================

    /**
     * Espejo de `test_se_guardan_los_datos_fiscales_cuando_se_dan` pero
     * para la empresa: existían en la tabla desde la fase 8, pero
     * ningún formulario los pedía.
     */
    public function test_se_pueden_guardar_los_datos_fiscales_de_la_empresa(): void
    {
        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->put(route('companies.update', $this->empresa), [
            'legal_name' => $this->empresa->legal_name,
            'document_type_code' => '31',
            'document_number' => $this->empresa->document_number,
            'operation_mode' => Company::MODO_INDEPENDIENTE,
            'organization_type_code' => '1',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'postal_code' => '050001',
            'tax_responsibilities' => ['O-13', 'O-23'],
        ])->assertSessionHasNoErrors();

        $empresa = $this->empresa->fresh();

        $this->assertSame('1', $empresa->organization_type_code);
        $this->assertSame('05001', $empresa->municipality_dane_code);
        $this->assertCount(2, $empresa->taxResponsibilities);
    }

    public function test_el_formulario_de_empresa_ofrece_los_tipos_del_catalogo(): void
    {
        // Antes eran tres opciones escritas a mano (NIT, CC, CE): el
        // mismo fallo que ya se habia corregido en el cliente.
        $this->get(route('companies.edit', $this->empresa))
            ->assertOk()
            ->assertSee('name="document_type_code"', false)
            ->assertSee('Cédula de extranjería', false)
            ->assertSee('Tarjeta de identidad', false);
    }

    // ==================== El servicio ====================

    public function test_se_pueden_guardar_los_datos_fiscales_del_servicio(): void
    {
        $this->post(route('services.store'), [
            'branch_id' => $this->sucursal->id,
            'name' => 'Internet 100 Megas',
            'base_price' => '50000',
            'tax_percentage' => '19',
            'product_code' => '81112101',
            'product_code_type' => '001',
            'unit_measure_code' => '94',
        ])->assertSessionHasNoErrors();

        $servicio = Service::where('name', 'Internet 100 Megas')->firstOrFail();

        $this->assertSame('81112101', $servicio->product_code);
        $this->assertSame('001', $servicio->product_code_type);
        $this->assertSame('94', $servicio->unit_measure_code);
    }

    public function test_los_datos_fiscales_del_servicio_son_opcionales(): void
    {
        // Exigirlos dejaria sin poder trabajar a quien solo viene a
        // dar de alta un servicio operativo.
        $this->post(route('services.store'), [
            'branch_id' => $this->sucursal->id,
            'name' => 'Internet 200 Megas',
            'base_price' => '70000',
            'tax_percentage' => '19',
        ])->assertSessionHasNoErrors();

        $servicio = Service::where('name', 'Internet 200 Megas')->firstOrFail();

        $this->assertNull($servicio->product_code);
        $this->assertNull($servicio->unit_measure_code);
    }

    // ==================== El informe ====================

    public function test_el_informe_dice_que_le_falta_a_la_empresa(): void
    {
        // Sin los datos de la empresa no se emite NADA, por muchos
        // clientes completos que haya.
        $informe = (new FiscalCompletenessReport())->empresa();

        $this->assertContains('Dígito de verificación', $informe['faltan']);
        $this->assertContains('Responsabilidades fiscales', $informe['faltan']);
    }

    public function test_la_empresa_incompleta_es_bloqueante(): void
    {
        $resumen = (new FiscalCompletenessReport())->resumen();

        $this->assertTrue($resumen['bloqueante']);
    }

    public function test_solo_cuenta_los_clientes_que_van_a_facturar_electronicamente(): void
    {
        // Un cliente cuyos contratos son todos internos no necesita
        // datos fiscales, y contarlo sería ruido que esconde los que
        // sí importan.
        $interno = AffinityGroup::factory()->create(['company_id' => $this->empresa->id]);
        $electronico = AffinityGroup::factory()->electronico()->create(['company_id' => $this->empresa->id]);

        $conInterno = $this->clienteConContrato($interno);
        $conElectronico = $this->clienteConContrato($electronico);

        $ids = (new FiscalCompletenessReport())->clientes()->pluck('id');

        $this->assertTrue($ids->contains($conElectronico->id));
        $this->assertFalse($ids->contains($conInterno->id));

        // Con --todos sí sale.
        $todos = (new FiscalCompletenessReport(incluirTodos: true))->clientes()->pluck('id');
        $this->assertTrue($todos->contains($conInterno->id));
    }

    public function test_un_codigo_no_vigente_cuenta_como_faltante(): void
    {
        // Es tan inservible como uno vacío, y esa diferencia no se ve
        // mirando si la columna está llena.
        $electronico = AffinityGroup::factory()->electronico()->create(['company_id' => $this->empresa->id]);
        $cliente = $this->clienteConContrato($electronico);

        $cliente->update(['organization_type_code' => '2']);

        FiscalCatalog::where('catalog', FiscalCatalog::TIPO_ORGANIZACION)
            ->where('code', '2')
            ->update(['active' => false]);

        $fila = (new FiscalCompletenessReport())->clientes()->firstWhere('id', $cliente->id);

        $this->assertContains('Tipo de organización (código no vigente)', $fila['faltan']);
    }

    public function test_los_servicios_sin_codigo_de_producto_salen_en_el_informe(): void
    {
        Service::create([
            'name' => 'Internet 100M',
            'base_price' => 50000,
            'tax_percentage' => 19,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);

        $fila = (new FiscalCompletenessReport())->servicios()->first();

        $this->assertContains('Código de producto', $fila['faltan']);
        $this->assertContains('Unidad de medida', $fila['faltan']);
    }

    public function test_la_pantalla_del_informe_se_pinta(): void
    {
        $this->get(route('fiscal.completeness'))
            ->assertOk()
            ->assertSee('Completitud fiscal', escape: false)
            ->assertSee('La empresa que emite', escape: false);
    }

    public function test_sin_permiso_no_se_ve_el_informe(): void
    {
        $otroRol = Role::where('name', 'tecnico')->firstOrFail();

        $usuario = User::factory()->create();
        $usuario->assignRole($otroRol);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $otroRol->id]);

        $this->actingAs($usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $otroRol->id,
        ]);

        app(CurrentContext::class)->establecer(
            $this->empresa->id,
            [$this->sucursal->id],
            $this->sucursal->id,
        );

        $this->get(route('fiscal.completeness'))->assertForbidden();
    }

    // ==================== Apoyo ====================

    /** @param  array<string, mixed>  $extra */
    private function datosCliente(array $extra = []): array
    {
        return array_merge([
            'document_type_code' => '13',
            'identity_number' => '1042772330',
            'name' => 'Juan',
            'last_name' => 'Pérez',
            'type_client' => 'Residencial',
            'number_phone' => '3001234567',
            'email' => 'juan@ejemplo.com',
        ], $extra);
    }

    private function clienteConContrato(AffinityGroup $grupo): Client
    {
        $cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);

        $plan = Plan::create([
            'name' => 'Plan ' . fake()->unique()->numerify('####'),
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->usuario->id,
        ]);

        Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'affinity_group_id' => $grupo->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
        ]);

        return $cliente->fresh();
    }
}
