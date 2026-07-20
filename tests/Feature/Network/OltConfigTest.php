<?php

namespace Tests\Feature\Network;

use App\Models\Branch;
use App\Models\LineProfile;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\SrvProfile;
use App\Models\User;
use App\Models\VlanOlt;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Configuración de la OLT: VLANs y perfiles.
 *
 * Son los valores que ya existen en el equipo y que aquí se
 * registran para poder ofrecerlos al autorizar una ONT.
 */
class OltConfigTest extends TestCase
{
    use RefreshDatabase;

    private Olt $olt;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $admin = User::factory()->create(['number_phone' => '3000000000']);
        $admin->assignRole($role);
        $admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        // La sucursal queda como TEXTO en sesión, igual que al
        // iniciar sesión de verdad
        $this->actingAs($admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        $this->olt = $this->crearOlt($this->branch, 'OLT de pruebas', '10.0.0.1');
    }

    private function crearOlt(Branch $branch, string $nombre, string $ip): Olt
    {
        return Olt::create([
            'branch_id' => $branch->id,
            'name' => $nombre,
            'ip_address' => $ip,
            'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
            'read_snmp_comunity' => 'public',
            'username' => 'admin', 'password' => 'secret',
            'brand' => 'huawei', 'uptime' => '0',
        ]);
    }

