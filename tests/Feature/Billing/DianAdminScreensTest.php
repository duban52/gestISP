<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
use App\Models\DianResolution;
use App\Models\NumberingRange;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las pantallas de administración DIAN.
 *
 * QUÉ CIERRAN
 * -----------
 * Hasta ahora la configuración DIAN, el certificado, las resoluciones y
 * los rangos **solo se podían crear por consola**: poner el sistema a
 * facturar electrónicamente exigía un programador delante.
 *
 * LO QUE MÁS SE DEFIENDE AQUÍ NO ES EL CRUD
 * -----------------------------------------
 * Es lo que rodea al certificado, porque es una **clave privada**: con
 * ella se firma todo lo que la empresa le presenta a la DIAN.
 *
 *   · que se guarde FUERA del directorio público,
 *   · que no haya forma de descargarlo,
 *   · que ni su contraseña ni el PIN ni la clave técnica se devuelvan
 *     nunca al formulario,
 *   · y que no se acepte uno que no abre — descubrirlo el día de firmar
 *     es descubrirlo a mitad de la corrida mensual.
 *
 * Y la otra: que no se pueda tocar la configuración de OTRA empresa
 * metiendo su id en la URL. Con datos fiscales eso no es un bug
 * cualquiera: es emitir con la autorización de otro contribuyente.
 */
class DianAdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private Branch $sucursal;
    private User $admin;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        foreach (['dian.index', 'dian.manage'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
            $this->rol->givePermissionTo($permiso);
        }

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole($this->rol);
        $this->admin->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($this->admin)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer($this->empresa->id, [$this->sucursal->id], $this->sucursal->id);
    }

    // ==================== El panel ====================

    public function test_el_panel_dice_que_falta(): void
    {
        // Es la pregunta que trae aquí a la gente. Es el mismo
        // diagnóstico que `php artisan dian:diagnostico`.
        $this->get(route('dian.panel', $this->empresa))
            ->assertOk()
            ->assertSee('Qué falta para poder emitir', escape: false)
            ->assertSee('Certificado digital', escape: false);
    }

    public function test_el_menu_lleva_a_la_empresa_del_contexto(): void
    {
        // Desde el menu no hay forma de decir de que empresa se trata,
        // asi que se usa la del contexto activo — que es precisamente
        // la empresa en la que uno esta trabajando.
        $this->get(route('dian.actual'))
            ->assertRedirect(route('dian.panel', $this->empresa));
    }

    public function test_sin_contexto_el_menu_pide_elegir_empresa(): void
    {
        // Configurar la DIAN de una empresa creyendo estar en otra es
        // exactamente lo que no puede pasar.
        //
        // Se llama al controlador directamente y no por HTTP a
        // proposito: por HTTP el middleware repone el contexto desde la
        // sesion —que es lo correcto—, asi que la rama «sin contexto»
        // no se alcanzaria nunca desde una peticion con sesion.
        $contexto = app(CurrentContext::class);
        $contexto->limpiar();

        $respuesta = app(\App\Http\Controllers\DianAdminController::class)->actual($contexto);

        $this->assertSame(route('companies.index'), $respuesta->getTargetUrl());
    }

    public function test_sin_permiso_no_se_ve(): void
    {
        $this->rol->revokePermissionTo('dian.index');

        $this->get(route('dian.panel', $this->empresa))->assertForbidden();
    }

    public function test_mirar_no_da_derecho_a_tocar(): void
    {
        // Quien lleva la facturación necesita ver qué falta; eso no le
        // da por qué dar acceso al certificado.
        $this->rol->revokePermissionTo('dian.manage');

        $this->get(route('dian.panel', $this->empresa))->assertOk();

        $this->put(route('dian.configuracion', $this->empresa), ['software_id' => 'x'])
            ->assertForbidden();
    }

    // ==================== La configuración ====================

    public function test_se_guarda_la_configuracion(): void
    {
        $this->put(route('dian.configuracion', $this->empresa), [
            'software_id' => 'fa326ca7-c1f8-40d3-a6fc-24d7c1040607',
            'software_pin' => '12345',
            'test_set_id' => 'set-abc',
        ])->assertRedirect();

        $configuracion = DianConfiguration::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)->firstOrFail();

        $this->assertSame('fa326ca7-c1f8-40d3-a6fc-24d7c1040607', $configuracion->software_id);
        $this->assertSame('12345', $configuracion->software_pin);
    }

    public function test_el_pin_no_se_devuelve_nunca_al_formulario(): void
    {
        // Un campo que trae el secreto lo pone en el HTML, en la caché
        // del navegador y en el historial de quien lo mire.
        DianConfiguration::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'environment_code' => DianConfiguration::PRUEBAS,
            'software_id' => 'software-1',
            'software_pin' => 'PIN-SUPER-SECRETO',
        ]);

        $this->get(route('dian.panel', $this->empresa))
            ->assertOk()
            ->assertSee('software-1', escape: false)
            ->assertDontSee('PIN-SUPER-SECRETO', escape: false);
    }

    public function test_dejar_el_pin_vacio_no_lo_borra(): void
    {
        // Vacío significa «no lo cambies», no «bórralo»: el campo llega
        // siempre en blanco porque el PIN no se devuelve.
        DianConfiguration::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'environment_code' => DianConfiguration::PRUEBAS,
            'software_pin' => 'el-de-antes',
        ]);

        $this->put(route('dian.configuracion', $this->empresa), [
            'software_id' => 'otro',
            'software_pin' => '',
        ])->assertRedirect();

        $this->assertSame(
            'el-de-antes',
            DianConfiguration::withoutGlobalScopes()->where('company_id', $this->empresa->id)->first()->software_pin,
        );
    }

    // ==================== El certificado ====================

    public function test_el_certificado_se_guarda_fuera_del_directorio_publico(): void
    {
        // ES LA PRUEBA MÁS IMPORTANTE. Contiene la clave privada con la
        // que se firma todo lo que se le presenta a la DIAN.
        [$p12] = $this->certificado();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'prueba',
        ])->assertRedirect();

        $certificado = DianCertificate::firstOrFail();

        $this->assertStringStartsWith('dian/certificados/', $certificado->path);
        $this->assertStringNotContainsString('public', $certificado->path);

        // Y está de verdad en el disco privado, no en el público.
        $this->assertTrue(Storage::disk('local')->exists($certificado->path));
        $this->assertFalse(Storage::disk('public')->exists($certificado->path));
    }

    public function test_no_se_acepta_un_certificado_que_no_abre(): void
    {
        // Guardar uno que no abre significa descubrirlo el día de
        // firmar, a mitad de la corrida mensual, con consecutivos
        // autorizados ya gastados.
        [$p12] = $this->certificado();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'la-que-no-es',
        ])->assertSessionHasErrors('certificado');

        $this->assertSame(0, DianCertificate::count());
    }

    public function test_un_archivo_que_no_es_un_p12_se_rechaza(): void
    {
        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('cualquiera.p12', 'esto no es un certificado'),
            'password' => 'prueba',
        ])->assertSessionHasErrors('certificado');

        $this->assertSame(0, DianCertificate::count());
    }

    public function test_las_fechas_salen_del_certificado_y_no_de_quien_lo_sube(): void
    {
        // Teclearlas a mano permite equivocarse o mentir, y una vigencia
        // falsa hace que el aviso de caducidad llegue tarde.
        [$p12] = $this->certificado();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'prueba',
            // Se mandan fechas mentirosa a propósito: deben ignorarse.
            'valid_from' => '1990-01-01',
            'valid_until' => '2099-12-31',
        ])->assertRedirect();

        $certificado = DianCertificate::firstOrFail();

        $this->assertTrue($certificado->valid_until->isBefore(now()->addYears(2)));
        $this->assertTrue($certificado->valid_from->isAfter(now()->subYears(2)));
    }

    public function test_la_contrasena_del_certificado_no_se_devuelve(): void
    {
        [$p12] = $this->certificado();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'CLAVE-DEL-CERTIFICADO',
        ]);

        $this->get(route('dian.panel', $this->empresa))
            ->assertOk()
            ->assertDontSee('CLAVE-DEL-CERTIFICADO', escape: false);
    }

    public function test_cargar_uno_nuevo_desactiva_el_anterior(): void
    {
        // Dos activos dejarían sin decidir con cuál se firma, y esa
        // clase de ambigüedad se descubre tarde.
        [$p12] = $this->certificado();

        $viejo = DianCertificate::create([
            'company_id' => $this->empresa->id,
            'name' => 'El viejo',
            'path' => 'dian/certificados/viejo.p12',
            'password' => 'x',
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addMonth(),
            'active' => true,
        ]);

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'prueba',
        ])->assertRedirect();

        $this->assertFalse($viejo->fresh()->active);
        $this->assertSame(1, DianCertificate::where('active', true)->count());
    }

    public function test_desactivar_no_borra_el_archivo(): void
    {
        // Con él se firmaron documentos ya transmitidos: poder demostrar
        // con qué certificado se firmó cada uno es parte de poder
        // explicarlos.
        [$p12] = $this->certificado();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
            'password' => 'prueba',
        ]);

        $certificado = DianCertificate::firstOrFail();

        $this->delete(route('dian.certificados.destroy', [$this->empresa, $certificado]))
            ->assertRedirect();

        $this->assertFalse($certificado->fresh()->active);
        $this->assertTrue(Storage::disk('local')->exists($certificado->path));
    }

    // ==================== Resoluciones y rangos ====================

    public function test_se_registra_una_resolucion(): void
    {
        $this->post(route('dian.resoluciones.store', $this->empresa), $this->datosResolucion())
            ->assertRedirect();

        $resolucion = DianResolution::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('18760000001', $resolucion->resolution_number);
        $this->assertSame('clave-tecnica', $resolucion->technical_key);
    }

    public function test_la_clave_tecnica_no_se_devuelve(): void
    {
        // No viaja en ningún XML: entra en el CUFE, y es lo que impide
        // falsificarlo conociendo solo los datos de la factura.
        $this->post(route('dian.resoluciones.store', $this->empresa), $this->datosResolucion([
            'technical_key' => 'CLAVE-TECNICA-SECRETA',
        ]));

        $resolucion = DianResolution::withoutGlobalScopes()->firstOrFail();

        $this->get(route('dian.resolucion', [$this->empresa, $resolucion]))
            ->assertOk()
            ->assertDontSee('CLAVE-TECNICA-SECRETA', escape: false);
    }

    public function test_no_se_repite_una_resolucion(): void
    {
        $this->post(route('dian.resoluciones.store', $this->empresa), $this->datosResolucion());

        $this->post(route('dian.resoluciones.store', $this->empresa), $this->datosResolucion())
            ->assertSessionHasErrors('resolution_number');
    }

    public function test_la_vigencia_tiene_que_terminar_despues_de_empezar(): void
    {
        $this->post(route('dian.resoluciones.store', $this->empresa), $this->datosResolucion([
            'valid_from' => '2026-12-31',
            'valid_until' => '2026-01-01',
        ]))->assertSessionHasErrors('valid_until');
    }

    public function test_se_registra_un_rango(): void
    {
        $resolucion = $this->resolucion();

        $this->post(route('dian.rangos.store', [$this->empresa, $resolucion]), [
            'prefix' => 'setp',
            'range_start' => 990000000,
            'range_end' => 995000000,
            'active' => '1',
        ])->assertRedirect();

        $rango = NumberingRange::withoutGlobalScopes()->firstOrFail();

        // El prefijo se guarda en mayúsculas: es como va en el XML.
        $this->assertSame('SETP', $rango->prefix);
        $this->assertSame($this->empresa->id, $rango->company_id);
    }

    public function test_no_se_pueden_tener_dos_rangos_activos_con_el_mismo_prefijo(): void
    {
        // La base lo impide, pero el error crudo no dice nada: sería
        // emitir dos veces el mismo número ante la DIAN aunque la base
        // los viera distintos.
        $resolucion = $this->resolucion();

        $datos = ['prefix' => 'SETP', 'range_start' => 1, 'range_end' => 1000, 'active' => '1'];

        $this->post(route('dian.rangos.store', [$this->empresa, $resolucion]), $datos);

        $this->post(route('dian.rangos.store', [$this->empresa, $resolucion]), array_merge($datos, [
            'range_start' => 2000,
            'range_end' => 3000,
        ]))->assertSessionHasErrors('prefix');
    }

    public function test_el_rango_final_tiene_que_ser_mayor_que_el_inicial(): void
    {
        $resolucion = $this->resolucion();

        $this->post(route('dian.rangos.store', [$this->empresa, $resolucion]), [
            'prefix' => 'SETP',
            'range_start' => 1000,
            'range_end' => 500,
        ])->assertSessionHasErrors('range_end');
    }

    public function test_no_se_puede_dejar_fuera_lo_ya_gastado(): void
    {
        // Un rango que empieza después de donde va repetiría números ya
        // emitidos.
        $resolucion = $this->resolucion();

        $rango = NumberingRange::withoutGlobalScopes()->create([
            'dian_resolution_id' => $resolucion->id,
            'prefix' => 'SETP',
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $this->put(route('dian.rangos.update', [$this->empresa, $resolucion, $rango]), [
            'prefix' => 'SETP',
            'range_start' => 600,
            'range_end' => 2000,
        ])->assertSessionHasErrors('range_start');
    }

    public function test_una_sucursal_de_otra_empresa_no_vale(): void
    {
        $resolucion = $this->resolucion();
        $ajena = Branch::factory()->create();

        $this->post(route('dian.rangos.store', [$this->empresa, $resolucion]), [
            'prefix' => 'SETP',
            'range_start' => 1,
            'range_end' => 1000,
            'branch_id' => $ajena->id,
        ])->assertSessionHasErrors('branch_id');
    }

    // ==================== Aislamiento entre empresas ====================

    public function test_no_se_toca_la_resolucion_de_otra_empresa(): void
    {
        // Con datos fiscales esto no es un bug cualquiera: es emitir con
        // la autorización de otro contribuyente.
        $otra = Company::factory()->create();

        $ajena = DianResolution::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'resolution_number' => '999',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => 'x',
        ]);

        $this->get(route('dian.resolucion', [$this->empresa, $ajena]))->assertNotFound();

        $this->put(route('dian.resoluciones.update', [$this->empresa, $ajena]), $this->datosResolucion())
            ->assertNotFound();
    }

    public function test_no_se_desactiva_el_certificado_de_otra_empresa(): void
    {
        $otra = Company::factory()->create();

        $ajeno = DianCertificate::create([
            'company_id' => $otra->id,
            'name' => 'Ajeno',
            'path' => 'dian/certificados/ajeno.p12',
            'password' => 'x',
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'active' => true,
        ]);

        $this->delete(route('dian.certificados.destroy', [$this->empresa, $ajeno]))->assertNotFound();

        $this->assertTrue($ajeno->fresh()->active);
    }

    // ==================== Apoyo ====================

    /** @param array<string, mixed> $extra */
    private function datosResolucion(array $extra = []): array
    {
        return array_merge([
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
            'technical_key' => 'clave-tecnica',
            'active' => '1',
        ], $extra);
    }

    private function resolucion(): DianResolution
    {
        return DianResolution::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'resolution_number' => '18760000001',
            'document_type_code' => DianResolution::FACTURA,
            'valid_from' => now()->subYear(),
            'valid_until' => now()->addYear(),
            'technical_key' => 'clave',
        ]);
    }

    /** @return array{0: string} el .p12 en binario */
    private function certificado(): array
    {
        $opciones = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $llave = @openssl_pkey_new($opciones);

        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $ruta) {
            if ($llave === false && $ruta && is_file($ruta)) {
                $opciones['config'] = $ruta;
                $llave = @openssl_pkey_new($opciones);
            }
        }

        if ($llave === false) {
            $this->markTestSkipped('OpenSSL no puede generar claves en esta máquina.');
        }

        $extra = isset($opciones['config']) ? ['config' => $opciones['config']] : [];

        $solicitud = openssl_csr_new(
            ['countryName' => 'CO', 'commonName' => 'Fibra Andina S.A.S.'],
            $llave,
            $extra + ['digest_alg' => 'sha256'],
        );
        $cert = openssl_csr_sign($solicitud, null, $llave, 365, $extra + ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($cert, $p12, $llave, 'prueba', $extra);

        return [$p12];
    }
}
