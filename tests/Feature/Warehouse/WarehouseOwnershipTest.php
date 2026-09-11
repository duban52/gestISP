<?php

namespace Tests\Feature\Warehouse;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * De quién es un almacén, y quién lo creó.
 *
 * EL DEFECTO
 * ----------
 * `warehouses.user_id` hacía de las dos cosas. El formulario lo llamaba
 * «vincular usuario» —el dueño— pero `store()` lo rellenaba con
 * `Auth::id()` si se dejaba vacío, y el listado lo mostraba como «Creado
 * por». Consecuencias:
 *
 * · Un almacén creado por la oficina PARA un técnico quedaba a nombre de
 *   la oficina si se olvidaban de elegirlo.
 * · El almacén principal quedaba a nombre de quien lo creó, y de ahí
 *   salía el material de las órdenes que cerraba esa persona.
 * · No había forma de dejar un almacén SIN dueño, que es exactamente lo
 *   que es una bodega, el de cabecera o uno general.
 * · Y el editar existía en el backend pero no estaba enlazado: reasignar
 *   un almacén exigía tocar la base de datos.
 */
class WarehouseOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sucursal;
    private User $oficina;
    private User $tecnico;
    private Role $rol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->sucursal = Branch::factory()->create(['name' => 'Gómez Plata']);
        $this->rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->oficina = $this->usuario('Oficina');
        $this->tecnico = $this->usuario('Tecnico');

        $this->actingAs($this->oficina)->withSession([
            'branch_id' => (string) $this->sucursal->id,
            'current_role_id' => (string) $this->rol->id,
        ]);
    }

    private function usuario(string $nombre): User
    {
        $usuario = User::factory()->create(['name' => $nombre]);
        $usuario->assignRole($this->rol);
        $usuario->branches()->attach($this->sucursal->id, ['role_id' => $this->rol->id]);

        return $usuario;
    }

    public function test_lo_crea_uno_y_pertenece_a_otro(): void
    {
        // Es el caso normal: la oficina da de alta el almacén de un
        // técnico. Antes quedaba a nombre de la oficina si se olvidaban
        // de elegir, y de ahí descontaban sus órdenes.
        $this->post(route('warehouses.store'), [
            'description' => 'Furgoneta del técnico',
            'user_id' => $this->tecnico->id,
        ])->assertRedirect();

        $almacen = Warehouse::where('description', 'Furgoneta del técnico')->firstOrFail();

        $this->assertSame($this->tecnico->id, $almacen->user_id, 'El dueño debe ser el técnico.');
        $this->assertSame($this->oficina->id, $almacen->created_by, 'El creador debe ser la oficina.');
    }

    public function test_sin_dueno_es_un_almacen_general(): void
    {
        // Una bodega, el de cabecera. Antes esto quedaba a nombre de
        // quien lo creaba, y no había manera de dejarlo sin dueño.
        $this->post(route('warehouses.store'), [
            'description' => 'Bodega de cabecera',
        ])->assertRedirect();

        $almacen = Warehouse::where('description', 'Bodega de cabecera')->firstOrFail();

        $this->assertNull($almacen->user_id);
        $this->assertTrue($almacen->esGeneral());
        $this->assertSame('General', $almacen->duenoVisible());
        $this->assertSame($this->oficina->id, $almacen->created_by);
    }

    public function test_se_puede_cambiar_el_dueno(): void
    {
        $almacen = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->oficina->id,
            'created_by' => $this->oficina->id,
            'description' => 'Almacén mal asignado',
        ]);

        $this->put(route('warehouses.update', $almacen), [
            'description' => 'Furgoneta del técnico',
            'user_id' => $this->tecnico->id,
        ])->assertRedirect();

        $almacen->refresh();

        $this->assertSame('Furgoneta del técnico', $almacen->description);
        $this->assertSame($this->tecnico->id, $almacen->user_id);
        // El creador NO cambia: es un hecho del pasado.
        $this->assertSame($this->oficina->id, $almacen->created_by);
    }

    public function test_dejarlo_en_blanco_le_quita_el_dueno(): void
    {
        // Antes se conservaba el dueño que hubiera, así que un almacén
        // asignado por error no se podía devolver a «general» sin ir a
        // la base de datos.
        $almacen = Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->tecnico->id,
            'created_by' => $this->oficina->id,
            'description' => 'Bodega',
        ]);

        $this->put(route('warehouses.update', $almacen), [
            'description' => 'Bodega',
            'user_id' => '',
        ])->assertRedirect();

        $this->assertNull($almacen->fresh()->user_id);
    }

    public function test_el_listado_distingue_dueno_de_creador(): void
    {
        Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => $this->tecnico->id,
            'created_by' => $this->oficina->id,
            'description' => 'Furgoneta',
        ]);

        Warehouse::create([
            'branch_id' => $this->sucursal->id,
            'user_id' => null,
            'created_by' => $this->oficina->id,
            'description' => 'Bodega',
        ]);

        $respuesta = $this->get(route('warehouses.index'))->assertOk();

        $respuesta->assertSee('Pertenece a', false);
        $respuesta->assertSee('Creado por', false);
        $respuesta->assertSee('General', false);
        // Y el enlace de edición, que existía en el backend pero no
        // estaba enlazado desde ninguna parte.
        $respuesta->assertSee('Editar', false);
    }

    public function test_no_se_edita_un_almacen_de_otra_sucursal(): void
    {
        // La ruta acepta cualquier id: sin el corte bastaba con cambiar
        // el número en la URL para reasignarle el dueño al almacén de
        // otra sede.
        $otraSucursal = Branch::factory()->create([
            'name' => 'Yarumal',
            'company_id' => $this->sucursal->company_id,
        ]);

        $ajeno = Warehouse::create([
            'branch_id' => $otraSucursal->id,
            'user_id' => null,
            'created_by' => $this->oficina->id,
            'description' => 'Bodega ajena',
        ]);

        $this->get(route('warehouses.edit', $ajeno))->assertStatus(403);

        $this->put(route('warehouses.update', $ajeno), [
            'description' => 'Secuestrada',
            'user_id' => $this->oficina->id,
        ])->assertStatus(403);

        $this->assertSame('Bodega ajena', $ajeno->fresh()->description);
    }
}
