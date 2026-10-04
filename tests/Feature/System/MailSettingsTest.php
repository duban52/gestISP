<?php

namespace Tests\Feature\System;

use App\Mail\CorreoDelSistema;
use App\Models\Branch;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Envío de correos: configuración, interruptor y bitácora.
 *
 * QUÉ RESUELVE
 * ------------
 * El SMTP vivía en el `.env` del servidor: cambiar de proveedor o
 * corregir una contraseña exigía entrar por SSH. Y un correo que no
 * salía no dejaba rastro en ninguna parte — el cliente llamaba tres
 * semanas después diciendo que nunca recibió la factura y no había
 * forma de saber si se envió, si rebotó o si la contraseña llevaba un
 * mes mal escrita.
 *
 * LO QUE NO PUEDE FALLAR, EN ORDEN DE GRAVEDAD
 * --------------------------------------------
 *  1. Que sin configurar nada el correo siga saliendo como siempre.
 *     Esto se despliega sobre un sistema en producción que factura.
 *  2. Que el interruptor apague DE VERDAD, y que lo que no salió
 *     quede anotado.
 *  3. Que un fallo deje el motivo, no un silencio.
 *  4. Que la contraseña no vuelva nunca a la pantalla.
 */
class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->superadmin = User::factory()->create(['number_phone' => '3000000000']);
        $this->superadmin->assignRole($rol);
        $this->superadmin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->superadmin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    private function guardar(array $datos = [])
    {
        return $this->put(route('mail.settings.update'), array_merge([
            'enabled' => '1',
            'host' => 'smtp.ejemplo.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'usuario@ejemplo.com',
            'password' => 'clave-secreta',
            'from_address' => 'facturacion@ejemplo.com',
            'from_name' => 'ISP de pruebas',
        ], $datos));
    }

    // ==================== Nada se rompe al desplegar ====================

    public function test_sin_configurar_nada_el_correo_sale_como_siempre(): void
    {
        // Es lo primero: esto llega a un sistema en produccion que ya
        // esta enviando facturas. Sin fila guardada, el .env manda.
        $this->assertSame(0, MailSetting::count());

        $anfitrionDelEnv = config('mail.mailers.smtp.host');

        app(CorreoDelSistema::class)->aplicarConfiguracion();

        $this->assertSame($anfitrionDelEnv, config('mail.mailers.smtp.host'));
        $this->assertTrue(MailSetting::vigente()->enabled, 'sin configuracion, el correo esta encendido');
    }

    public function test_un_campo_vacio_no_pisa_el_del_entorno(): void
    {
        // Rellenar solo el remitente no puede dejar el servidor vacio:
        // un campo en blanco es «deja el del .env», no «ponlo vacio».
        $anfitrion = config('mail.mailers.smtp.host');

        MailSetting::create([
            'enabled' => true,
            'from_address' => 'facturacion@ejemplo.com',
        ]);

        CorreoDelSistema::olvidarCache();
        app(CorreoDelSistema::class)->aplicarConfiguracion();

        $this->assertSame($anfitrion, config('mail.mailers.smtp.host'));
        $this->assertSame('facturacion@ejemplo.com', config('mail.from.address'));
    }

    public function test_lo_configurado_se_aplica_sobre_el_entorno(): void
    {
        $this->guardar()->assertRedirect();

        app(CorreoDelSistema::class)->aplicarConfiguracion();

        $this->assertSame('smtp.ejemplo.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('clave-secreta', config('mail.mailers.smtp.password'));
        $this->assertSame('facturacion@ejemplo.com', config('mail.from.address'));
    }

    // ==================== El interruptor ====================

    public function test_apagado_no_sale_ni_un_correo(): void
    {
        $this->guardar(['enabled' => '0'])->assertRedirect();
        CorreoDelSistema::olvidarCache();

        Mail::raw('contenido', fn ($m) => $m->to('cliente@ejemplo.com')->subject('Su factura'));

        // El oyente devuelve false en MessageSending: el correo no se
        // manda y queda anotado como «no se envio».
        $log = MailLog::latest('id')->first();

        $this->assertNotNull($log, 'el intento tiene que quedar anotado aunque no salga');
        $this->assertSame(MailLog::OMITIDO, $log->status);
        $this->assertSame('cliente@ejemplo.com', $log->to);
        $this->assertStringContainsString('deshabilitado', $log->error);
    }

    public function test_el_aviso_de_apagado_se_ve_en_la_pantalla(): void
    {
        $this->guardar(['enabled' => '0']);
        CorreoDelSistema::olvidarCache();

        $this->get(route('mail.settings'))
            ->assertOk()
            ->assertSee('No está saliendo ningún correo', false);
    }

    public function test_apagar_el_correo_queda_en_la_trazabilidad(): void
    {
        $this->guardar(['enabled' => '0']);

        $this->assertDatabaseHas('audits', ['action' => 'mail.settings_updated']);

        $registro = \DB::table('audits')->where('action', 'mail.settings_updated')->latest('id')->first();
        $this->assertStringContainsString('APAGÓ', $registro->description);
    }

    // ==================== La bitácora ====================

    public function test_un_envio_correcto_queda_anotado(): void
    {
        $this->guardar();
        CorreoDelSistema::olvidarCache();

        Mail::raw('contenido', fn ($m) => $m->to('cliente@ejemplo.com')->subject('Su factura EGP000123'));

        $log = MailLog::latest('id')->firstOrFail();

        $this->assertSame(MailLog::ENVIADO, $log->status);
        $this->assertSame('cliente@ejemplo.com', $log->to);
        $this->assertSame('Su factura EGP000123', $log->subject);
        $this->assertNotNull($log->sent_at);
    }

    public function test_el_log_traduce_el_motivo_del_fallo(): void
    {
        // «Expected response code 235 but got 535 Username and Password
        // not accepted» no le dice nada a quien administra el sistema.
        $log = new MailLog([
            'error' => 'Expected response code "235" but got code "535", with message '
                . '"535 5.7.8 Username and Password not accepted".',
        ]);

        $this->assertStringContainsString('usuario o la contraseña', $log->motivoEnClaro());

        $conexion = new MailLog(['error' => 'Connection could not be established with host smtp.x:587']);
        $this->assertStringContainsString('No se pudo conectar', $conexion->motivoEnClaro());

        $ritmo = new MailLog(['error' => '421 4.7.0 Too many messages']);
        $this->assertStringContainsString('límite de envío', $ritmo->motivoEnClaro());

        // Y lo que no se reconoce no se inventa
        $this->assertNull((new MailLog(['error' => 'algo rarísimo']))->motivoEnClaro());
    }

    public function test_la_bitacora_se_filtra_por_estado(): void
    {
        MailLog::create(['status' => MailLog::ENVIADO, 'to' => 'bueno@ejemplo.com', 'subject' => 'Salio bien']);
        MailLog::create(['status' => MailLog::FALLIDO, 'to' => 'malo@ejemplo.com', 'subject' => 'Se rompio']);

        $this->get(route('mail.settings', ['estado' => 'fallido']))
            ->assertOk()
            ->assertSee('malo@ejemplo.com', false)
            ->assertDontSee('bueno@ejemplo.com', false);
    }

    public function test_la_bitacora_se_poda(): void
    {
        $vieja = MailLog::create(['status' => MailLog::ENVIADO, 'to' => 'antigua@ejemplo.com']);
        $vieja->forceFill(['created_at' => now()->subDays(200)])->save();

        MailLog::create(['status' => MailLog::ENVIADO, 'to' => 'reciente@ejemplo.com']);

        $this->delete(route('mail.log.prune'), ['dias' => 90])->assertRedirect();

        $this->assertSame(0, MailLog::where('to', 'antigua@ejemplo.com')->count());
        $this->assertSame(1, MailLog::where('to', 'reciente@ejemplo.com')->count());
    }

    // ==================== La contraseña ====================

    public function test_la_contrasena_se_guarda_cifrada(): void
    {
        $this->guardar();

        $crudo = \DB::table('mail_settings')->value('password');

        $this->assertNotSame('clave-secreta', $crudo, 'la contraseña no puede estar en claro en la base');
        $this->assertSame('clave-secreta', MailSetting::first()->password);
    }

    public function test_la_contrasena_no_vuelve_a_la_pantalla(): void
    {
        $this->guardar();

        $this->get(route('mail.settings'))
            ->assertOk()
            ->assertDontSee('clave-secreta', false);
    }

    public function test_dejarla_vacia_no_la_borra(): void
    {
        // Es lo que permite corregir el puerto sin volver a teclear la
        // contraseña del SMTP.
        $this->guardar();

        $this->guardar(['password' => '', 'port' => 465]);

        $ajustes = MailSetting::first();

        $this->assertSame('clave-secreta', $ajustes->password);
        $this->assertSame(465, $ajustes->port);
    }

    public function test_la_contrasena_no_entra_en_la_trazabilidad(): void
    {
        $this->guardar();

        $registro = \DB::table('audits')->where('action', 'mail.settings_updated')->latest('id')->first();

        $this->assertStringNotContainsString('clave-secreta', (string) $registro->description);
        $this->assertStringNotContainsString('clave-secreta', (string) ($registro->metadata ?? ''));
    }

    // ==================== Seguridad ====================

    public function test_solo_el_superadministrador_entra(): void
    {
        $rol = Role::where('name', 'administrador')->firstOrFail();

        $usuario = User::factory()->create(['number_phone' => '3000000001']);
        $usuario->assignRole($rol);
        $usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($usuario)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('mail.settings'))->assertForbidden();
        $this->put(route('mail.settings.update'), ['enabled' => '0'])->assertForbidden();
        $this->post(route('mail.settings.test'), ['destino' => 'x@ejemplo.com'])->assertForbidden();
    }

    public function test_la_prueba_avisa_si_el_correo_esta_apagado(): void
    {
        $this->guardar(['enabled' => '0']);
        CorreoDelSistema::olvidarCache();

        $this->post(route('mail.settings.test'), ['destino' => 'yo@ejemplo.com'])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_la_prueba_sale_cuando_esta_encendido(): void
    {
        $this->guardar();
        CorreoDelSistema::olvidarCache();

        $this->post(route('mail.settings.test'), ['destino' => 'yo@ejemplo.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            MailLog::ENVIADO,
            MailLog::where('to', 'yo@ejemplo.com')->latest('id')->firstOrFail()->status,
        );
    }
}
