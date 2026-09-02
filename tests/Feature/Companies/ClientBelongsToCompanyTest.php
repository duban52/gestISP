<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El cliente pertenece a la EMPRESA, no a la sucursal.
 *
 * LA REGLA
 * --------
 * Una persona puede tener servicio en varias sedes de la misma
 * empresa: es UN cliente con varios contratos. En otra empresa esa
 * misma persona es OTRO cliente, porque son contribuyentes distintos y
 * sus datos están aislados.
 *
 *     Empresa A ── cliente Juan ── contrato en Norte
 *                              └── contrato en Sur
 *     Empresa B ── cliente Juan  (ficha aparte, aislada)
 *
 * POR QUÉ SE CAMBIÓ
 * -----------------
 * Con el cliente atado a una sucursal, una persona con servicio en dos
 * sedes había que crearla dos veces: dos fichas, dos historiales, y
 * ante la DIAN un solo adquiriente. En los datos reales que revisamos,
 * 215 personas tenían contratos en más de una sede.
 *
 * Y en panel consolidado directamente no se podía crear un cliente: no
 * hay una sucursal activa de la que sacar branch_id, así que el alta
 * fallaba con «Indique la sucursal en la que se registra».
 */
class ClientBelongsToCompanyTest extends TestCase
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

    /** Entra en una empresa; sin sucursal si se pide consolidado. */
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

        // withSession() solo siembra la sesion: el middleware que
        // enciende el contexto solo corre en una peticion HTTP. Sin
        // esto, una comprobacion hecha DESPUES de cambiar de empresa
        // seguiria mirando con el contexto de la anterior, que es
        // justo lo que se quiere comprobar aqui.
        app(CurrentContext::class)->establecer(
            $empresa->id,
            $ids,
            $consolidado ? null : $sucursales[0]->id,
        );

        return $usuario;
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'type_document' => 'Cédula de ciudadanía',
            'identity_number' => '1042772330',
            'name' => 'Juan',
            'last_name' => 'Pérez',
            'type_client' => 'Residencial',
            'number_phone' => '3001234567',
            'email' => 'juan@ejemplo.com',
        ], $extra);
    }

    // ==================== El caso que fallaba ====================

    public function test_en_panel_consolidado_se_puede_crear_un_cliente(): void
    {
        // Antes daba «Indique la sucursal en la que se registra»: el
        // alta exigía branch_id y en consolidado no hay una activa.
        $empresa = Company::factory()->consolidada()->create();
        $sedes = [
            Branch::factory()->create(['company_id' => $empresa->id]),
            Branch::factory()->create(['company_id' => $empresa->id]),
        ];

        $this->entrarEn($empresa, $sedes, consolidado: true);

        $this->post(route('clients.store'), $this->datos())->assertRedirect();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->assertSame($empresa->id, (int) $cliente->company_id);
        // Sin sucursal de origen: no había una activa, y no pasa nada.
        $this->assertNull($cliente->branch_id);
    }

    public function test_en_modo_independiente_se_guarda_la_sucursal_de_origen(): void
    {
        // No acota quién lo ve —eso lo hace la empresa—, pero sirve
        // para saber de dónde salió.
        $empresa = Company::factory()->create();
        $sede = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sede]);

        $this->post(route('clients.store'), $this->datos())->assertRedirect();

        $cliente = Client::where('identity_number', '1042772330')->firstOrFail();

        $this->assertSame($sede->id, $cliente->branch_id);
        $this->assertSame($empresa->id, (int) $cliente->company_id);
    }

    // ==================== Unicidad ====================

    public function test_el_mismo_documento_no_se_repite_dentro_de_una_empresa(): void
    {
        // Sería la misma persona dos veces: dos fichas, dos
        // historiales, y ante la DIAN un solo adquiriente.
        $empresa = Company::factory()->create();
        $sede = Branch::factory()->create(['company_id' => $empresa->id]);

        $this->entrarEn($empresa, [$sede]);

        $this->post(route('clients.store'), $this->datos())->assertRedirect();

        $respuesta = $this->post(route('clients.store'), $this->datos());

        $respuesta->assertSessionHasErrors('identity_number');
        $this->assertSame(1, Client::withoutGlobalScope('empresa')
            ->where('identity_number', '1042772330')->count());
    }

    public function test_el_mismo_documento_si_se_puede_crear_en_otra_empresa(): void
    {
        // Es lo que pidió el usuario: la misma persona en la empresa A
        // y en la B son dos clientes distintos y aislados.
        $primera = Company::factory()->create();
        $segunda = Company::factory()->create();

        $this->entrarEn($primera, [Branch::factory()->create(['company_id' => $primera->id])]);
        $this->post(route('clients.store'), $this->datos())->assertRedirect();

        $this->entrarEn($segunda, [Branch::factory()->create(['company_id' => $segunda->id])]);
        $this->post(route('clients.store'), $this->datos())->assertSessionHasNoErrors();

        $this->assertSame(2, Client::withoutGlobalScope('empresa')
            ->where('identity_number', '1042772330')->count());
    }

    // ==================== Aislamiento ====================

    public function test_no_se_ve_el_cliente_de_otra_empresa(): void
    {
        $primera = Company::factory()->create();
        $segunda = Company::factory()->create();

        $this->entrarEn($primera, [Branch::factory()->create(['company_id' => $primera->id])]);
        $this->post(route('clients.store'), $this->datos(['name' => 'DeLaPrimera']));

        $this->entrarEn($segunda, [Branch::factory()->create(['company_id' => $segunda->id])]);

        $this->assertSame(0, Client::count());
        $this->assertNull(Client::where('name', 'DeLaPrimera')->first());
    }

    public function test_el_cliente_se_ve_desde_cualquier_sucursal_de_su_empresa(): void
    {
        // Es el punto de todo el cambio: una persona dada de alta en
        // Norte tiene que encontrarse desde Sur, porque el cliente es
        // de la empresa y puede contratar en las dos.
        $empresa = Company::factory()->create();
        $norte = Branch::factory()->create(['company_id' => $empresa->id]);
        $sur = Branch::factory()->create(['company_id' => $empresa->id]);

        $usuario = $this->entrarEn($empresa, [$norte, $sur]);

        $this->post(route('clients.store'), $this->datos(['name' => 'DadoDeAltaEnNorte']));

        // Se cambia a la otra sede
        $this->actingAs($usuario)->withSession([
            'company_id' => $empresa->id,
            'branch_id' => (string) $sur->id,
            'branch_ids' => [$sur->id],
            'current_role_id' => (string) $this->rol->id,
        ]);

        $this->assertNotNull(Client::where('name', 'DadoDeAltaEnNorte')->first());
    }
}
