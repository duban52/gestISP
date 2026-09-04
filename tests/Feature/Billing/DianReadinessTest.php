<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\DianReadiness;
use App\Billing\Dian\Transport\DianTestSetTransport;
use App\Billing\Dian\Transport\FakeDianTransport;
use App\Billing\Dian\Transport\TransmissionResult;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\ElectronicDocument;
use App\Models\FiscalCatalog;
use App\Models\Invoice;
use App\Models\NumberingRange;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Habilitación y producción gradual (fase 12).
 *
 * QUÉ CIERRA ESTA FASE
 * --------------------
 * Hasta ahora la única forma de saber si una empresa podía emitir era
 * intentarlo — y ese es justamente el momento en que no se puede
 * fallar: un consecutivo autorizado gastado en un documento que la DIAN
 * rechaza deja un hueco que hay que justificar ante ella.
 *
 * Y había tres comprobaciones escritas que **no llamaba nadie**:
 * `diasParaCaducar()` del certificado, `porAgotarse()` del rango y
 * `vigente()` de la resolución. Existían desde la fase 10 sin usarse.
 *
 * LO QUE MÁS SE DEFIENDE AQUÍ
 * ---------------------------
 * Que el interruptor de producción **no se pueda accionar a ciegas**.
 * Encenderlo con el certificado caducado o sin rango no falla en el
 * momento: falla el día 1 del mes siguiente, a mitad de la corrida.
 */
class DianReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private Branch $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        FiscalCatalog::insert([
            ['catalog' => FiscalCatalog::DEPARTAMENTO, 'code' => '05', 'name' => 'Antioquia',
             'parent_code' => null, 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
            ['catalog' => FiscalCatalog::MUNICIPIO, 'code' => '05001', 'name' => 'Medellín',
             'parent_code' => '05', 'active' => true, 'sort_order' => 0,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $usuario = User::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);
        $this->actingAs($usuario);

        app(CurrentContext::class)->establecer($this->empresa->id, [$this->sucursal->id], $this->sucursal->id);

        config(['dian.endpoint' => 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc']);
    }

    // ==================== De cero a listo ====================

    public function test_una_empresa_recien_creada_no_puede_emitir(): void
    {
        $this->assertFalse($this->revision()->puedeEmitir($this->empresa));
        $this->assertNotEmpty($this->revision()->bloqueos($this->empresa));
    }

    public function test_con_todo_puesto_si_puede(): void
    {
        $this->completarTodo();

        $this->assertTrue(
            $this->revision()->puedeEmitir($this->empresa->fresh()),
            'Con todo completo debería poder emitir. Falta: '
            . implode(' | ', $this->revision()->bloqueos($this->empresa->fresh())),
        );
    }

    public function test_dice_exactamente_que_falta(): void
    {
        // «No se puede emitir» sin decir por qué obliga a revisar seis
        // sitios a mano.
        $bloqueos = implode(' ', $this->revision()->bloqueos($this->empresa));

        $this->assertStringContainsString('Faltan', $bloqueos);
        $this->assertStringContainsString('certificado', $bloqueos);
    }

    // ==================== Lo que bloquea ====================

    public function test_un_certificado_caducado_bloquea(): void
    {
        // Firmar con uno caducado produce documentos que la DIAN
        // rechaza: es peor que no firmar, porque parece que sí.
        $this->completarTodo();

        DianCertificate::where('company_id', $this->empresa->id)
            ->update(['valid_until' => now()->subDay()]);

        $this->assertFalse($this->revision()->puedeEmitir($this->empresa->fresh()));
        $this->assertStringContainsString(
            'caducado',
            implode(' ', $this->revision()->bloqueos($this->empresa->fresh())),
        );
    }

    public function test_una_resolucion_vencida_bloquea(): void
    {
        // No autoriza nada, por muchos consecutivos que le queden.
        $this->completarTodo();

        DianResolution::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['valid_until' => now()->subDay()]);

        $this->assertFalse($this->revision()->puedeEmitir($this->empresa->fresh()));
    }

    public function test_sin_endpoint_bloquea(): void
    {
        $this->completarTodo();
        config(['dian.endpoint' => '']);

        $this->assertFalse($this->revision()->puedeEmitir($this->empresa->fresh()));
    }

    public function test_sin_habilitacion_aprobada_bloquea(): void
    {
        $this->completarTodo();

        DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['enabled_at' => null]);

        $this->assertFalse($this->revision()->puedeEmitir($this->empresa->fresh()));
    }

    // ==================== Lo que solo avisa ====================

    public function test_un_certificado_por_caducar_avisa_pero_no_bloquea(): void
    {
        // Renovarlo ante una entidad acreditada lleva tiempo: si el
        // aviso llega el día antes, ya es tarde. Pero hoy todavía firma.
        $this->completarTodo();

        DianCertificate::where('company_id', $this->empresa->id)
            ->update(['valid_until' => now()->addDays(10)]);

        $empresa = $this->empresa->fresh();

        $this->assertTrue($this->revision()->puedeEmitir($empresa));
        $this->assertStringContainsString('caduca en', implode(' ', $this->revision()->avisos($empresa)));
    }

    public function test_un_rango_por_agotarse_avisa_pero_no_bloquea(): void
    {
        // Se agota a mitad de una corrida mensual y a partir de ahí no
        // se emite ni una más.
        $this->completarTodo();

        NumberingRange::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['range_start' => 1, 'range_end' => 1000, 'current_number' => 995]);

        $empresa = $this->empresa->fresh();

        $this->assertTrue($this->revision()->puedeEmitir($empresa));
        $this->assertStringContainsString('quedan', implode(' ', $this->revision()->avisos($empresa)));
    }

    public function test_el_transporte_forzado_a_simulado_avisa(): void
    {
        // Hay URL pero no sale nada: es fácil creer que se está
        // transmitiendo cuando no.
        $this->completarTodo();
        config(['dian.transport' => 'fake']);

        $empresa = $this->empresa->fresh();

        $this->assertTrue($this->revision()->puedeEmitir($empresa));
        $this->assertStringContainsString('simulado', implode(' ', $this->revision()->avisos($empresa)));
    }

    // ==================== Los comandos ====================

    public function test_el_diagnostico_falla_si_la_empresa_no_esta_lista(): void
    {
        // El código de salida sirve para encadenarlo en un despliegue.
        $this->artisan('dian:diagnostico', ['--empresa' => $this->empresa->id])
            ->assertExitCode(1);
    }

    public function test_el_diagnostico_pasa_cuando_esta_todo(): void
    {
        $this->completarTodo();

        $this->artisan('dian:diagnostico', ['--empresa' => $this->empresa->id])
            ->assertExitCode(0);
    }

    public function test_no_se_puede_habilitar_con_bloqueos(): void
    {
        // ES LA PRUEBA IMPORTANTE. Encender con el certificado caducado
        // no falla hoy: falla el día 1 del mes siguiente, a mitad de la
        // corrida mensual.
        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id])
            ->expectsOutputToContain('No se puede habilitar todavía')
            ->assertExitCode(1);

        $this->assertFalse($this->empresa->fresh()->electronic_invoicing_enabled);
    }

    public function test_habilitar_enciende_cuando_esta_todo(): void
    {
        $this->completarTodo();

        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id])
            ->expectsConfirmation('¿Continuar?', 'yes')
            ->assertExitCode(0);

        $this->assertTrue($this->empresa->fresh()->electronic_invoicing_enabled);
    }

    public function test_habilitar_queda_en_la_trazabilidad(): void
    {
        // Es de las cosas que un día habrá que poder explicar.
        $this->completarTodo();

        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id])
            ->expectsConfirmation('¿Continuar?', 'yes');

        $this->assertDatabaseHas('audits', ['action' => 'dian.habilitada']);
    }

    public function test_forzar_enciende_pero_avisa_de_lo_que_se_salta(): void
    {
        // No es un atajo: es una decisión, y tiene que verse.
        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id, '--forzar' => true])
            ->expectsOutputToContain('SALTÁNDOSE')
            ->expectsConfirmation('¿Continuar?', 'yes')
            ->assertExitCode(0);

        $this->assertTrue($this->empresa->fresh()->electronic_invoicing_enabled);
    }

    public function test_apagar_no_borra_la_habilitacion_conseguida(): void
    {
        // La habilitación ante la DIAN se consiguió: es un hecho
        // histórico. Lo que se apaga es la emisión.
        $this->completarTodo();

        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id, '--apagar' => true])
            ->expectsConfirmation('¿Apagar la facturación electrónica de ' . $this->empresa->nombreVisible() . '?', 'yes')
            ->assertExitCode(0);

        $this->assertFalse($this->empresa->fresh()->electronic_invoicing_enabled);
        $this->assertNotNull($this->empresa->fresh()->dianConfiguration->enabled_at);
    }

    public function test_las_alertas_avisan_de_lo_que_va_a_romperse(): void
    {
        $this->completarTodo();

        // Las alertas solo miran las empresas que EMITEN: avisar a
        // quien todavia no ha encendido nada seria ruido.
        $this->empresa->update(['electronic_invoicing_enabled' => true]);

        DianCertificate::where('company_id', $this->empresa->id)
            ->update(['valid_until' => now()->addDays(5)]);

        $this->artisan('dian:alertas')
            ->expectsOutputToContain('caduca en')
            ->assertExitCode(1);
    }

    public function test_sin_nada_que_avisar_las_alertas_pasan(): void
    {
        $this->completarTodo();
        $this->empresa->update(['electronic_invoicing_enabled' => true]);

        $this->artisan('dian:alertas')
            ->expectsOutputToContain('Todo en orden')
            ->assertExitCode(0);
    }

    public function test_las_alertas_no_miran_a_quien_no_emite(): void
    {
        // Una empresa que todavia no ha encendido la facturacion
        // electronica no tiene nada que se le pueda romper.
        $this->completarTodo();

        DianCertificate::where('company_id', $this->empresa->id)
            ->update(['valid_until' => now()->addDays(5)]);

        $this->artisan('dian:alertas')
            ->expectsOutputToContain('Ninguna empresa')
            ->assertExitCode(0);
    }

    // ==================== El set de pruebas ====================

    public function test_el_set_de_pruebas_manda_los_documentos_firmados(): void
    {
        $transporte = new FakeDianTransport();
        $transporte->responder(TransmissionResult::aceptado('zip-key-123'));
        $this->app->instance(DianTestSetTransport::class, $transporte);

        $this->completarTodo();
        $this->documentoFirmado();

        $this->artisan('dian:set-de-pruebas', ['--empresa' => $this->empresa->id])
            ->expectsConfirmation('¿Mandar el set a la DIAN?', 'yes')
            ->expectsOutputToContain('zip-key-123')
            ->assertExitCode(0);

        $this->assertCount(1, $transporte->setsEnviados);
        $this->assertSame('set-1234', $transporte->setsEnviados[0]['testSetId']);
    }

    public function test_recibido_no_es_aprobado(): void
    {
        // La DIAN devuelve una ZipKey de acuse y valida después.
        // Confundirlo con «aprobado» haría creer que la habilitación
        // está hecha cuando solo está entregada.
        $transporte = new FakeDianTransport();
        $transporte->responder(TransmissionResult::aceptado('zip-key-123'));
        $this->app->instance(DianTestSetTransport::class, $transporte);

        $this->completarTodo();
        DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['enabled_at' => null]);

        $this->documentoFirmado();

        $this->artisan('dian:set-de-pruebas', ['--empresa' => $this->empresa->id])
            ->expectsConfirmation('¿Mandar el set a la DIAN?', 'yes')
            ->expectsOutputToContain('Recibido NO es aprobado');

        // El comando NO habilita a nadie.
        $this->assertNull($this->empresa->fresh()->dianConfiguration->enabled_at);
    }

    public function test_sin_identificador_de_set_no_se_manda(): void
    {
        $this->completarTodo();

        DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['test_set_id' => null]);

        $this->artisan('dian:set-de-pruebas', ['--empresa' => $this->empresa->id])
            ->assertExitCode(1);
    }

    public function test_sin_documentos_firmados_no_se_manda(): void
    {
        $this->completarTodo();

        $this->artisan('dian:set-de-pruebas', ['--empresa' => $this->empresa->id])
            ->expectsOutputToContain('No hay documentos firmados')
            ->assertExitCode(1);
    }

    // ==================== Apoyo ====================

    private function revision(): DianReadiness
    {
        return new DianReadiness();
    }

    /** Deja la empresa con todo lo que hace falta para emitir. */
    private function completarTodo(): void
    {
        $this->empresa->update([
            'legal_name' => 'Fibra Andina S.A.S.',
            'document_type_code' => '31',
            'document_number' => '900374637',
            'verification_digit' => '9',
            'organization_type_code' => '1',
            'address' => 'Calle 50 # 40-30',
            'department_dane_code' => '05',
            'municipality_dane_code' => '05001',
            'email' => 'facturacion@fibraandina.co',
        ]);

        $this->empresa->taxResponsibilities()->firstOrCreate(['responsibility_code' => 'O-13']);

        DianConfiguration::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->empresa->id],
            [
                'environment_code' => DianConfiguration::PRODUCCION,
                'enabled_at' => now(),
                'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
                'software_pin' => '12345',
                'test_set_id' => 'set-1234',
            ],
        );

        DianCertificate::firstOrCreate(
            ['company_id' => $this->empresa->id, 'name' => 'Certificado'],
            [
                'path' => 'certificados/prueba.p12',
                'password' => 'x',
                'valid_from' => now()->subMonth(),
                'valid_until' => now()->addYear(),
                'active' => true,
            ],
        );

        $resolucion = DianResolution::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->empresa->id, 'resolution_number' => '18760000001'],
            [
                'document_type_code' => DianResolution::FACTURA,
                'valid_from' => now()->subYear(),
                'valid_until' => now()->addYear(),
                'technical_key' => 'clave',
            ],
        );

        NumberingRange::withoutGlobalScopes()->firstOrCreate(
            ['dian_resolution_id' => $resolucion->id, 'prefix' => 'SETP'],
            [
                'branch_id' => $this->sucursal->id,
                'range_start' => 990000000,
                'range_end' => 995000000,
                'current_number' => 0,
            ],
        );
    }

    private function documentoFirmado(): ElectronicDocument
    {
        $factura = Invoice::factory()->create([
            'branch_id' => $this->sucursal->id,
            'company_id' => $this->empresa->id,
        ]);

        return ElectronicDocument::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'invoice_id' => $factura->id,
            'environment_code' => '2',
            'cufe' => str_repeat('b', 96),
            'signed_xml' => '<Invoice/>',
            'status' => ElectronicDocument::FIRMADO,
            'signed_at' => now(),
        ]);
    }
}
