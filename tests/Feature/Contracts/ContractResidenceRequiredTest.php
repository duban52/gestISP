<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los datos de la residencia se piden, no se revientan.
 *
 * LO QUE PASABA
 * -------------
 * El alta de contratos no validaba nada de la residencia. Dejar el
 * barrio en blanco y pulsar «Guardar contrato» daba un error 500:
 *
 *     Column 'neighborhood' cannot be null
 *
 * Quien lo veía no tenía forma de saber que le faltaba el barrio: la
 * pantalla era la de error del sistema. `neighborhood`, `address` y
 * `home_type` son NOT NULL en `contracts`.
 *
 * LO QUE SE DEFIENDE AQUÍ
 * -----------------------
 *  1. Que falte un dato obligatorio se diga con un mensaje, no con un
 *     500 — y por los DOS caminos que guardan estos campos: el alta y
 *     el modal de residencia de la ficha.
 *  2. Que el mensaje diga qué falta. «El campo neighborhood es
 *     obligatorio» no le sirve a nadie.
 *  3. Que no se exija MÁS de lo que la tabla pide. Departamento,
 *     municipio y estrato admiten nulo y hay contratos importados sin
 *     ellos: volverlos obligatorios habría impedido guardar cualquier
 *     cambio en esos contratos.
 *  4. Que el error se VEA al volver a la ficha. Un rechazo silencioso
 *     parece un cambio ignorado, y se vuelve a intentar.
 */
class ContractResidenceRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;
    private Company $empresa;
    private Branch $sucursal;
    private Client $cliente;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();
        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $usuario = User::factory()->create();
        $usuario->assignRole($this->rol);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        $this->actingAs($usuario)->withSession([
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

        $this->cliente = Client::factory()->create([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
            'user_id' => $usuario->id,
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'branch_id' => $this->sucursal->id,
            'user_id' => $usuario->id,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'client_id' => $this->cliente->id,
            'plan_id' => $this->plan->id,
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
            'neighborhood' => 'Centro',
            'address' => 'Calle 30 # 23-23',
            'home_type' => 'Propia',
            'social_stratum' => '2',
        ], $extra);
    }

    private function contratoExistente(): Contract
    {
        $this->post(route('contracts.store'), $this->datos())->assertSessionHasNoErrors();

        return Contract::where('client_id', $this->cliente->id)->firstOrFail();
    }

    // ==================== El alta ====================

    /**
     * El caso exacto del error: barrio en blanco.
     *
     * @dataProvider camposObligatorios
     */
    public function test_sin_un_dato_obligatorio_no_se_crea_el_contrato(string $campo): void
    {
        $this->post(route('contracts.store'), $this->datos([$campo => '']))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, Contract::where('client_id', $this->cliente->id)->count());
    }

    /** @dataProvider camposObligatorios */
    public function test_el_dato_que_no_llega_tampoco_vale(string $campo): void
    {
        // El modal de residencia no manda el campo cuando el navegador
        // lo quita; el formulario de alta sí lo manda vacío. Los dos
        // acababan en el mismo NULL.
        $datos = $this->datos();
        unset($datos[$campo]);

        $this->post(route('contracts.store'), $datos)->assertSessionHasErrors($campo);

        $this->assertSame(0, Contract::where('client_id', $this->cliente->id)->count());
    }

    public static function camposObligatorios(): array
    {
        return [
            'barrio' => ['neighborhood'],
            'direccion' => ['address'],
            'tipo de vivienda' => ['home_type'],
        ];
    }

    public function test_el_mensaje_dice_que_falta(): void
    {
        $this->post(route('contracts.store'), $this->datos([
            'neighborhood' => '',
            'address' => '',
            'home_type' => '',
        ]));

        $errores = session('errors');

        $this->assertStringContainsString('barrio', $errores->first('neighborhood'));
        $this->assertStringContainsString('dirección', $errores->first('address'));
        $this->assertStringContainsString('tipo de vivienda', $errores->first('home_type'));
    }

    public function test_sin_cliente_tampoco_revienta(): void
    {
        // Va en un campo oculto, pero un oculto se edita igual que
        // cualquier otro campo y `client_id` también es NOT NULL.
        $this->post(route('contracts.store'), $this->datos(['client_id' => '']))
            ->assertSessionHasErrors('client_id');

        $this->assertSame(0, Contract::count());
    }

    public function test_con_los_datos_completos_el_contrato_se_crea(): void
    {
        $this->post(route('contracts.store'), $this->datos())->assertSessionHasNoErrors();

        $contrato = Contract::where('client_id', $this->cliente->id)->firstOrFail();

        $this->assertSame('Centro', $contrato->neighborhood);
        $this->assertSame('Calle 30 # 23-23', $contrato->address);
    }

    /**
     * No se exige más de lo que la tabla pide.
     *
     * Hay contratos importados sin departamento, municipio ni
     * estrato. Si estos fueran obligatorios, abrir el modal de
     * residencia de uno de ellos y guardar cualquier corrección
     * fallaría por datos que nunca se tuvieron.
     */
    public function test_el_departamento_el_municipio_y_el_estrato_siguen_siendo_opcionales(): void
    {
        $this->post(route('contracts.store'), $this->datos([
            'department' => '',
            'municipality' => '',
            'social_stratum' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Contract::where('client_id', $this->cliente->id)->count());
    }

    /**
     * El estrato llega como número y como texto.
     *
     * El desplegable manda "3"; otras llamadas mandan el 3 entero. Una
     * regla `string` aquí rechazaba el entero, y el alta fallaba con
     * «social stratum debe ser texto» en un campo que estaba bien
     * relleno.
     */
    public function test_el_estrato_vale_como_numero_y_como_texto(): void
    {
        $this->post(route('contracts.store'), $this->datos(['social_stratum' => 3]))
            ->assertSessionHasNoErrors();

        $this->assertSame('3', (string) Contract::where('client_id', $this->cliente->id)
            ->firstOrFail()->social_stratum);
    }

    // ==================== El modal de la ficha ====================

    public function test_borrar_el_barrio_al_editar_no_revienta(): void
    {
        $contrato = $this->contratoExistente();

        $this->put(route('contracts.update', $contrato), [
            'neighborhood' => '',
            'address' => 'Calle 30 # 23-23',
            'home_type' => 'Propia',
            'social_stratum' => '2',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
        ])->assertSessionHasErrors('neighborhood');

        // Y lo que había sigue ahí: un rechazo no borra el dato bueno.
        $this->assertSame('Centro', $contrato->fresh()->neighborhood);
    }

    public function test_la_ficha_ensena_lo_que_falto(): void
    {
        // Sin este aviso el modal se cerraba sin guardar y sin decir
        // nada: parecía que el sistema había ignorado el cambio.
        $contrato = $this->contratoExistente();

        $this->put(route('contracts.update', $contrato), [
            'neighborhood' => '',
            'address' => 'Calle 30 # 23-23',
            'home_type' => 'Propia',
        ]);

        $this->get(route('contracts.show', $contrato))
            ->assertOk()
            ->assertSee('No se guardó', escape: false)
            ->assertSee('barrio', escape: false);
    }

    public function test_la_edicion_valida_sigue_guardando(): void
    {
        $contrato = $this->contratoExistente();

        $this->put(route('contracts.update', $contrato), [
            'neighborhood' => 'La Esperanza',
            'address' => 'Carrera 10 # 5-15',
            'home_type' => 'En Arriendo',
            'social_stratum' => '3',
            'department' => 'Antioquia',
            'municipality' => 'Yarumal',
        ])->assertSessionHasNoErrors();

        $this->assertSame('La Esperanza', $contrato->fresh()->neighborhood);
    }
}
