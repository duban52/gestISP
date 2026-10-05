<?php

namespace Tests\Feature\System;

use App\Mail\CorreoDeLaSucursal;
use App\Mail\CorreoDelSistema;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\MailSetting;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\InvoiceOverdue;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dos correos que no son el mismo.
 *
 * AL CLIENTE LE ESCRIBE SU OPERADOR
 * ---------------------------------
 * La factura, el aviso de vencimiento y la orden técnica salen de la
 * sede que lo atiende: con su dominio y con un buzón al que pueda
 * responder. Que una respuesta a la factura de San Andrés caiga en el
 * correo interno de GestISP no le sirve a nadie.
 *
 * EL RESTABLECIMIENTO DE CONTRASEÑA, NO
 * -------------------------------------
 * Ese es correo interno del panel y no tiene por qué salir del buzón
 * comercial de ninguna sucursal. Es la separación que se pidió y la
 * que estas pruebas defienden.
 *
 * Y LA CADENA DE RESPALDO
 * -----------------------
 * Sucursal → sistema → `.env`. Una sede sin configurar envía por
 * donde envía hoy: eso es lo que permite desplegar esto sobre un
 * sistema que está facturando.
 */
class BranchMailTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sanAndres;
    private Branch $yarumal;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sanAndres = Branch::factory()->create(['name' => 'San Andrés']);
        $this->yarumal = Branch::factory()->create([
            'name' => 'Yarumal',
            'company_id' => $this->sanAndres->company_id,
        ]);

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->sanAndres->id, ['role_id' => $rol->id]);
        $this->admin->branches()->attach($this->yarumal->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->sanAndres->id,
            'current_role_id' => (string) $rol->id,
        ]);
    }

    private function configurar(Branch $sucursal, array $extra = []): MailSetting
    {
        return MailSetting::create(array_merge([
            'branch_id' => $sucursal->id,
            'enabled' => true,
            'host' => 'smtp.' . strtolower(str_replace(' ', '', $sucursal->name)) . '.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'envios@' . strtolower(str_replace(' ', '', $sucursal->name)) . '.com',
            'password' => 'clave',
            'from_address' => 'facturacion@' . strtolower(str_replace(' ', '', $sucursal->name)) . '.com',
            'from_name' => $sucursal->name,
        ], $extra));
    }

    // ==================== Cada sede, su servidor ====================

    public function test_cada_sucursal_sale_por_su_propio_servidor(): void
    {
        $this->configurar($this->sanAndres);
        $this->configurar($this->yarumal);

        $delaSede = app(CorreoDeLaSucursal::class);

        $uno = $delaSede->mailerDe($this->sanAndres);
        $otro = $delaSede->mailerDe($this->yarumal);

        $this->assertNotSame($uno, $otro, 'las dos sedes comparten servidor');

        $this->assertSame('smtp.sanandrés.com', config("mail.mailers.{$uno}.host"));
        $this->assertSame('smtp.yarumal.com', config("mail.mailers.{$otro}.host"));
    }

    public function test_una_sucursal_sin_configurar_usa_el_del_sistema(): void
    {
        // Es lo que permite desplegar esto sobre un sistema que ya
        // está facturando sin que cambie nada.
        $this->assertNull(app(CorreoDeLaSucursal::class)->mailerDe($this->yarumal));
    }

    public function test_una_configuracion_sin_servidor_tampoco_cuenta(): void
    {
        // Guardar solo el remitente no convierte a la sede en
        // autónoma: sin servidor no hay por dónde enviar.
        $this->configurar($this->sanAndres, ['host' => null]);

        $this->assertNull(app(CorreoDeLaSucursal::class)->mailerDe($this->sanAndres));
    }

    public function test_el_remitente_es_el_de_la_sucursal(): void
    {
        $this->configurar($this->sanAndres);

        $remitente = app(CorreoDeLaSucursal::class)->remitenteDe($this->sanAndres);

        $this->assertSame('facturacion@sanandrés.com', $remitente['address']);
        $this->assertSame('San Andrés', $remitente['name']);
    }

    // ==================== El correo del cliente ====================

    public function test_el_aviso_al_cliente_sale_por_su_sucursal(): void
    {
        $this->configurar($this->sanAndres);

        $factura = $this->facturaDe($this->sanAndres);

        $correo = (new InvoiceOverdue($factura))->toMail($factura->contract->client);

        $this->assertSame('sucursal_' . $this->sanAndres->id, $correo->mailer);
        $this->assertSame('facturacion@sanandrés.com', $correo->from[0]);
    }

    public function test_el_aviso_de_otra_sede_sale_por_la_suya(): void
    {
        // La prueba de que no se resuelve una vez y vale para todos:
        // en una corrida salen mezcladas las dos sedes.
        $this->configurar($this->sanAndres);
        $this->configurar($this->yarumal);

        $deSanAndres = $this->facturaDe($this->sanAndres);
        $deYarumal = $this->facturaDe($this->yarumal);

        $uno = (new InvoiceOverdue($deSanAndres))->toMail($deSanAndres->contract->client);
        $otro = (new InvoiceOverdue($deYarumal))->toMail($deYarumal->contract->client);

        $this->assertSame('sucursal_' . $this->sanAndres->id, $uno->mailer);
        $this->assertSame('sucursal_' . $this->yarumal->id, $otro->mailer);
    }

    public function test_sin_configurar_el_aviso_sale_por_el_de_siempre(): void
    {
        $factura = $this->facturaDe($this->yarumal);

        $correo = (new InvoiceOverdue($factura))->toMail($factura->contract->client);

        $this->assertNull($correo->mailer, 'debería usar el mailer por defecto');
    }

    // ==================== El correo del sistema ====================

    public function test_el_restablecimiento_de_contrasena_no_usa_el_de_la_sucursal(): void
    {
        // Es la separación que se pidió: lo del panel es interno.
        $this->configurar($this->sanAndres);

        $correo = (new \Illuminate\Auth\Notifications\ResetPassword('token-de-prueba'))
            ->toMail($this->admin);

        $this->assertNull($correo->mailer, 'el correo interno no puede salir por una sucursal');
        $this->assertEmpty($correo->from, 'ni con su remitente');
    }

    // ==================== La pantalla ====================

    public function test_se_guarda_desde_la_ficha_de_la_sucursal(): void
    {
        $this->put(route('branches.mail.update', $this->sanAndres), [
            'host' => 'smtp.ejemplo.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'envios@ejemplo.com',
            'password' => 'abcd efgh ijkl mnop',
            'from_address' => 'facturacion@ejemplo.com',
            'from_name' => 'ISP San Andrés',
        ])->assertRedirect();

        $ajustes = MailSetting::deLaSucursal($this->sanAndres->id);

        $this->assertSame('smtp.ejemplo.com', $ajustes->host);
        // La misma limpieza que en el del sistema
        $this->assertSame('abcdefghijklmnop', $ajustes->password);
    }

    public function test_guardarla_no_toca_la_del_sistema(): void
    {
        MailSetting::create([
            'branch_id' => null,
            'enabled' => true,
            'host' => 'smtp.interno.com',
        ]);

        $this->put(route('branches.mail.update', $this->sanAndres), [
            'host' => 'smtp.sucursal.com',
        ])->assertRedirect();

        $this->assertSame('smtp.interno.com', MailSetting::vigente()->host);
        $this->assertSame('smtp.sucursal.com', MailSetting::deLaSucursal($this->sanAndres->id)->host);
    }

    public function test_no_se_toca_el_correo_de_una_sucursal_ajena(): void
    {
        $ajena = Branch::factory()->create(['name' => 'De otro']);

        $this->put(route('branches.mail.update', $ajena), ['host' => 'smtp.pirata.com'])
            ->assertForbidden();

        $this->assertNull(MailSetting::deLaSucursal($ajena->id));
    }

    public function test_la_ficha_ofrece_configurarlo(): void
    {
        $this->get(route('branches.edit', $this->sanAndres))
            ->assertOk()
            ->assertSee('Correo de esta sucursal', false)
            ->assertSee('Usa el del sistema', false);
    }

    /** Una factura vencida de un cliente de esa sucursal. */
    private function facturaDe(Branch $sucursal)
    {
        $cliente = Client::factory()->create([
            'branch_id' => $sucursal->id,
            'number_phone' => '311' . rand(1000000, 9999999),
            'aditional_phone' => '312' . rand(1000000, 9999999),
            'user_id' => $this->admin->id,
        ]);

        $plan = Plan::factory()->create([
            'name' => 'Plan ' . $sucursal->id . rand(100, 999),
            'branch_id' => $sucursal->id,
            'user_id' => $this->admin->id,
        ]);

        $contrato = Contract::factory()->create([
            'branch_id' => $sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => $plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
        ]);

        return \App\Models\Invoice::create([
            'contract_id' => $contrato->id,
            'branch_id' => $sucursal->id,
            'user_id' => $this->admin->id,
            'invoice_number' => 'F' . rand(10000, 99999),
            'issue_date' => now()->subDays(40),
            'due_date' => now()->subDays(10),
            'total' => 100000,
            'pending_invoice_amount' => 100000,
            'status' => \App\Billing\Enums\InvoiceStatus::Vencida->value,
        ]);
    }
    // ==================== El interruptor de la sede ====================

    /**
     * Apagada significa QUE NO SALE, no que salga por el del sistema.
     *
     * Es el interruptor general acotado a una sede: cortar el correo
     * de una sucursal concreta —una migracion de proveedor, un buzon
     * que rebota todo— sin dejar a las demas sin facturas.
     */
    public function test_una_sucursal_apagada_no_manda_nada(): void
    {
        $this->configurar($this->sanAndres, ['enabled' => false]);
        CorreoDelSistema::olvidarCache();

        $factura = $this->facturaDe($this->sanAndres);
        $cliente = $factura->contract->client;

        $cliente->notify(new InvoiceOverdue($factura));

        // NADA llego al transporte
        $this->assertCount(
            0,
            app('mailer')->getSymfonyTransport()->messages(),
            'la sucursal apagada envio igual',
        );

        // Y quedo anotado, con su sucursal y su motivo
        $log = \App\Models\MailLog::latest('id')->firstOrFail();

        $this->assertSame(\App\Models\MailLog::OMITIDO, $log->status);
        $this->assertSame($this->sanAndres->id, $log->branch_id);
        $this->assertStringContainsString('sucursal', $log->error);
    }

    public function test_apagar_una_sede_no_calla_a_las_demas(): void
    {
        $this->configurar($this->sanAndres, ['enabled' => false]);
        $this->configurar($this->yarumal);
        CorreoDelSistema::olvidarCache();

        $deYarumal = $this->facturaDe($this->yarumal);
        $deYarumal->contract->client->notify(new InvoiceOverdue($deYarumal));

        // Por SU mailer, no por el de siempre: cada sede tiene el suyo
        $this->assertCount(1, $this->loEnviadoPor($this->yarumal));
    }

    public function test_el_correo_de_una_sede_encendida_lleva_su_marca(): void
    {
        // La cabecera es lo que permite anotar de que sede fue cada
        // correo. Se quita antes de enviar: no pinta nada en la
        // bandeja del cliente.
        $this->configurar($this->sanAndres);
        CorreoDelSistema::olvidarCache();

        $factura = $this->facturaDe($this->sanAndres);
        $factura->contract->client->notify(new InvoiceOverdue($factura));

        $log = \App\Models\MailLog::latest('id')->firstOrFail();
        $this->assertSame($this->sanAndres->id, $log->branch_id);

        $enviado = $this->loEnviadoPor($this->sanAndres)->first();

        $this->assertNotNull($enviado, 'no salio por el mailer de la sucursal');
        $this->assertFalse(
            $enviado->getOriginalMessage()->getHeaders()->has(CorreoDeLaSucursal::CABECERA_SEDE),
            'la cabecera interna se filtro al cliente',
        );
    }

    /**
     * Lo que salio por el mailer de ESA sucursal.
     *
     * Cada sede tiene el suyo, asi que mirar el de siempre
     * (`app('mailer')`) no ve nada — que es justo lo que demuestra
     * que el reparto funciona.
     */
    private function loEnviadoPor(Branch $sucursal): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\Mail::mailer('sucursal_' . $sucursal->id)
            ->getSymfonyTransport()
            ->messages();
    }

    public function test_apagarla_queda_en_la_trazabilidad(): void
    {
        $this->configurar($this->sanAndres);

        $this->put(route('branches.mail.update', $this->sanAndres), [
            'host' => 'smtp.ejemplo.com',
        ])->assertRedirect();

        $registro = \DB::table('audits')->where('action', 'mail.branch_settings_updated')->latest('id')->first();

        $this->assertStringContainsString('APAGÓ', $registro->description);
    }

}
