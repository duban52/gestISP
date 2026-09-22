<?php

namespace Tests\Feature\Contracts;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Plan;
use App\Models\User;
use App\Support\ColombiaLocations;
use App\Support\Direccion;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La dirección por partes.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * 1. Que sale siempre con la misma forma, la arme quien la arme.
 * 2. Que se puede volver a partir al editar, también las viejas
 *    escritas a mano: si no, editar un contrato obligaría a reescribirla.
 * 3. Que guardar un formulario sin tocar la dirección NO la borra, por
 *    rara que sea la que había.
 * 4. Que el servidor la arma y valida: no se fía del navegador.
 */
class DireccionTest extends TestCase
{
    use RefreshDatabase;

    // ==================== La forma ====================

    public function test_arma_la_direccion_con_la_forma_unica(): void
    {
        $this->assertSame('Calle 20 # 19-30, Apto 201, Ref: frente al parque', Direccion::componer([
            'tipo' => 'Calle', 'numero' => '20', 'placa' => '19', 'placa2' => '30',
            'complemento' => 'Apto 201', 'referencia' => 'frente al parque',
        ]));

        // Letras, bis y cuadrante, escritos de cualquier forma.
        $this->assertSame('Carrera 45A Bis Sur # 12B-34', Direccion::componer([
            'tipo' => 'Carrera', 'numero' => '45 a bis', 'cuadrante' => 'Sur',
            'placa' => '12b', 'placa2' => '34',
        ]));

        $this->assertSame('Vereda La Esperanza, Finca El Roble', Direccion::componer([
            'tipo' => 'Vereda', 'descripcion' => 'La Esperanza', 'complemento' => 'Finca El Roble',
        ]));
    }

    public function test_la_direccion_armada_se_vuelve_a_partir_igual(): void
    {
        foreach ([
            'Calle 20 # 19-30',
            'Calle 20 # 19-30, Apto 201, Torre 2, Ref: frente al parque',
            'Avenida Carrera 30 # 45A-12',
            'Carrera 45A Bis Sur # 12B-34, Ref: casa esquinera',
            'Vereda La Esperanza, Finca El Roble',
            'Kilómetro 5 vía Rionegro, Ref: después del peaje',
        ] as $direccion) {
            $partes = Direccion::partes($direccion);

            $this->assertNotNull($partes, "No se reconoció «{$direccion}».");
            $this->assertSame($direccion, Direccion::componer($partes));
        }

        $this->assertSame('Avenida Carrera', Direccion::partes('Avenida Carrera 30 # 45A-12')['tipo']);
    }

    public function test_reconoce_las_direcciones_viejas_escritas_a_mano(): void
    {
        $this->assertSame(
            ['Calle', '20', '19', '30', 'apto 201'],
            $this->resumen(Direccion::partes('CL 20 #19-30 apto 201')),
        );
        $this->assertSame(
            ['Carrera', '5', '10', '20', null],
            $this->resumen(Direccion::partes('Cra. 5 No. 10 - 20')),
        );
        $this->assertSame(
            ['Diagonal', '32B', '45', '12', 'casa 3'],
            $this->resumen(Direccion::partes('dg 32b n° 45-12, casa 3')),
        );

        // Lo que no tiene forma de nomenclatura no se inventa.
        $this->assertNull(Direccion::partes('Frente a la iglesia, casa azul'));
    }

    public function test_prepara_la_consulta_para_el_mapa(): void
    {
        $this->assertSame(
            'Calle 20 19-30, Rionegro, Antioquia, Colombia',
            Direccion::paraBuscar('Calle 20 # 19-30, Apto 201, Ref: frente al parque', 'Rionegro', 'Antioquia'),
        );

        // El «N/A» que dejaba la pantalla vieja no se le pregunta a nadie.
        $this->assertSame('Calle 20 19-30, Colombia', Direccion::paraBuscar('Calle 20 # 19-30', 'N/A', null));
    }

    public function test_reconoce_departamento_y_municipio_escritos_a_mano(): void
    {
        $this->assertSame(['Antioquia', 'Medellín'], ColombiaLocations::resolver('ANTIOQUIA', 'medellin'));
        // Lo que no está en el catálogo se conserva tal cual.
        $this->assertSame(['Antioquia', 'Vereda X'], ColombiaLocations::resolver('Antioquia', 'Vereda X'));
        $this->assertSame([null, null], ColombiaLocations::resolver('N/A', 'N/A'));
    }

