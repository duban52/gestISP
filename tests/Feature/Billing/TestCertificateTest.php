<?php

namespace Tests\Feature\Billing;

use App\Billing\Dian\DianReadiness;
use App\Billing\Dian\SelfSignedCertificate;
use App\Billing\Dian\XadesSigner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DianCertificate;
use App\Models\DianConfiguration;
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
 * El certificado de pruebas autofirmado.
 *
 * QUÉ PROBLEMA RESUELVE
 * ---------------------
 * Conseguir el certificado de verdad depende de un tercero —una entidad
 * acreditada por la ONAC— y puede tardar semanas. Sin él, todo lo que
 * hay después de armar el XML quedaba sin poder verse funcionando:
 * firma, QR, estado FIRMADO en pantalla, transmisión.
 *
 * Este comando genera uno autofirmado para recorrer ese camino entero
 * mientras tanto.
 *
 * LO QUE DE VERDAD SE DEFIENDE AQUÍ
 * ---------------------------------
 * No que se genere —eso es lo fácil— sino **que no se pueda confundir
 * con uno de verdad**. Un certificado de pruebas olvidado en una
 * empresa que factura significa firmar un mes entero con algo que la
 * DIAN rechaza, y descubrirlo cuando ya se gastaron los consecutivos
 * autorizados.
 *
 * De ahí los tres candados que se comprueban abajo: que no se genere en
 * producción, que quede marcado en la base, y que el diagnóstico lo
 * declare BLOQUEANTE aunque esté ahí y vigente.
 *
 * Y la otra mitad: que el marcado no dependa de este comando. Un
 * autofirmado subido a mano por la pantalla tiene que quedar marcado
 * igual — quien lo sube es justamente quien puede no saber que lo es.
 */
class TestCertificateTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private Branch $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->empresa = Company::factory()->create(['legal_name' => 'Fibra Andina S.A.S.']);
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        Storage::fake('local');
    }

    // ==================== El comando ====================

    public function test_genera_un_certificado_y_lo_deja_activo(): void
    {
        $this->saltarSiOpensslNoPuede();

        $this->artisan('dian:certificado-de-pruebas', ['--empresa' => $this->empresa->id])
            ->assertSuccessful();

        $certificado = DianCertificate::withoutGlobalScopes()
            ->where('company_id', $this->empresa->id)
            ->firstOrFail();

        $this->assertTrue($certificado->active);
        $this->assertTrue($certificado->self_signed);
        $this->assertTrue($certificado->vigente());
        Storage::disk('local')->assertExists($certificado->path);
    }

    public function test_el_archivo_generado_abre_con_su_contrasena(): void
    {
        // Si no abriera, todo lo demás sería decorado: el certificado
        // existe para firmar.
        $this->saltarSiOpensslNoPuede();

        $this->artisan('dian:certificado-de-pruebas', ['--empresa' => $this->empresa->id]);

        $certificado = DianCertificate::withoutGlobalScopes()->firstOrFail();
        $contenido = Storage::disk('local')->get($certificado->path);

        $leido = [];
        $this->assertTrue(openssl_pkcs12_read($contenido, $leido, $certificado->password));
        $this->assertArrayHasKey('pkey', $leido);
    }

    public function test_lo_que_firma_con_el_verifica_de_verdad(): void
    {
        // Este es el sentido entero del comando: que la MECÁNICA de la
        // firma quede ejercitada igual que con un certificado real. Lo
        // único que le falta es quién lo avala.
        $this->saltarSiOpensslNoPuede();

        $p12 = (new SelfSignedCertificate())->generar(
            ['countryName' => 'CO', 'commonName' => 'Fibra Andina S.A.S.'],
            'clave-de-prueba',
        );

        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
          <ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>
          <ID>SETP990000001</ID>
        </Invoice>
        XML;

        $firmado = (new XadesSigner())->firmar($xml, $p12, 'clave-de-prueba');

        $this->assertStringContainsString('ds:Signature', $firmado);
        $this->assertStringContainsString('ds:SignatureValue', $firmado);
    }

    public function test_se_niega_en_produccion(): void
    {
        // El candado que más importa. En producción la firma no es una
        // prueba: es lo que sostiene cada factura emitida.
        DianConfiguration::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'environment_code' => DianConfiguration::PRODUCCION,
        ]);

        $this->artisan('dian:certificado-de-pruebas', ['--empresa' => $this->empresa->id])
            ->assertFailed();

        $this->assertSame(0, DianCertificate::withoutGlobalScopes()->count());
    }

    public function test_desactiva_el_certificado_anterior(): void
    {
        // Dos activos dejarían sin decidir con cuál se firma, y esa
        // ambigüedad se descubre tarde.
        $this->saltarSiOpensslNoPuede();

        $anterior = DianCertificate::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'El de antes',
            'path' => 'dian/certificados/viejo.p12',
            'password' => 'x',
            'active' => true,
        ]);

        $this->artisan('dian:certificado-de-pruebas', ['--empresa' => $this->empresa->id]);

        $this->assertFalse($anterior->fresh()->active);
        $this->assertSame(1, DianCertificate::withoutGlobalScopes()->where('active', true)->count());
    }

    public function test_con_varias_empresas_exige_decir_cual(): void
    {
        // Generar el certificado en la empresa equivocada es de los
        // errores que no se ven hasta que algo se firma mal.
        Company::factory()->create();

        $this->artisan('dian:certificado-de-pruebas')->assertFailed();

        $this->assertSame(0, DianCertificate::withoutGlobalScopes()->count());
    }

    // ==================== El diagnóstico ====================

    public function test_el_diagnostico_lo_declara_bloqueante(): void
    {
        // Está ahí, está activo y está vigente — y aun así no se puede
        // emitir. La pregunta que contesta el diagnóstico no es «¿se
        // puede firmar?» sino «¿se puede emitir?».
        DianCertificate::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'PRUEBAS — autofirmado',
            'path' => 'dian/certificados/pruebas.p12',
            'password' => 'x',
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'active' => true,
            'self_signed' => true,
        ]);

        $pasos = collect(app(DianReadiness::class)->revisar($this->empresa));
        $paso = $pasos->firstWhere('clave', 'certificado');

        $this->assertFalse($paso['ok']);
        $this->assertTrue($paso['bloqueante']);
        $this->assertStringContainsString('AUTOFIRMADO', $paso['detalle']);
        $this->assertFalse(app(DianReadiness::class)->puedeEmitir($this->empresa));
    }

    public function test_uno_real_no_se_marca_como_de_pruebas(): void
    {
        DianCertificate::withoutGlobalScopes()->create([
            'company_id' => $this->empresa->id,
            'name' => 'El de verdad',
            'path' => 'dian/certificados/real.p12',
            'password' => 'x',
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'active' => true,
        ]);

        $paso = collect(app(DianReadiness::class)->revisar($this->empresa))
            ->firstWhere('clave', 'certificado');

        $this->assertTrue($paso['ok']);
    }

    // ==================== Subido a mano ====================

    public function test_subir_un_autofirmado_por_la_pantalla_lo_marca(): void
    {
        // El marcado no puede depender del comando: quien sube un .p12
        // es justamente quien puede no saber que es autofirmado — desde
        // fuera se ven igual.
        $this->saltarSiOpensslNoPuede();

        $p12 = (new SelfSignedCertificate())->generar(
            ['countryName' => 'CO', 'commonName' => 'Fibra Andina S.A.S.'],
            'secreta',
        );

        $this->comoAdministrador();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('cert.p12', $p12),
            'password' => 'secreta',
        ])->assertRedirect();

        $certificado = DianCertificate::withoutGlobalScopes()->firstOrFail();

        $this->assertTrue($certificado->self_signed);
    }

    public function test_uno_emitido_por_otra_autoridad_no_se_marca(): void
    {
        // La comprobación es emisor != titular. Aquí hay una CA de
        // mentira que firma a otro: es lo mismo que hace una entidad
        // acreditada, y no debe marcarse.
        $this->saltarSiOpensslNoPuede();

        $p12 = $this->emitidoPorUnaAutoridad('secreta');

        $this->comoAdministrador();

        $this->post(route('dian.certificados.store', $this->empresa), [
            'certificado' => UploadedFile::fake()->createWithContent('cert.p12', $p12),
            'password' => 'secreta',
        ])->assertRedirect();

        $this->assertFalse(DianCertificate::withoutGlobalScopes()->firstOrFail()->self_signed);
    }

    // ==================== Andamiaje ====================

    private function comoAdministrador(): void
    {
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        foreach (['dian.index', 'dian.manage'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
            $rol->givePermissionTo($permiso);
        }

        $admin = User::factory()->create();
        $admin->assignRole($rol);
        $admin->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $rol->id,
        ]);

        app(CurrentContext::class)->establecer($this->empresa->id, [$this->sucursal->id], $this->sucursal->id);
    }

    /** Un certificado firmado por OTRO: emisor distinto del titular. */
    private function emitidoPorUnaAutoridad(string $clave): string
    {
        $extra = ($cnf = $this->configuracionDeOpenssl()) !== null ? ['config' => $cnf] : [];
        $opciones = $extra + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $llaveCa = openssl_pkey_new($opciones);
        $csrCa = openssl_csr_new(['countryName' => 'CO', 'commonName' => 'Autoridad de Mentira'], $llaveCa, $extra + ['digest_alg' => 'sha256']);
        $ca = openssl_csr_sign($csrCa, null, $llaveCa, 3650, $extra + ['digest_alg' => 'sha256']);

        $llave = openssl_pkey_new($opciones);
        $csr = openssl_csr_new(['countryName' => 'CO', 'commonName' => 'Fibra Andina S.A.S.'], $llave, $extra + ['digest_alg' => 'sha256']);
        $certificado = openssl_csr_sign($csr, $ca, $llaveCa, 365, $extra + ['digest_alg' => 'sha256']);

        $p12 = '';
        openssl_pkcs12_export($certificado, $p12, $llave, $clave, $extra);

        return $p12;
    }

    /**
     * En Windows openssl no encuentra su configuración solo.
     *
     * Se salta en vez de fallar: es una limitación de la máquina de
     * desarrollo, no del código, y en el servidor no ocurre.
     */
    private function saltarSiOpensslNoPuede(): void
    {
        if (@openssl_pkey_new(['private_key_bits' => 2048]) !== false) {
            return;
        }

        if ($this->configuracionDeOpenssl() === null) {
            $this->markTestSkipped('OpenSSL no puede generar claves en esta máquina (no se encontró openssl.cnf).');
        }
    }

    private function configuracionDeOpenssl(): ?string
    {
        $candidatos = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ];

        foreach (array_filter($candidatos) as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }
}
