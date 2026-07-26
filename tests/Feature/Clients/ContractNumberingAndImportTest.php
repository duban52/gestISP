<?php

namespace Tests\Feature\Clients;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\ContractNumberGenerator;
use App\Services\Import\ClientContractImporter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Numeración de contratos por sucursal e importación masiva de
 * clientes y contratos desde otro software.
 *
 * Lo más delicado que se comprueba aquí es el tratamiento del saldo
 * que traen los clientes migrados: no puede perderse ni desordenar la
 * facturación mensual.
 */
class ContractNumberingAndImportTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Role $rol;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['name' => 'EasyNet Gómez Plata']);
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        // La importación tiene su propio permiso
        Permission::firstOrCreate(
            ['name' => 'clients.import', 'guard_name' => 'web'],
            ['description' => 'Importar clientes y contratos'],
        );
        $this->rol->givePermissionTo('clients.import');

        $this->admin = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->rol->id]);

        $this->plan = Plan::create([
            'name' => 'Internet 100 Megas',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    // ==================== Numeración ====================

    public function test_la_sucursal_recibe_un_prefijo_al_migrar(): void
    {
        // "EasyNet Gómez Plata" → EGP (propuesta inicial, editable)
        $this->assertSame('EGP', $this->branch->fresh()->contract_prefix);
    }

    public function test_el_contrato_recibe_un_numero_consecutivo(): void
    {
        $numerador = app(ContractNumberGenerator::class);

        $this->assertSame('EGP000001', $numerador->siguiente($this->branch->id));
        $this->assertSame('EGP000002', $numerador->siguiente($this->branch->id));
        $this->assertSame('EGP000003', $numerador->siguiente($this->branch->id));
    }

    public function test_cada_sucursal_lleva_su_propia_numeracion(): void
    {
        $otra = Branch::factory()->create(['name' => 'Yatv San Jose']);
        $otra->update(['contract_prefix' => 'YVS', 'contract_next_number' => 0]);

        $numerador = app(ContractNumberGenerator::class);

        $this->assertSame('EGP000001', $numerador->siguiente($this->branch->id));
        $this->assertSame('YVS000001', $numerador->siguiente($otra->id));
        $this->assertSame('EGP000002', $numerador->siguiente($this->branch->id));
    }

    public function test_el_prefijo_se_puede_cambiar_desde_la_sucursal(): void
    {
        $respuesta = $this->put(route('branches.update', $this->branch), [
            'nit' => '900123456-1',
            'name' => $this->branch->name,
            'contract_prefix' => 'eng',
            'country' => 'Colombia',
            'department' => 'Antioquia',
            'municipality' => 'Gómez Plata',
            'address' => 'Calle 1',
            'number_phone' => '3001234567',
            // La sucursal guarda además sus reglas de facturación
            'proration_mode' => 'prorated',
            'due_days' => 20,
            'suspension_threshold' => 2,
            'suspension_days' => 24,
        ]);

        $respuesta->assertRedirect();

        // Se guarda siempre en mayúsculas
        $this->assertSame('ENG', $this->branch->fresh()->contract_prefix);
        $this->assertSame('ENG000001', app(ContractNumberGenerator::class)->siguiente($this->branch->id));
    }

    public function test_un_numero_heredado_adelanta_el_consecutivo(): void
    {
        $numerador = app(ContractNumberGenerator::class);

        // Llega un contrato del sistema anterior con el número 500
        $numerador->registrarNumeroExterno($this->branch->id, 'EGP000500');

        // El siguiente contrato nuevo NO puede repetirlo
        $this->assertSame('EGP000501', $numerador->siguiente($this->branch->id));
    }

    // ============ Listado sin botón de edición ============

    public function test_el_listado_ya_no_ofrece_editar_el_contrato(): void
    {
        $contrato = $this->contrato();

        $respuesta = $this->get(route('contracts.index'));

        $respuesta->assertOk();
        $respuesta->assertSee($contrato->contract_number);
        $respuesta->assertDontSee(route('contracts.edit', $contrato), false);
    }

    // ==================== Importación ====================

    /** Crea un CSV temporal con los encabezados indicados. */
    private function archivo(array $filas, array $encabezados = null): string
    {
        $encabezados ??= ['Numero de contrato', 'Documento', 'Nombre', 'Apellido', 'Telefono', 'Correo', 'Plan', 'Direccion', 'Saldo pendiente'];

        $ruta = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        $f = fopen($ruta, 'w');
        fputcsv($f, $encabezados);

        foreach ($filas as $fila) {
            fputcsv($f, $fila);
        }

        fclose($f);

        return $ruta;
    }

    public function test_importa_clientes_y_contratos_desde_un_solo_archivo(): void
    {
        $ruta = $this->archivo([
            ['', '1111111', 'Ana', 'Restrepo', '3155554433', 'ana@ejemplo.com', 'Internet 100 Megas', 'Calle 10', '0'],
            ['', '2222222', 'Luis', 'Gómez', '3155554434', 'luis@ejemplo.com', 'Internet 100 Megas', 'Calle 20', '0'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        // El mensaje incluye los motivos: si algo falla, el fallo dice
        // POR QUÉ en lugar de solo "0 no es 2".
        $motivos = json_encode($resultado['errores'], JSON_UNESCAPED_UNICODE);

        $this->assertSame(2, $resultado['creados'], $motivos);
        $this->assertSame(2, $resultado['clientes_nuevos'], $motivos);
        $this->assertEmpty($resultado['errores'], $motivos);

        // El dato de la persona fue a clientes...
        $this->assertDatabaseHas('clients', ['identity_number' => '1111111', 'name' => 'Ana']);
        // ...y el del servicio a contratos
        $this->assertDatabaseHas('contracts', ['address' => 'Calle 10', 'plan_id' => $this->plan->id]);

        unlink($ruta);
    }

    public function test_respeta_el_numero_de_contrato_del_sistema_anterior(): void
    {
        $ruta = $this->archivo([
            ['EGP000750', '3333333', 'Marta', 'Ruiz', '3155554435', '', 'Internet 100 Megas', 'Calle 30', '0'],
        ]);

        app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $this->assertDatabaseHas('contracts', ['contract_number' => 'EGP000750']);

        // Y el consecutivo se adelanta para no repetirlo nunca
        $this->assertSame('EGP000751', app(ContractNumberGenerator::class)->siguiente($this->branch->id));

        unlink($ruta);
    }

    public function test_si_no_trae_numero_lo_asigna_el_sistema(): void
    {
        $ruta = $this->archivo([
            ['', '4444444', 'Pedro', 'Diaz', '3155554436', '', 'Internet 100 Megas', 'Calle 40', '0'],
        ]);

        app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $this->assertSame('EGP000001', Contract::first()->contract_number);

        unlink($ruta);
    }

    public function test_un_cliente_que_ya_existe_no_se_duplica(): void
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'identity_number' => '5555555',
        ]);

        $ruta = $this->archivo([
            ['', '5555555', 'Sofia', 'Lopez', '3155554437', '', 'Internet 100 Megas', 'Calle 50', '0'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $this->assertSame(1, $resultado['creados']);
        $this->assertSame(0, $resultado['clientes_nuevos']);
        $this->assertSame(1, Client::where('identity_number', '5555555')->count());
        // El contrato nuevo se le agrega al cliente que ya estaba
        $this->assertSame($cliente->id, Contract::first()->client_id);

        unlink($ruta);
    }

    // ============ Lo delicado: los saldos ============

    public function test_el_saldo_pendiente_se_convierte_en_una_factura_cobrable(): void
    {
        $ruta = $this->archivo([
            ['', '6666666', 'Jorge', 'Mesa', '3155554438', '', 'Internet 100 Megas', 'Calle 60', '85000'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $this->assertSame(1, $resultado['con_saldo']);
        $this->assertEquals(85000, $resultado['saldo_total']);

        $contrato = Contract::first();
        $factura = Invoice::where('contract_id', $contrato->id)->first();

        $this->assertNotNull($factura, 'El saldo migrado debe quedar como una factura.');
        $this->assertEquals(85000, (float) $factura->total);
        $this->assertEquals(85000, (float) $factura->pending_invoice_amount);
        $this->assertSame(ClientContractImporter::TIPO_MIGRACION, $factura->type);

        // Y el dinero cuenta como deuda del contrato
        $this->assertEquals(85000, $contrato->outstandingBalance());

        unlink($ruta);
    }

    public function test_el_saldo_migrado_queda_explicado_en_el_contrato(): void
    {
        $ruta = $this->archivo([
            ['', '7777777', 'Elena', 'Vega', '3155554439', '', 'Internet 100 Megas', 'Calle 70', '45000'],
        ]);

        app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $comentario = Contract::first()->comments()->first();

        $this->assertNotNull($comentario, 'Debe quedar un comentario que explique el saldo.');
        $this->assertStringContainsString('SALDO DE MIGRACIÓN', $comentario->body);
        $this->assertStringContainsString('45.000', $comentario->body);

        unlink($ruta);
    }

    public function test_el_saldo_migrado_no_bloquea_la_factura_del_mes(): void
    {
        // Este es el riesgo grande: si el saldo migrado ocupara el
        // período del mes en curso, la facturación mensual daría ese
        // contrato por facturado y el cliente se quedaría sin factura.
        $ruta = $this->archivo([
            ['', '8888888', 'Raul', 'Nieto', '3155554440', '', 'Internet 100 Megas', 'Calle 80', '30000'],
        ]);

        app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $factura = Invoice::first();

        $this->assertSame(ClientContractImporter::PERIODO_MIGRACION, $factura->billed_year_month);
        $this->assertNotSame(now()->format('Ym'), $factura->billed_year_month);

        unlink($ruta);
    }

    public function test_se_puede_importar_sin_registrar_los_saldos(): void
    {
        $ruta = $this->archivo([
            ['', '9999999', 'Nora', 'Pena', '3155554441', '', 'Internet 100 Megas', 'Calle 90', '50000'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id, crearSaldos: false);

        $this->assertSame(1, $resultado['creados']);
        $this->assertSame(0, $resultado['con_saldo']);
        $this->assertSame(0, Invoice::count());

        unlink($ruta);
    }

    public function test_interpreta_los_importes_escritos_de_distintas_formas(): void
    {
        $ruta = $this->archivo([
            ['', '1010101', 'Uno', 'Test', '300', '', 'Internet 100 Megas', 'Calle 1', '45.000'],
            ['', '2020202', 'Dos', 'Test', '300', '', 'Internet 100 Megas', 'Calle 2', '$ 32000'],
            ['', '3030303', 'Tres', 'Test', '300', '', 'Internet 100 Megas', 'Calle 3', '12500,50'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        $this->assertSame(3, $resultado['con_saldo']);
        $this->assertEqualsWithDelta(45000 + 32000 + 12500.50, $resultado['saldo_total'], 0.01);

        unlink($ruta);
    }

    // ==================== Validación ====================

    public function test_reporta_las_filas_incompletas_sin_detener_la_importacion(): void
    {
        $ruta = $this->archivo([
            ['', '', 'SinDocumento', 'X', '300', '', 'Internet 100 Megas', 'Calle 1', '0'],
            ['', '1212121', 'Correcta', 'Y', '300', '', 'Internet 100 Megas', 'Calle 2', '0'],
            ['', '1313131', 'PlanInexistente', 'Z', '300', '', 'Plan Que No Existe', 'Calle 3', '0'],
        ]);

        $resultado = app(ClientContractImporter::class)->importar($ruta, $this->branch->id);

        // La fila buena entra; las otras dos se reportan
        $this->assertSame(1, $resultado['creados']);
        $this->assertCount(2, $resultado['errores']);

        unlink($ruta);
    }

    public function test_la_revision_previa_no_escribe_nada(): void
    {
        $ruta = $this->archivo([
            ['', '1414141', 'Previa', 'Test', '300', '', 'Internet 100 Megas', 'Calle 1', '70000'],
        ]);

        $resultado = app(ClientContractImporter::class)->previsualizar($ruta, $this->branch->id);

        $this->assertSame(1, $resultado['resumen']['total']);
        $this->assertSame(1, $resultado['resumen']['con_saldo']);

        // Nada se guardó
        $this->assertSame(0, Client::where('identity_number', '1414141')->count());
        $this->assertSame(0, Contract::count());
        $this->assertSame(0, Invoice::count());

        unlink($ruta);
    }

    public function test_la_pantalla_de_importacion_exige_su_permiso(): void
    {
        $this->get(route('clients.import.index'))->assertOk();

        // Un rol sin el permiso no entra
        $otroRol = Role::where('name', '!=', 'superadministrador')->firstOrFail();
        $otroRol->revokePermissionTo('clients.import');

        $usuario = User::factory()->create(['selected_branch_id' => $this->branch->id]);
        $usuario->assignRole($otroRol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $otroRol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $otroRol->id,
        ]);

        $this->get(route('clients.import.index'))->assertForbidden();
    }

    private function contrato(): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ]);

        return app(ContractNumberGenerator::class)->asignar($contrato);
    }
}
