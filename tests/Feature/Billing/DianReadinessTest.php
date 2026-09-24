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
    private User $usuario;

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

        $usuario = $this->usuario = User::factory()->create();
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
        // Vaciar tambien los de cada ambiente: la URL ya no hay que
        // configurarla —viene puesta de fabrica—, asi que para
        // probar «sin servicio» hay que quitarlas todas.
        config(['dian.endpoint' => '', 'dian.endpoints.habilitacion' => '', 'dian.endpoints.produccion' => '']);

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

    /**
     * `dian:alertas` avisa de lo que va a ROMPERSE.
     *
     * Que la emisión esté apagada es un estado legítimo mientras se
     * prepara la habilitación: convertirlo en aviso le mandaría un
     * correo cada noche a quien todavía no ha encendido nada.
     */
    public function test_la_emision_apagada_no_dispara_alertas(): void
    {
        $this->completarTodo();
        $this->empresa->update(['electronic_invoicing_enabled' => false]);

        $this->assertNotContains(
            'emision',
            collect($this->revision()->revisar($this->empresa->fresh()))
                ->where('ok', true)
                ->pluck('clave')
                ->all(),
        );

        $this->assertSame([], $this->revision()->avisos($this->empresa->fresh()));
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

    // ==================== Por qué no hay documentos ====================

    /**
     * «No hay documentos firmados que mandar» sin decir por qué es el
     * punto en el que uno se queda mirando la pantalla: el diagnóstico
     * enseñaba todo lo demás en verde y esto no lo miraba nadie.
     */
    public function test_el_diagnostico_dice_si_la_emision_esta_apagada(): void
    {
        $this->completarTodo();
        $this->empresa->update(['electronic_invoicing_enabled' => false]);

        $emision = collect($this->revision()->revisar($this->empresa->fresh()))
            ->firstWhere('clave', 'emision');

        $this->assertFalse($emision['ok']);
        $this->assertStringContainsString('Apagada', $emision['detalle']);
        // No bloquea: no impide emitir, explica por qué no hay nada.
        $this->assertFalse($emision['bloqueante']);
    }

    public function test_el_diagnostico_avisa_si_ningun_contrato_es_electronico(): void
    {
        $this->completarTodo();
        $this->empresa->update(['electronic_invoicing_enabled' => true]);

        $emision = collect($this->revision()->revisar($this->empresa->fresh()))
            ->firstWhere('clave', 'emision');

        $this->assertFalse($emision['ok']);
        $this->assertStringContainsString('grupo de afinidad', $emision['detalle']);
    }

    public function test_el_diagnostico_cuenta_los_contratos_electronicos(): void
    {
        $this->completarTodo();
        $this->empresa->update(['electronic_invoicing_enabled' => true]);

        $grupo = \App\Models\AffinityGroup::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'code' => 'ELEC',
            'name' => 'Electrónicos',
            'requires_electronic_invoicing' => true,
        ]);

        \App\Models\Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'affinity_group_id' => $grupo->id,
            'user_id' => $this->usuario->id,
            'client_id' => \App\Models\Client::factory()->create([
                'branch_id' => $this->sucursal->id,
                'number_phone' => '3001112233',
                'aditional_phone' => '3001112234',
                'user_id' => $this->usuario->id,
            ])->id,
            'plan_id' => \App\Models\Plan::factory()->create([
                'branch_id' => $this->sucursal->id,
                'user_id' => $this->usuario->id,
            ])->id,
        ]);

        $emision = collect($this->revision()->revisar($this->empresa->fresh()))
            ->firstWhere('clave', 'emision');

        $this->assertTrue($emision['ok']);
        $this->assertStringContainsString('Encendida', $emision['detalle']);
    }

    // ==================== Declarar la aprobación ====================

    /**
     * LA PESCADILLA QUE SE MORDÍA LA COLA.
     *
     * El diagnóstico marca «Habilitación» en rojo mientras `enabled_at`
     * sea null, y `enabled_at` solo lo escribe este comando. O sea que
     * el primer paso a producción —el único momento en que el comando
     * tiene sentido— era imposible sin `--forzar`.
     *
     * Las pruebas no lo veían porque el ayudante regalaba `enabled_at`.
     */
    public function test_con_todo_listo_pero_sin_declarar_la_aprobacion_no_enciende(): void
    {
        $this->completarTodo(conHabilitacion: false);

        $this->artisan('dian:habilitar', ['--empresa' => $this->empresa->id])
            ->expectsOutputToContain('No se puede habilitar todavía')
            ->assertExitCode(1);

        $this->assertFalse($this->empresa->fresh()->electronic_invoicing_enabled);
    }

    public function test_declarando_la_fecha_de_aprobacion_enciende_sin_forzar(): void
    {
        $this->completarTodo(conHabilitacion: false);

        $this->artisan('dian:habilitar', [
            '--empresa' => $this->empresa->id,
            '--aprobada' => '2026-09-20',
        ])
            ->expectsConfirmation('¿Continuar?', 'yes')
            ->assertExitCode(0);

        $configuracion = $this->empresa->fresh()->dianConfiguration;

        $this->assertTrue($this->empresa->fresh()->electronic_invoicing_enabled);
        $this->assertSame(DianConfiguration::PRODUCCION, $configuracion->environment_code);
        // La fecha que se declaró, no la de hoy: es cuándo aprobó la DIAN.
        $this->assertSame('2026-09-20', $configuracion->enabled_at->format('Y-m-d'));
    }

    /**
     * Declarar la aprobación NO es `--forzar`.
     *
     * Ese era el daño del atajo viejo: para saltarse el paso imposible
     * había que saltárselos todos, y un rango de producción que faltara
     * se colaba en silencio.
     */
    public function test_declarar_la_aprobacion_no_se_salta_los_demas_bloqueos(): void
    {
        $this->completarTodo(conHabilitacion: false);

        NumberingRange::query()->delete();

        $this->artisan('dian:habilitar', [
            '--empresa' => $this->empresa->id,
            '--aprobada' => '2026-09-20',
        ])
            ->expectsOutputToContain('No se puede habilitar todavía')
            ->assertExitCode(1);

        $this->assertFalse($this->empresa->fresh()->electronic_invoicing_enabled);
    }

    public function test_una_fecha_de_aprobacion_futura_se_rechaza(): void
    {
        // Es cuándo aprobó la DIAN, no cuándo se espera que apruebe.
        $this->completarTodo(conHabilitacion: false);

        $this->artisan('dian:habilitar', [
            '--empresa' => $this->empresa->id,
            '--aprobada' => now()->addWeek()->format('Y-m-d'),
        ])
            ->expectsOutputToContain('no puede ser futura')
            ->assertExitCode(1);

        $this->assertNull($this->empresa->fresh()->dianConfiguration->enabled_at);
    }

    // ==================== Preguntar cómo quedó el set ====================

    public function test_el_envio_del_set_guarda_la_zipkey(): void
    {
        // Es lo que hay que presentar para preguntar el resultado, y
        // eso se hace después: antes solo quedaba en la trazabilidad.
        $transporte = new FakeDianTransport();
        $transporte->responder(TransmissionResult::aceptado('zip-key-123'));
        $this->app->instance(DianTestSetTransport::class, $transporte);

        $this->completarTodo(conHabilitacion: false);
        $this->documentoFirmado();

        $this->artisan('dian:set-de-pruebas', ['--empresa' => $this->empresa->id])
            ->expectsConfirmation('¿Mandar el set a la DIAN?', 'yes');

        $this->assertSame('zip-key-123', $this->empresa->fresh()->dianConfiguration->test_set_zip_key);
    }

    public function test_sin_zipkey_no_hay_nada_que_consultar(): void
    {
        $this->completarTodo(conHabilitacion: false);

        $this->artisan('dian:estado-set', ['--empresa' => $this->empresa->id])
            ->expectsOutputToContain('No hay ninguna ZipKey')
            ->assertExitCode(1);
    }

    public function test_consultar_el_set_usa_la_zipkey_guardada(): void
    {
        $transporte = new FakeDianTransport();
        $transporte->responder(new TransmissionResult(
            TransmissionResult::ACEPTADO,
            respuesta: $this->respuestaDeEstado(aprobado: true),
        ));
        $this->app->instance(DianTestSetTransport::class, $transporte);

        $this->completarTodo(conHabilitacion: false);
        DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->update(['test_set_zip_key' => 'zip-key-123']);

        $this->artisan('dian:estado-set', ['--empresa' => $this->empresa->id])
            ->expectsOutputToContain('APROBADO')
            ->assertExitCode(0);

        $this->assertSame(['zip-key-123'], $transporte->setsConsultados);
    }

    public function test_un_set_no_aprobado_dice_que_hay_que_corregir(): void
    {
        // Es el caso que importa: «entregado» no ayuda a nadie; lo que
        // hace falta es saber CUÁL documento falló y por qué.
        $transporte = new FakeDianTransport();
        $transporte->responder(new TransmissionResult(
            TransmissionResult::ACEPTADO,
            respuesta: $this->respuestaDeEstado(aprobado: false),
        ));
        $this->app->instance(DianTestSetTransport::class, $transporte);

        $this->completarTodo(conHabilitacion: false);

        $this->artisan('dian:estado-set', [
            '--empresa' => $this->empresa->id,
            '--zipkey' => 'zip-key-456',
        ])
            ->expectsOutputToContain('TODAVÍA NO está aprobado')
            ->assertExitCode(0);
    }

    public function test_se_lee_el_veredicto_de_cada_documento(): void
    {
        $estado = \App\Billing\Dian\Transport\SoapDianTransport::interpretarEstadoDelSet(
            $this->respuestaDeEstado(aprobado: false),
        );

        $this->assertFalse($estado['aprobado']);
        $this->assertSame('99', $estado['codigo']);
        $this->assertContains('Regla: FAB01. Rechazo: El campo CUFE no cumple.', $estado['errores']);
        $this->assertSame('SETP990000001.xml', $estado['documentos'][0]['archivo']);
    }

    /**
     * «Procesado» NO es «aprobado».
     *
     * El servicio devuelve un código de proceso que dice que leyó el
     * lote, y aparte el veredicto. Confundirlos daría por habilitada
     * una empresa que no lo está.
     */
    public function test_procesado_con_el_veredicto_en_falso_no_es_aprobado(): void
    {
        $xml = str_replace('<b:IsValid>true</b:IsValid>', '<b:IsValid>false</b:IsValid>',
            $this->respuestaDeEstado(aprobado: true));

        $estado = \App\Billing\Dian\Transport\SoapDianTransport::interpretarEstadoDelSet($xml);

        $this->assertSame('00', $estado['codigo']);
        $this->assertFalse($estado['aprobado']);
    }

    /** Una respuesta de GetStatusZip con la forma que documenta la DIAN. */
    private function respuestaDeEstado(bool $aprobado): string
    {
        return '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">'
            . '<s:Body><GetStatusZipResponse xmlns="http://wcf.dian.colombia">'
            . '<GetStatusZipResult xmlns:b="http://schemas.datacontract.org/2004/07/">'
            . '<b:StatusCode>' . ($aprobado ? '00' : '99') . '</b:StatusCode>'
            . '<b:StatusDescription>' . ($aprobado ? 'Procesado Correctamente.' : 'Documento con errores en campos mandatorios.') . '</b:StatusDescription>'
            . '<b:StatusMessage>' . ($aprobado ? 'La Factura electrónica ha sido autorizada.' : '') . '</b:StatusMessage>'
            . '<b:IsValid>' . ($aprobado ? 'true' : 'false') . '</b:IsValid>'
            . ($aprobado ? '' : '<b:ErrorMessage><b:string>Regla: FAB01. Rechazo: El campo CUFE no cumple.</b:string></b:ErrorMessage>')
            . '<b:DianResponse>'
            . '<b:XmlDocumentKey>abc123</b:XmlDocumentKey>'
            . '<b:XmlFileName>SETP990000001.xml</b:XmlFileName>'
            . '<b:IsValid>' . ($aprobado ? 'true' : 'false') . '</b:IsValid>'
            . '<b:StatusDescription>' . ($aprobado ? 'Procesado Correctamente.' : 'Rechazado.') . '</b:StatusDescription>'
            . '</b:DianResponse>'
            . '</GetStatusZipResult></GetStatusZipResponse></s:Body></s:Envelope>';
    }

    // ==================== Apoyo ====================

    private function revision(): DianReadiness
    {
        return new DianReadiness();
    }

    /**
     * Deja la empresa con todo lo que hace falta para emitir.
     *
     * `$conHabilitacion` existe porque este ayudante escribia
     * `enabled_at` — el dato que SOLO puede producir `dian:habilitar`—
     * y con eso tapaba el estado real de una empresa que aun no ha
     * pasado a produccion. Todas las pruebas de encendido corrian
     * sobre una empresa que ya estaba habilitada.
     */
    private function completarTodo(bool $conHabilitacion = true): void
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
                'enabled_at' => $conHabilitacion ? now() : null,
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

    // ============ Encender en PRUEBAS, para la habilitacion ============

    public function test_se_puede_encender_la_emision_sin_irse_a_produccion(): void
    {
        // Es la otra mitad del bloqueo que se corrigio en el decisor.
        // Alli se permitio emitir en pruebas; aqui se permite ENCENDER
        // sin pasar a produccion, que era lo que faltaba: el
        // interruptor de la empresa solo lo movia `dian:habilitar` en
        // su modo normal, y ese ademas anota la habilitacion como
        // aprobada.
        //
        // Sin esto no habia forma de armar el set de pruebas: para
        // producir documentos hace falta el interruptor, y encenderlo
        // obligaba a irse a produccion sin haber pasado el set.
        $this->artisan('dian:habilitar --pruebas --empresa=' . $this->empresa->id)
            ->assertSuccessful();

        $this->empresa->refresh();

        $this->assertTrue($this->empresa->electronic_invoicing_enabled);
        $this->assertSame(
            DianConfiguration::PRUEBAS,
            $this->empresa->dianConfiguration->environment_code,
        );
    }

    public function test_encender_en_pruebas_no_anota_la_habilitacion(): void
    {
        // `enabled_at` sigue en null porque la DIAN no ha aprobado
        // nada. Es lo que impide que un despiste con el ambiente acabe
        // emitiendo en produccion sin haber pasado el set.
        $this->artisan('dian:habilitar --pruebas --empresa=' . $this->empresa->id);

        $this->assertNull($this->empresa->fresh()->dianConfiguration->enabled_at);
    }

    public function test_no_se_usa_para_volver_atras_desde_produccion(): void
    {
        // Una empresa ya habilitada que quiera volver a pruebas usa
        // --apagar, que es explicito. Que --pruebas lo hiciera de
        // callada seria apagar la facturacion de una empresa viva
        // creyendo que se esta preparando una habilitacion.
        DianConfiguration::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $this->empresa->id],
            ['environment_code' => DianConfiguration::PRODUCCION, 'enabled_at' => now()],
        );

        $this->artisan('dian:habilitar --pruebas --empresa=' . $this->empresa->id)
            ->assertFailed();

        $this->assertSame(
            DianConfiguration::PRODUCCION,
            $this->empresa->fresh()->dianConfiguration->environment_code,
        );
    }
}