    public function test_registra_una_vlan(): void
    {
        $this->post(route('olt.vlans.store'), [
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
            'description' => 'VLAN de datos',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('vlan_olts', [
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ]);
    }

    public function test_registra_un_perfil_de_servicio(): void
    {
        $this->post(route('olt.srvprofiles.store'), [
            'olt_id' => $this->olt->id,
            'id_srv_profile' => '10',
            'name' => 'SmartOLT_G',
            'description' => 'Perfil de servicio',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('srv_profiles', [
            'olt_id' => $this->olt->id,
            'id_srv_profile' => '10',
            'name' => 'SmartOLT_G',
        ]);
    }

    public function test_registra_un_perfil_de_linea(): void
    {
        $this->post(route('olt.lineprofiles.store'), [
            'olt_id' => $this->olt->id,
            'id_line_profile' => '20',
            'name' => 'LINE_100M',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('line_profiles', [
            'olt_id' => $this->olt->id,
            'id_line_profile' => '20',
            'name' => 'LINE_100M',
        ]);
    }

    /**
     * La unicidad es por OLT, no global: la VLAN 100 es un valor
     * habitual y debe poder existir en varios equipos.
     */
    public function test_permite_el_mismo_id_en_otra_olt(): void
    {
        $otraOlt = $this->crearOlt($this->branch, 'Segunda OLT', '10.0.0.2');

        VlanOlt::create([
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ]);

        $this->post(route('olt.vlans.store'), [
            'olt_id' => $otraOlt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, VlanOlt::where('id_vlan', 100)->count());
    }

    public function test_no_permite_el_mismo_id_dos_veces_en_la_misma_olt(): void
    {
        VlanOlt::create([
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ]);

        $this->post(route('olt.vlans.store'), [
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'OTRA',
        ])->assertRedirect()->assertSessionHasErrors('id_vlan', null, 'vlan');

        $this->assertSame(1, VlanOlt::where('olt_id', $this->olt->id)->count());
    }

    /**
     * El olt_id viaja en un campo oculto del formulario: sin
     * comprobarlo se podría escribir en una OLT de otra sucursal.
     */
    public function test_bloquea_olts_de_otra_sucursal(): void
    {
        $otraSucursal = Branch::factory()->create();
        $oltAjena = $this->crearOlt($otraSucursal, 'OLT ajena', '10.9.9.9');

        $this->post(route('olt.srvprofiles.store'), [
            'olt_id' => $oltAjena->id,
            'id_srv_profile' => '10',
            'name' => 'INTRUSO',
        ])->assertForbidden();

        $this->assertDatabaseMissing('srv_profiles', ['name' => 'INTRUSO']);
    }

    /**
     * Los errores van en un contenedor propio de cada formulario:
     * los tres modales comparten los campos "name" y "description".
     */
    public function test_los_errores_no_se_mezclan_entre_formularios(): void
    {
        $this->post(route('olt.vlans.store'), [
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => '',
        ])
            ->assertSessionHasErrors('name', null, 'vlan')
            ->assertSessionDoesntHaveErrors('name', null, 'srvProfile');
    }

    public function test_la_pantalla_de_edicion_muestra_las_tres_pestanas(): void
    {
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => 100, 'name' => 'INTERNET']);
        SrvProfile::create(['olt_id' => $this->olt->id, 'id_srv_profile' => '10', 'name' => 'SRV']);
        LineProfile::create(['olt_id' => $this->olt->id, 'id_line_profile' => '20', 'name' => 'LINE']);

        $this->get(route('olts.edit', $this->olt))
            ->assertOk()
            ->assertSee('Agregar VLAN')
            ->assertSee('Agregar perfil de servicio')
            ->assertSee('Agregar perfil de línea', false);
    }

    public function test_edita_una_vlan(): void
    {
        $vlan = VlanOlt::create([
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ]);

        $this->put(route('olt.vlans.update', $vlan), [
            'id_vlan' => 200,
            'name' => 'INTERNET CORREGIDA',
            'description' => 'Nombre corregido',
        ])->assertRedirect()->assertSessionHas('success');

        $vlan->refresh();

        $this->assertSame('200', (string) $vlan->id_vlan);
        $this->assertSame('INTERNET CORREGIDA', $vlan->name);
    }

    /**
     * Al editar, su propio identificador no debe contar como
     * duplicado: si no, no se podría corregir solo el nombre.
     */
    public function test_permite_guardar_sin_cambiar_el_identificador(): void
    {
        $vlan = VlanOlt::create([
            'olt_id' => $this->olt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ]);

        $this->put(route('olt.vlans.update', $vlan), [
            'id_vlan' => 100,
            'name' => 'OTRO NOMBRE',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('OTRO NOMBRE', $vlan->fresh()->name);
    }

    public function test_no_permite_editar_hacia_un_identificador_ya_usado(): void
    {
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => 100, 'name' => 'UNA']);
        $otra = VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => 200, 'name' => 'OTRA']);

        $this->put(route('olt.vlans.update', $otra), [
            'id_vlan' => 100,
            'name' => 'OTRA',
        ])->assertSessionHasErrors('id_vlan', null, 'vlan');

        $this->assertSame('200', (string) $otra->fresh()->id_vlan);
    }

    /**
     * La OLT no se cambia al editar: se conserva la del registro
     * aunque el formulario envíe otra.
     */
    public function test_editar_no_mueve_el_registro_a_otra_olt(): void
    {
        $otraOlt = $this->crearOlt($this->branch, 'Segunda OLT', '10.0.0.2');

        $vlan = VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => 100, 'name' => 'INTERNET']);

        $this->put(route('olt.vlans.update', $vlan), [
            'olt_id' => $otraOlt->id,
            'id_vlan' => 100,
            'name' => 'INTERNET',
        ])->assertRedirect();

        $this->assertSame($this->olt->id, $vlan->fresh()->olt_id);
    }

    public function test_elimina_un_perfil_de_servicio(): void
    {
        $perfil = SrvProfile::create([
            'olt_id' => $this->olt->id,
            'id_srv_profile' => '10',
            'name' => 'SRV',
        ]);

        $this->delete(route('olt.srvprofiles.destroy', $perfil))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseMissing('srv_profiles', ['id' => $perfil->id]);
    }

    public function test_elimina_un_perfil_de_linea(): void
    {
        $perfil = LineProfile::create([
            'olt_id' => $this->olt->id,
            'id_line_profile' => '20',
            'name' => 'LINE',
        ]);

        $this->delete(route('olt.lineprofiles.destroy', $perfil))->assertRedirect();

        $this->assertDatabaseMissing('line_profiles', ['id' => $perfil->id]);
    }

    public function test_no_edita_ni_elimina_registros_de_otra_sucursal(): void
    {
        $oltAjena = $this->crearOlt(Branch::factory()->create(), 'OLT ajena', '10.9.9.9');
        $vlanAjena = VlanOlt::create(['olt_id' => $oltAjena->id, 'id_vlan' => 100, 'name' => 'AJENA']);

        $this->put(route('olt.vlans.update', $vlanAjena), [
            'id_vlan' => 300,
            'name' => 'INTRUSO',
        ])->assertForbidden();

        $this->delete(route('olt.vlans.destroy', $vlanAjena))->assertForbidden();

        $this->assertDatabaseHas('vlan_olts', ['id' => $vlanAjena->id, 'name' => 'AJENA']);
    }

    /**
     * El listado avisa cuántas ONTs usan cada VLAN antes de
     * borrarla: onts.vlan guarda el número, no una llave foránea,
     * así que la base de datos no lo impediría por sí sola.
     */
    public function test_informa_cuantas_onts_usan_la_vlan(): void
    {
        VlanOlt::create(['olt_id' => $this->olt->id, 'id_vlan' => 100, 'name' => 'INTERNET']);

        foreach ([1, 2] as $onuId) {
            Ont::create([
                'branch_id' => $this->branch->id,
                'olt_id' => $this->olt->id,
                'slot' => 1, 'port' => 1, 'onu_id' => $onuId,
                'sn' => 'HWTC-000' . $onuId,
                'vlan' => 100,
                'status' => 1,
            ]);
        }

        $this->getJson(route('api.vlansolt', $this->olt->id))
            ->assertOk()
            ->assertJsonFragment(['id_vlan' => '100', 'en_uso' => 2]);
    }

    /**
     * El formulario apuntaba a un método que no existía: el botón
     * "Actualizar OLT" no guardaba nada.
     */
    public function test_actualiza_los_datos_de_la_olt(): void
    {
        $this->put(route('olts.update', $this->olt), [
            'name' => 'OLT renombrada',
            'ip_address' => '10.0.0.50',
            'ssh_port' => 2166,
            'telnet_port' => 23,
            'snmp_port' => 161,
            'read_snmp_comunity' => 'comunidad-real',
            'write_snmp_comunity' => 'escritura',
            'username' => 'admin',
            'password' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $this->olt->refresh();

        $this->assertSame('OLT renombrada', $this->olt->name);
        $this->assertSame(2166, $this->olt->ssh_port);
        $this->assertSame('comunidad-real', $this->olt->read_snmp_comunity);
    }

    /**
     * La contraseña en blanco significa "conservar la actual": el
     * usuario no debería reescribirla para corregir otro campo.
     */
    public function test_conserva_la_contrasena_si_se_deja_en_blanco(): void
    {
        $this->put(route('olts.update', $this->olt), $this->datosValidos(['password' => '']))
            ->assertRedirect();

        $this->assertSame('secret', $this->olt->fresh()->password);
    }

    /**
     * Se guarda tal cual porque la conexión SSH necesita
     * recuperarla: un hash no sirve para autenticar contra la OLT.
     */
    public function test_guarda_la_contrasena_utilizable_para_ssh(): void
    {
        $this->put(route('olts.update', $this->olt), $this->datosValidos(['password' => 'nueva-clave']))
            ->assertRedirect();

        $this->assertSame('nueva-clave', $this->olt->fresh()->getPlainPassword());
    }

    /**
     * El campo mostraba el puerto SSH en lugar de la comunidad, así
     * que al guardar la sobrescribía con "22" y las consultas SNMP
     * dejaban de funcionar.
     */
    public function test_la_pantalla_muestra_la_comunidad_snmp_real(): void
    {
        $this->get(route('olts.edit', $this->olt))
            ->assertOk()
            ->assertSee('value="public"', false);
    }

    public function test_no_actualiza_olts_de_otra_sucursal(): void
    {
        $oltAjena = $this->crearOlt(Branch::factory()->create(), 'OLT ajena', '10.9.9.9');

        $this->put(route('olts.update', $oltAjena), $this->datosValidos())
            ->assertForbidden();

        $this->assertSame('OLT ajena', $oltAjena->fresh()->name);
    }

    /** @return array<string, mixed> */
    private function datosValidos(array $sobrescribir = []): array
    {
        return array_merge([
            'name' => 'OLT actualizada',
            'ip_address' => '10.0.0.1',
            'ssh_port' => 22,
            'username' => 'admin',
            'password' => '',
        ], $sobrescribir);
    }

    /**
     * El endpoint que alimenta la pestaña leía una columna mal
     * escrita ("id_srv_pofile") y devolvía el identificador vacío.
     */
    public function test_el_endpoint_devuelve_el_id_del_perfil_de_servicio(): void
    {
        SrvProfile::create([
            'olt_id' => $this->olt->id,
            'id_srv_profile' => '10',
            'name' => 'SmartOLT_G',
        ]);

        $this->getJson(route('api.srvProfile', $this->olt->id))
            ->assertOk()
            ->assertJsonFragment(['id_srv_profile' => '10']);
    }
}
