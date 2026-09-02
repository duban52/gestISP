<?php

namespace Tests\Feature\Companies;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\CurrentContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los tres caminos que no van por un formulario normal.
 *
 * POR QUÉ ESTÁN JUNTOS
 * --------------------
 * El resto de las pantallas de alta piden la sucursal con el mismo
 * componente y en un `<form>` corriente, así que basta con mirar que
 * el campo esté ahí (BranchSelectorTest). Estas tres no:
 *
 *   · **Cortes masivos** manda la lista por AJAX en dos llamadas
 *     —revisar y ejecutar—, y la sucursal tiene que viajar en las dos.
 *   · **La barra de facturas** tiene el selector FUERA del formulario,
 *     asociado con el atributo `form`, porque el mismo valor lo usan
 *     dos acciones: el POST de la corrida y el GET del PDF masivo.
 *   · **La importación de clientes** son dos pantallas: lo que se
 *     elige en la primera tiene que llegar a la segunda, o se
 *     revisaría una sucursal y se importaría en otra.
 *
 * En los tres, un fallo se ve solo al usarlos. De ahí estas pruebas.
 */
class ConsolidatedFlowsTest extends TestCase
{
    use RefreshDatabase;

    private Role $rol;
    private Company $empresa;
    private Branch $norte;
    private Branch $sur;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        Permission::firstOrCreate(
            ['name' => 'clients.import', 'guard_name' => 'web'],
            ['description' => 'Importar clientes y contratos'],
        );
        $this->rol->givePermissionTo('clients.import');

        $this->empresa = Company::factory()->consolidada()->create();
        $this->norte = Branch::factory()->create(['company_id' => $this->empresa->id, 'name' => 'Sede Norte']);
        $this->sur = Branch::factory()->create(['company_id' => $this->empresa->id, 'name' => 'Sede Sur']);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($this->rol);
        $this->usuario->branches()->attach($this->norte->id, ['role_id' => $this->rol->id]);
        $this->usuario->branches()->attach($this->sur->id, ['role_id' => $this->rol->id]);

        $this->enConsolidado();
    }

    private function enConsolidado(): void
    {
        $ids = [$this->norte->id, $this->sur->id];

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => null,
            'branch_ids' => $ids,
            'current_role_id' => (string) $this->rol->id,
        ]);

        app(CurrentContext::class)->establecer($this->empresa->id, $ids, null);
    }

    // ==================== Cortes masivos ====================

    public function test_los_cortes_piden_la_sucursal_en_la_pantalla(): void
    {
        $this->get(route('pppoe.cutoff'))
            ->assertOk()
            ->assertSee('name="branch_id"', escape: false)
            ->assertSee('Sede Norte', escape: false)
            ->assertSee('Sede Sur', escape: false);
    }

    public function test_revisar_sin_sucursal_responde_422_y_no_500(): void
    {
        // Va por AJAX: tiene que devolver un error entendible en JSON,
        // no una página de error 500 que el JS no sabe leer.
        $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => 'ENG000001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_revisar_con_sucursal_funciona(): void
    {
        $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => 'ENG000001',
            'branch_id' => $this->norte->id,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_no_se_puede_cortar_en_una_sucursal_ajena(): void
    {
        $ajena = Branch::factory()->create();

        $this->postJson(route('pppoe.cutoff.preview'), [
            'lista' => 'ENG000001',
            'branch_id' => $ajena->id,
        ])->assertStatus(422);
    }

    // ==================== La barra de facturas ====================

    public function test_la_barra_de_facturas_asocia_el_selector_al_formulario(): void
    {
        // El selector vive FUERA del <form> y se le engancha con el
        // atributo `form`. Sin esa asociación el valor no se envía y la
        // corrida falla sin que se vea por qué.
        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('form="formGenerarFacturas"', escape: false)
            ->assertSee('id="formGenerarFacturas"', escape: false);
    }

    public function test_la_corrida_sin_sucursal_avisa_en_vez_de_reventar(): void
    {
        $this->post(route('invoices.generate'))
            ->assertRedirect()
            ->assertSessionHasErrors('branch_id');
    }

    public function test_la_corrida_con_sucursal_se_ejecuta(): void
    {
        // Sin contratos no genera nada, pero llega hasta el final: lo
        // que se comprueba es que la sucursal se acepta y no se corta
        // antes por falta de dato.
        $this->post(route('invoices.generate'), ['branch_id' => $this->norte->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_el_pdf_masivo_recibe_la_sucursal_por_la_url(): void
    {
        // El botón va por AJAX GET y el JS le cuelga branch_id a la URL.
        $this->get(route('invoices.generate_max_pdf', ['branch_id' => $this->norte->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_el_pdf_masivo_sin_sucursal_avisa(): void
    {
        $this->get(route('invoices.generate_max_pdf'))
            ->assertRedirect()
            ->assertSessionHasErrors('branch_id');
    }

    // ==================== Importación en dos pantallas ====================

    /** Un CSV mínimo con los encabezados que espera el importador. */
    private function archivo(): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        $f = fopen($ruta, 'w');

        fputcsv($f, ['Numero de contrato', 'Documento', 'Nombre', 'Apellido',
            'Telefono', 'Correo', 'Plan', 'Direccion', 'Saldo pendiente']);
        fputcsv($f, ['', '1111111', 'Ana', 'Restrepo', '3155554433',
            'ana@ejemplo.com', 'Internet 100 Megas', 'Calle 10', '0']);

        fclose($f);

        return new UploadedFile($ruta, 'clientes.csv', 'text/csv', null, true);
    }

    public function test_la_importacion_pide_la_sucursal(): void
    {
        $this->get(route('clients.import.index'))
            ->assertOk()
            ->assertSee('name="branch_id"', escape: false);
    }

    public function test_sin_sucursal_no_se_llega_a_la_revision(): void
    {
        $this->post(route('clients.import.preview'), ['archivo' => $this->archivo()])
            ->assertRedirect()
            ->assertSessionHasErrors('branch_id');
    }

    public function test_la_sucursal_elegida_llega_a_la_segunda_pantalla(): void
    {
        // Este es el fallo que la prueba busca: revisar en una sucursal
        // e importar en otra. La elegida en el paso 1 viaja en un campo
        // oculto para que las dos pantallas hablen de lo mismo.
        Plan::create([
            'name' => 'Internet 100 Megas',
            'branch_id' => $this->sur->id,
            'user_id' => $this->usuario->id,
        ]);

        $this->post(route('clients.import.preview'), [
            'archivo' => $this->archivo(),
            'branch_id' => $this->sur->id,
        ])
            ->assertOk()
            ->assertSee('name="branch_id" value="' . $this->sur->id . '"', escape: false);
    }

    public function test_no_se_puede_importar_a_una_sucursal_ajena(): void
    {
        $ajena = Branch::factory()->create();

        $this->post(route('clients.import.preview'), [
            'archivo' => $this->archivo(),
            'branch_id' => $ajena->id,
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('branch_id');
    }
}