    // ==================== El formulario ====================

    public function test_el_servidor_arma_la_direccion_con_las_partes(): void
    {
        $contrato = $this->contratoConSesion(['address' => 'CL 20 #19-30']);

        $this->put(route('contracts.update', $contrato), [
            '_direcciones' => ['address'],
            'address' => 'lo que diga el navegador no cuenta',
            'address_partes' => [
                'tipo' => 'Carrera', 'numero' => '45 a', 'cuadrante' => 'Sur',
                'placa' => '12', 'placa2' => '34', 'complemento' => 'Casa 5',
                'referencia' => 'frente a la tienda',
            ],
            'neighborhood' => 'Centro',
            'department' => 'Antioquia',
            'municipality' => 'Rionegro',
            'home_type' => 'Propia',
            'social_stratum' => 3,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            'Carrera 45A Sur # 12-34, Casa 5, Ref: frente a la tienda',
            $contrato->fresh()->address,
        );
    }

    public function test_sin_tocar_las_partes_se_conserva_la_direccion_que_habia(): void
    {
        // Una dirección que no se reconoce: guardar el modal para cambiar
        // el estrato no puede borrarla.
        $contrato = $this->contratoConSesion(['address' => 'Frente a la iglesia, casa azul']);

        $this->put(route('contracts.update', $contrato), [
            '_direcciones' => ['address'],
            'address' => 'Frente a la iglesia, casa azul',
            'address_partes' => ['tipo' => '', 'complemento' => ''],
            'neighborhood' => 'Centro',
            'home_type' => 'Propia',
            'social_stratum' => 3,
        ])->assertSessionHasNoErrors();

        $contrato->refresh();
        $this->assertSame('Frente a la iglesia, casa azul', $contrato->address);
        $this->assertSame(3, (int) $contrato->social_stratum);
    }

    public function test_una_nomenclatura_imposible_no_se_guarda(): void
    {
        $contrato = $this->contratoConSesion(['address' => 'Calle 20 # 19-30']);

        $this->put(route('contracts.update', $contrato), [
            '_direcciones' => ['address'],
            'address' => 'Calle 20 # 19-30',
            'address_partes' => ['tipo' => 'Calle', 'numero' => 'veinte', 'placa' => '19', 'placa2' => ''],
            'neighborhood' => 'Centro',
        ])->assertSessionHasErrors(['address_partes.numero', 'address_partes.placa2']);

        $this->assertSame('Calle 20 # 19-30', $contrato->fresh()->address);
    }

    public function test_el_modal_de_residencia_trae_los_campos_nuevos(): void
    {
        $contrato = $this->contratoConSesion([
            'address' => 'CL 20 #19-30',
            'department' => 'ANTIOQUIA',
            'municipality' => 'Medellin',
        ]);

        $this->get(route('contracts.show', $contrato))
            ->assertOk()
            ->assertSee('name="address_partes[tipo]"', false)
            // La vieja se interpretó y viene partida.
            ->assertSee('value="Calle" selected', false)
            // Departamento y municipio, elegidos aunque estaban escritos a mano.
            ->assertSee('value="Antioquia" selected', false)
            ->assertSee('value="Medellín" selected', false);
    }

    // ==================== Apoyo ====================

    /** @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?string} */
    private function resumen(?array $partes): array
    {
        return [$partes['tipo'], $partes['numero'], $partes['placa'], $partes['placa2'], $partes['complemento']];
    }

    private function contratoConSesion(array $extra): Contract
    {
        $this->seed(RoleSeeder::class);

        $branch = Branch::factory()->create();
        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create();
        $admin->assignRole($rol);
        $admin->branches()->attach($branch->id, ['role_id' => $rol->id]);

        $this->actingAs($admin)->withSession([
            'branch_id' => (string) $branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $datos = ['branch_id' => $branch->id, 'user_id' => $admin->id];

        return Contract::factory()->create(array_merge($datos, [
            'client_id' => Client::factory()->create($datos)->id,
            'plan_id' => Plan::factory()->create($datos)->id,
            'status' => 'Activo',
        ], $extra));
    }
}
