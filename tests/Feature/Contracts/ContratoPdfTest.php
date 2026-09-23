<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El contrato de servicios que firma el cliente.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que lleva los datos de ESTE contrato y de ESTE cliente: un
 *    documento que se firma no puede salir con el nombre de otro.
 * 2. Que lleva los datos de la empresa que presta el servicio, que es
 *    quien se obliga en él.
 * 3. Que no se imprime el contrato de otra sucursal por cambiar el
 *    número de la URL.
 */
class ContratoPdfTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create(['reconnection_price' => 15000]);
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create();
        $this->admin->assignRole($rol);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        Company::whereKey($this->branch->company_id)->update([
            'legal_name' => 'Redes del Norte S.A.S.',
            'document_number' => '901299882',
            'verification_digit' => '1',
            'tic_registry' => '96004914',
            'website' => 'https://redesdelnorte.com',
        ]);

        $servicio = Service::factory()->create([
            'name' => 'Internet 100 Megas',
            'base_price' => 80000,
            'tax_percentage' => 0,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        $this->plan = Plan::factory()->create([
            'name' => 'Hogar 100',
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
        ]);
        $this->plan->services()->attach($servicio->id);
    }

    public function test_el_documento_trae_los_datos_del_cliente_del_plan_y_de_la_empresa(): void
    {
        $contrato = $this->contrato();

        // Se renderiza la plantilla —lo que se convierte en PDF— para
        // poder mirar lo que dice: el PDF ya es binario.
        $html = view('gestisp.contracts.pdf', [
            'contract' => $contrato->load('client', 'plan.services', 'branch'),
            'branch' => $this->branch,
            'company' => $this->branch->company,
            'logoPath' => null,
        ])->render();

        // El cliente
        $this->assertStringContainsString('Rebeca Arango', $html);
        $this->assertStringContainsString('1037045539', $html);
        $this->assertStringContainsString('rebeca@example.com', $html);
        $this->assertStringContainsString('Calle 20 # 19-30', $html);

        // El contrato y su plan
        $this->assertStringContainsString($contrato->numero_visible, $html);
        $this->assertStringContainsString('Internet 100 Megas', $html);
        $this->assertStringContainsString('$80.000', $html);
        $this->assertStringContainsString('12', $html);          // meses de permanencia

        // La empresa que se obliga
        $this->assertStringContainsString('Redes del Norte S.A.S.', $html);
        $this->assertStringContainsString('901299882-1', $html);
        $this->assertStringContainsString('96004914', $html);
        $this->assertStringContainsString('redesdelnorte.com', $html);

        // Y el costo de reconexión de la sucursal, que el formato exige
        $this->assertStringContainsString('$15.000', $html);

        // El articulado de la CRC
        $this->assertStringContainsString('CONTRATO ÚNICO DE PRESTACIÓN DE SERVICIOS FIJOS', $html);
        $this->assertStringContainsString('ANEXO 1', mb_strtoupper($html));
        $this->assertStringContainsString('SARLAFT', mb_strtoupper($html));
    }

    public function test_sin_permanencia_lo_dice_en_vez_de_dejarlo_en_blanco(): void
    {
        $contrato = $this->contrato(['permanence_clause' => 0]);

        $html = view('gestisp.contracts.pdf', [
            'contract' => $contrato->load('client', 'plan.services', 'branch'),
            'branch' => $this->branch,
            'company' => $this->branch->company,
            'logoPath' => null,
        ])->render();

        $this->assertStringContainsString('sin cláusula de permanencia mínima', $html);
    }

    public function test_se_descarga_el_pdf(): void
    {
        $contrato = $this->contrato();

        $ver = $this->get(route('contracts.pdf', $contrato));
        $ver->assertOk();
        $this->assertSame('application/pdf', $ver->headers->get('Content-Type'));

        $guardar = $this->get(route('contracts.pdf', [$contrato, 'descargar' => 1]));
        $guardar->assertOk();
        $this->assertStringContainsString('attachment', (string) $guardar->headers->get('Content-Disposition'));
    }

    public function test_no_se_imprime_el_contrato_de_otra_sucursal(): void
    {
        $otra = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $datos = ['branch_id' => $otra->id, 'user_id' => $this->admin->id];

        $ajeno = Contract::factory()->create($datos + [
            'client_id' => Client::factory()->create($datos)->id,
            'plan_id' => Plan::factory()->create($datos)->id,
        ]);

        $this->get(route('contracts.pdf', $ajeno))->assertForbidden();
    }

    public function test_la_ficha_ofrece_el_documento(): void
    {
        $contrato = $this->contrato();

        $this->get(route('contracts.show', $contrato))
            ->assertOk()
            ->assertSee('Mostrar PDF del contrato')
            ->assertSee(route('contracts.pdf', $contrato), false);
    }

    // ==================== Apoyo ====================

    private function contrato(array $extra = []): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'name' => 'Rebeca',
            'last_name' => 'Arango',
            'identity_number' => '1037045539',
            'type_document' => 'CC',
            'email' => 'rebeca@example.com',
        ]);

        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'user_id' => $this->admin->id,
            'status' => 'Activo',
            'address' => 'Calle 20 # 19-30',
            'neighborhood' => 'Centro',
            'municipality' => 'Yarumal',
            'department' => 'Antioquia',
            'social_stratum' => 2,
            'permanence_clause' => 12,
            'activation_date' => '2026-09-23',
        ], $extra));
    }
}
