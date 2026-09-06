<?php

namespace Tests\Feature\Billing;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo se comparte entre las sedes de una empresa.
 *
 * LA REGLA
 * --------
 * `branch_id = NULL` significa «de la EMPRESA»: disponible en todas sus
 * sucursales. Con un id, exclusivo de esa sede. Es la misma semántica
 * que ya tenía `numbering_ranges`.
 *
 * QUÉ SE DEFIENDE
 * ---------------
 * Tres cosas, y la tercera es la que cuesta dinero:
 *
 *   1. Que lo compartido se VEA desde todas las sedes de su empresa.
 *   2. Que NO se vea desde otra empresa. Compartir dentro de la empresa
 *      no puede abrir una puerta entre contribuyentes.
 *   3. Que consolidar duplicados **no altere ninguna factura ya
 *      emitida**. Sus renglones son copias congeladas; si el
 *      consolidador los tocara, cambiaría documentos que ya se
 *      presentaron a la DIAN.
 */
class SharedCatalogTest extends BillingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El alcance de empresa solo actúa con el contexto activo, y
        // quien lo activa en una petición es el middleware. Aquí se
        // establece a mano porque parte de lo que se comprueba ES ese
        // filtrado — sin contexto, las pruebas de aislamiento pasarían
        // sin demostrar nada.
        //
        // Además, `company_id` NO está en `$fillable`: quien la rellena
        // es el gancho de BelongsToCompany, desde la sucursal o, cuando
        // no hay (el catálogo de la empresa), desde este contexto.
        app(CurrentContext::class)->establecer(
            $this->branch->company_id,
            [$this->branch->id],
            $this->branch->id,
        );
    }

    // ==================== El alcance ====================

    public function test_un_plan_de_la_empresa_se_ve_desde_otra_sede(): void
    {
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $plan = Plan::create(['name' => 'Plan compartido', 'branch_id' => null]);

        $this->assertTrue(
            Plan::disponiblesEn([$otraSede->id])->get()->contains('id', $plan->id),
            'Un plan de la empresa no se vio desde otra de sus sedes.',
        );
    }

    public function test_un_plan_de_otra_sede_no_se_ve(): void
    {
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $plan = Plan::create(['name' => 'Plan solo de allá', 'branch_id' => $otraSede->id]);

        $this->assertFalse(
            Plan::disponiblesEn([$this->branch->id])->get()->contains('id', $plan->id),
        );
    }

    public function test_compartir_no_abre_una_puerta_entre_empresas(): void
    {
        // Lo más importante del alcance. `branch_id` nulo significa «de
        // MI empresa», no «de todos». Quien lo impide sigue siendo
        // BelongsToCompany; esto lo fija para que nadie lo quite
        // creyendo que `disponibles()` ya filtra.
        $otraEmpresa = Company::factory()->create();

        // La empresa se pone en el modelo, no en el array: `company_id`
        // no es fillable, y por asignación masiva se descarta en
        // silencio — el plan acabaría en MI empresa y la prueba pasaría
        // sin comprobar nada.
        $ajeno = new Plan(['name' => 'Plan de otra empresa']);
        $ajeno->company_id = $otraEmpresa->id;
        $ajeno->save();

        $this->assertFalse(
            Plan::disponiblesEn([$this->branch->id])->get()->contains('id', $ajeno->id),
            'Se vio el catálogo de OTRA empresa.',
        );
    }

    public function test_el_mismo_nombre_puede_existir_en_dos_empresas(): void
    {
        $otraEmpresa = Company::factory()->create();

        Plan::create(['name' => 'Plan 100 Megas', 'branch_id' => null]);

        $otro = new Plan(['name' => 'Plan 100 Megas']);
        $otro->company_id = $otraEmpresa->id;
        $otro->save();

        $this->assertSame(
            2,
            Plan::withoutGlobalScopes()->where('name', 'Plan 100 Megas')->count(),
        );
    }

    public function test_no_se_repite_el_nombre_dentro_del_mismo_ambito(): void
    {
        // Es lo que el índice sobre `branch_key` protege: con un UNIQUE
        // sobre `branch_id` a secas, MySQL admite varios nulos y dos
        // planes de empresa homónimos habrían pasado.
        Plan::create(['name' => 'Plan único', 'branch_id' => null]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Plan::create(['name' => 'Plan único', 'branch_id' => null]);
    }

    // ==================== Facturar con catálogo compartido ====================

    public function test_el_formulario_de_contrato_ofrece_los_planes_de_la_empresa(): void
    {
        // La pantalla es la mitad visible de la regla; la otra es la
        // validación, que se comprueba abajo. Las dos tienen que estar:
        // ofrecer un plan que la validación rechaza es peor que no
        // ofrecerlo.
        $contrato = $this->createBillableContract();

        $compartido = Plan::create(['name' => 'Plan de toda la empresa', 'branch_id' => null]);

        $this->get(route('contracts.create', $contrato->client_id))
            ->assertOk()
            ->assertSee('Plan de toda la empresa', escape: false);
    }

    public function test_la_validacion_acepta_un_plan_de_la_empresa_y_rechaza_el_de_otra_sede(): void
    {
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        $deLaEmpresa = Plan::create(['name' => 'Compartido', 'branch_id' => null]);
        $deOtraSede  = Plan::create(['name' => 'Solo de allá', 'branch_id' => $otraSede->id]);

        $this->assertTrue($deLaEmpresa->disponibleEn($this->branch->id));
        $this->assertFalse($deOtraSede->disponibleEn($this->branch->id));
        $this->assertTrue($deOtraSede->disponibleEn($otraSede->id));
    }

    public function test_se_factura_igual_con_un_plan_compartido(): void
    {
        $contrato = $this->createBillableContract();

        // El plan del contrato pasa a ser de la empresa: nada más
        // cambia, y la factura tiene que salir idéntica.
        Plan::withoutGlobalScopes()->whereKey($contrato->plan_id)->update(['branch_id' => null]);

        $this->post(route('contracts.invoice', $contrato))->assertRedirect();

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();

        $this->assertTrue($factura->invoice_items()->exists(), 'Salió sin renglones.');
        $this->assertGreaterThan(0, (float) $factura->total);
    }

    // ==================== El consolidador ====================

    public function test_fusiona_servicios_identicos_repetidos_por_sede(): void
    {
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        foreach ([$this->branch->id, $otraSede->id] as $sede) {
            Service::withoutGlobalScopes()->create([
                'name' => 'Internet 100 Mb',
                'base_price' => 80000,
                'tax_percentage' => 0,
                'branch_id' => $sede,
                'user_id' => $this->admin->id,
            ]);
        }

        $this->artisan('gestisp:catalogo-consolidar --aplicar')->assertSuccessful();

        $quedan = Service::withoutGlobalScopes()->where('name', 'Internet 100 Mb')->get();

        $this->assertCount(1, $quedan);
        $this->assertNull($quedan->first()->branch_id, 'No quedó como servicio de la empresa.');
    }

    public function test_no_fusiona_servicios_con_iva_distinto(): void
    {
        // El límite que justifica todo el diseño del comando. Fusionar
        // dos servicios con IVA distinto cambia lo que se le factura a
        // un cliente el mes siguiente, y en electrónica cambia el XML
        // que se le presenta a la DIAN.
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        foreach ([[$this->branch->id, 0], [$otraSede->id, 19]] as [$sede, $iva]) {
            Service::withoutGlobalScopes()->create([
                'name' => 'Television',
                'base_price' => 30000,
                'tax_percentage' => $iva,
                'branch_id' => $sede,
                'user_id' => $this->admin->id,
            ]);
        }

        $this->artisan('gestisp:catalogo-consolidar --aplicar')->assertSuccessful();

        $this->assertCount(
            2,
            Service::withoutGlobalScopes()->where('name', 'Television')->get(),
            'Fusionó dos servicios con IVA distinto.',
        );
    }

    public function test_sin_aplicar_no_escribe_nada(): void
    {
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);

        foreach ([$this->branch->id, $otraSede->id] as $sede) {
            Service::withoutGlobalScopes()->create([
                'name' => 'Servicio repetido',
                'base_price' => 1000,
                'tax_percentage' => 0,
                'branch_id' => $sede,
                'user_id' => $this->admin->id,
            ]);
        }

        $this->artisan('gestisp:catalogo-consolidar')->assertSuccessful();

        $this->assertCount(
            2,
            Service::withoutGlobalScopes()->where('name', 'Servicio repetido')->get(),
        );
    }

    public function test_consolidar_no_toca_ninguna_factura_emitida(): void
    {
        // LA PRUEBA QUE MÁS IMPORTA DE ESTE ARCHIVO.
        //
        // Los renglones de una factura emitida son copias congeladas:
        // llevan su propio precio, su código de producto y su
        // clasificación fiscal, y no apuntan al servicio. Si el
        // consolidador los tocara, cambiaría documentos que ya se
        // presentaron a la DIAN.
        $contrato = $this->createBillableContract();
        $this->post(route('contracts.invoice', $contrato));

        $factura = Invoice::where('contract_id', $contrato->id)->firstOrFail();
        $antes = $factura->invoice_items()->get()->map->only(['description', 'unit_price', 'total'])->toArray();

        // Se crea el duplicado que el consolidador va a fusionar.
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $original = Service::withoutGlobalScopes()->where('branch_id', $this->branch->id)->first();

        Service::withoutGlobalScopes()->create([
            'name' => $original->name,
            'base_price' => $original->base_price,
            'tax_percentage' => $original->tax_percentage,
            'branch_id' => $otraSede->id,
            'user_id' => $this->admin->id,
        ]);

        $this->artisan('gestisp:catalogo-consolidar --aplicar')->assertSuccessful();

        $despues = $factura->fresh()->invoice_items()->get()->map->only(['description', 'unit_price', 'total'])->toArray();

        $this->assertSame($antes, $despues, 'Consolidar el catálogo alteró una factura ya emitida.');
    }

    public function test_los_contratos_siguen_su_plan_al_fusionarlo(): void
    {
        // Un plan con contratos no se puede borrar —la clave foránea de
        // la parte 1 lo impide—, así que el consolidador tiene que
        // repuntar los contratos antes. Si no lo hiciera, fallaría.
        $otraSede = Branch::factory()->create(['company_id' => $this->branch->company_id]);
        $contrato = $this->createBillableContract();
        $planOriginal = Plan::withoutGlobalScopes()->findOrFail($contrato->plan_id);

        $gemelo = Plan::create(['name' => $planOriginal->name, 'branch_id' => $otraSede->id]);
        $gemelo->services()->attach($planOriginal->services->pluck('id'));

        $this->artisan('gestisp:catalogo-consolidar --aplicar')->assertSuccessful();

        $superviviente = Plan::withoutGlobalScopes()->where('name', $planOriginal->name)->get();

        $this->assertCount(1, $superviviente);
        $this->assertSame($superviviente->first()->id, $contrato->fresh()->plan_id);
        $this->assertNull($superviviente->first()->branch_id);
    }
}
