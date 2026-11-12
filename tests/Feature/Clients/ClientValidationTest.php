<?php

namespace Tests\Feature\Clients;

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
 * Lo que el formulario de clientes rechaza, y cómo lo dice.
 *
 * El alta feliz y el documento duplicado ya están cubiertos en
 * ClientBelongsToCompanyTest. Aquí va lo demás: que cada dato mal
 * puesto se conteste con un mensaje junto a su campo —y nunca con un
 * error 500—, que una petición AJAX reciba sus errores en JSON, y que
 * un alta que falla a medias no deje un cliente a medio crear.
 */
class ClientValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;
    private Branch $sucursal;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->seed(RoleSeeder::class);

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->empresa = Company::factory()->create();
        $this->sucursal = Branch::factory()->create(['company_id' => $this->empresa->id]);

        $this->usuario = User::factory()->create();
        $this->usuario->assignRole($rol);
        $this->usuario->branches()->attach($this->sucursal->id, ['role_id' => $rol->id]);

        $this->actingAs($this->usuario)->withSession([
            'company_id' => $this->empresa->id,
            'branch_id' => (string) $this->sucursal->id,
            'branch_ids' => [$this->sucursal->id],
            'current_role_id' => (string) $rol->id,
        ]);

        app(CurrentContext::class)->establecer($this->empresa->id, [$this->sucursal->id], $this->sucursal->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'document_type_code' => '13',
            'identity_number' => '1042772330',
            'name' => 'Juan',
            'last_name' => 'Pérez',
            'type_client' => 'Residencial',
            'number_phone' => '3001234567',
            'email' => 'juan@ejemplo.com',
        ], $extra);
    }

    // ==================== Lo obligatorio ====================

    /** @dataProvider camposObligatorios */
    public function test_sin_un_dato_obligatorio_no_se_crea_el_cliente(string $campo): void
    {
        $this->post(route('clients.store'), $this->datos([$campo => '']))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public static function camposObligatorios(): array
    {
        return [
            'tipo de documento' => ['document_type_code'],
            'número de documento' => ['identity_number'],
            'nombres' => ['name'],
            'apellidos' => ['last_name'],
            'tipo de cliente' => ['type_client'],
            'teléfono' => ['number_phone'],
        ];
    }

    // ==================== Lo mal escrito ====================

    public function test_un_correo_mal_escrito_se_rechaza(): void
    {
        $this->post(route('clients.store'), $this->datos(['email' => 'esto-no-es-un-correo']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public function test_el_correo_es_opcional(): void
    {
        // Hay clientes sin correo, y exigirlo obligaba a inventarlo.
        $this->post(route('clients.store'), $this->datos(['email' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Client::withoutGlobalScopes()->count());
    }

    /**
     * Los topes son los de las COLUMNAS.
     *
     * Un valor que pasa la validación y no cabe en la base no da un
     * mensaje: da un «Data too long» de MySQL y una página de error.
     *
     * @dataProvider valoresQueNoCaben
     */
    public function test_lo_que_no_cabe_en_la_columna_se_rechaza_antes(string $campo, int $largo): void
    {
        $this->post(route('clients.store'), $this->datos([$campo => str_repeat('9', $largo)]))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    public static function valoresQueNoCaben(): array
    {
        return [
            'documento de 21 caracteres' => ['identity_number', 21],
            'teléfono de 21 caracteres' => ['number_phone', 21],
            'teléfono adicional de 21' => ['aditional_phone', 21],
            'nombre de 41 caracteres' => ['name', 41],
        ];
    }

    public function test_una_fecha_de_nacimiento_imposible_se_rechaza(): void
    {
        $this->post(route('clients.store'), $this->datos(['birthday' => '2026-99-99']))
            ->assertSessionHasErrors('birthday');
    }

    public function test_al_fallar_se_conserva_lo_que_se_habia_escrito(): void
    {
        // Volver a escribir once campos porque uno estaba mal es la
        // forma más rápida de que alguien deje de usar el formulario.
        $this->post(route('clients.store'), $this->datos(['email' => 'malo']))
            ->assertSessionHasInput('name', 'Juan')
            ->assertSessionHasInput('identity_number', '1042772330');
    }

    // ==================== Peticiones AJAX ====================

    public function test_una_peticion_ajax_recibe_los_errores_en_json(): void
    {
        $this->postJson(route('clients.store'), $this->datos(['name' => '', 'email' => 'malo']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }

    // ==================== Editar y consultar ====================

    /** Un cliente ya creado, con todos sus datos de contacto. */
    private function clienteCreado(): Client
    {
        $this->post(route('clients.store'), $this->datos([
            'aditional_phone' => '6041112233',
        ]))->assertSessionHasNoErrors();

        return Client::withoutGlobalScopes()->firstOrFail();
    }

    public function test_editar_un_cliente_guarda_los_datos_de_contacto(): void
    {
        $cliente = $this->clienteCreado();

        $this->put(route('clients.update', $cliente), [
            'number_phone' => '3009998877',
            'aditional_phone' => '6045556677',
            'email' => 'nuevo@ejemplo.com',
        ])->assertSessionHasNoErrors();

        $cliente->refresh();

        $this->assertSame('3009998877', $cliente->number_phone);
        $this->assertSame('6045556677', $cliente->aditional_phone);
        $this->assertSame('nuevo@ejemplo.com', $cliente->email);
    }

    /**
     * EL MISMO FALLO QUE EL BARRIO DEL CONTRATO.
     *
     * `number_phone` es NOT NULL y la edición lo escribía sin validar:
     * borrar el teléfono y guardar era un error 500.
     */
    public function test_borrar_el_telefono_al_editar_da_un_mensaje_y_no_un_500(): void
    {
        $cliente = $this->clienteCreado();

        $this->put(route('clients.update', $cliente), ['number_phone' => ''])
            ->assertSessionHasErrors('number_phone');

        $this->assertSame('3001234567', $cliente->fresh()->number_phone);
    }

    public function test_un_correo_mal_escrito_tampoco_entra_al_editar(): void
    {
        $cliente = $this->clienteCreado();

        $this->put(route('clients.update', $cliente), [
            'number_phone' => '3001234567',
            'email' => 'sin-arroba',
        ])->assertSessionHasErrors('email');

        $this->assertSame('juan@ejemplo.com', $cliente->fresh()->email);
    }

    /**
     * Mandar solo el teléfono no borra lo demás.
     *
     * La edición ponía en NULO el correo y el teléfono adicional si la
     * petición no los traía. Un formulario que solo corrige un dato no
     * puede llevarse por delante los otros dos.
     */
    public function test_corregir_solo_el_telefono_no_borra_el_correo(): void
    {
        $cliente = $this->clienteCreado();

        $this->put(route('clients.update', $cliente), ['number_phone' => '3110001122'])
            ->assertSessionHasNoErrors();

        $cliente->refresh();

        $this->assertSame('3110001122', $cliente->number_phone);
        $this->assertSame('juan@ejemplo.com', $cliente->email);
        $this->assertSame('6041112233', $cliente->aditional_phone);
    }

    public function test_el_documento_no_se_puede_cambiar_al_editar(): void
    {
        // Identifica al cliente: cambiarlo es crear otro. Es una regla
        // que ya existía y que estas pruebas no deben aflojar.
        $cliente = $this->clienteCreado();

        $this->put(route('clients.update', $cliente), [
            'number_phone' => '3001234567',
            'document_type_code' => '31',
        ])->assertSessionHasErrors('document_type_code');
    }

    public function test_un_cliente_que_no_existe_da_404_y_no_500(): void
    {
        $this->get(route('clients.edit', 999999))->assertNotFound();
    }

    public function test_el_cliente_de_otra_empresa_tampoco_se_puede_abrir(): void
    {
        // El scope de empresa lo esconde: para quien mira, no existe.
        $ajeno = Client::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'branch_id' => null,
            'user_id' => $this->usuario->id,
        ]);

        $this->get(route('clients.edit', $ajeno->id))->assertNotFound();
    }

    // ==================== Nada a medias ====================

    /**
     * Si el alta falla a mitad, no queda un cliente a medio crear.
     *
     * Cliente y responsabilidades fiscales se guardaban en dos pasos
     * sueltos: si fallaba el segundo, el cliente quedaba creado, la
     * pantalla daba error y al reintentar respondía «ya existe un
     * cliente con ese documento» — no se podía ni terminar ni repetir.
     */
    public function test_si_falla_a_mitad_no_queda_un_cliente_a_medias(): void
    {
        // Un fallo provocado justo DESPUÉS de crear el cliente y antes
        // de terminar el alta.
        Client::created(function () {
            throw new \RuntimeException('Fallo simulado tras crear el cliente');
        });

        $this->post(route('clients.store'), $this->datos())->assertStatus(500);

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    // ==================== El formulario, tal como se ve ====================

    /**
     * Tras un error, el formulario vuelve RELLENO y con el motivo.
     *
     * Casi ningún campo usaba `old()` ni pintaba su error: un correo
     * mal escrito devolvía el formulario vacío y sin una palabra, que
     * desde fuera es un botón que no hace nada.
     */
    public function test_tras_un_error_el_formulario_vuelve_relleno_y_dice_que_fallo(): void
    {
        $pagina = $this->followingRedirects()
            ->from(route('clients.create'))
            ->post(route('clients.store'), $this->datos(['email' => 'sin-arroba']))
            ->assertOk();

        // Lo que ya se había escrito sigue ahí...
        $pagina->assertSee('value="Juan"', escape: false);
        $pagina->assertSee('value="1042772330"', escape: false);
        $pagina->assertSee('value="3001234567"', escape: false);
        // ...y el campo culpable está señalado, con su mensaje.
        $pagina->assertSee('is-invalid', escape: false);
        $pagina->assertSee('El correo electrónico no tiene un formato válido', escape: false);
    }

    public function test_un_documento_repetido_se_explica_en_pantalla(): void
    {
        $this->post(route('clients.store'), $this->datos())->assertSessionHasNoErrors();

        $this->followingRedirects()
            ->from(route('clients.create'))
            ->post(route('clients.store'), $this->datos())
            ->assertOk()
            ->assertSee('Ya existe un cliente con ese documento', escape: false);
    }

    /**
     * La opción vacía del tipo de cliente manda vacío.
     *
     * No tenía `value`, así que el navegador enviaba su TEXTO y se
     * guardaba «Seleccione una opción» como tipo de cliente.
     */
    public function test_no_se_puede_guardar_el_texto_de_la_opcion_vacia_como_tipo(): void
    {
        $this->get(route('clients.create'))
            ->assertOk()
            ->assertSee('<option value="">Seleccione una opción</option>', escape: false);

        // Y aunque una página vieja todavía lo mande, el servidor no
        // lo acepta.
        $this->post(route('clients.store'), $this->datos(['type_client' => 'Seleccione una opción']))
            ->assertSessionHasErrors('type_client');

        $this->assertSame(0, Client::withoutGlobalScopes()->count());
    }

    // ==================== La pantalla de edición ====================

    /**
     * EL BOTÓN «ACTUALIZAR» NO PUEDE LLEVAR UN DELETE DENTRO.
     *
     * El formulario de eliminar estaba anidado en el de actualizar. El
     * navegador descarta el `<form>` interior y sus campos pasan al
     * exterior, que acababa enviando `_method=PUT` y `_method=DELETE`;
     * PHP se queda con el último y la petición llegaba al borrado.
     * Pulsar «Actualizar cliente» eliminaba al cliente.
     *
     * Se mira la página YA RENDERIZADA, con sus parciales dentro: lo
     * que hay entre la apertura del formulario de actualizar y su
     * primer `</form>` es exactamente lo que el navegador considera
     * suyo, y ahí no puede haber ni otro formulario ni un DELETE.
     */
    public function test_el_formulario_de_actualizar_no_contiene_el_de_eliminar(): void
    {
        $cliente = $this->clienteCreado();

        $html = $this->get(route('clients.edit', $cliente))->assertOk()->getContent();

        $inicio = strpos($html, 'id="formCliente"');
        $this->assertNotFalse($inicio, 'No se encontró el formulario de actualizar.');

        $fin = strpos($html, '</form>', $inicio);
        $suyo = substr($html, $inicio, $fin - $inicio);

        $this->assertStringContainsString('value="PUT"', $suyo);
        $this->assertStringNotContainsString('value="DELETE"', $suyo, 'El formulario de actualizar lleva un DELETE dentro.');
        $this->assertStringNotContainsString('<form', $suyo, 'Hay un formulario dentro del de actualizar.');

        // El de eliminar existe, pero APARTE.
        $this->assertStringContainsString('id="formEliminarCliente"', $html);
    }

    public function test_guardar_bien_lo_dice_en_la_pantalla(): void
    {
        // La edición pintaba `success-update` y el controlador avisa
        // con `success`: guardar bien no mostraba nada.
        $cliente = $this->clienteCreado();

        $this->followingRedirects()
            ->from(route('clients.edit', $cliente))
            ->put(route('clients.update', $cliente), ['number_phone' => '3110001122'])
            ->assertOk()
            ->assertSee('Datos del cliente actualizados', escape: false)
            ->assertSee('value="3110001122"', escape: false);
    }

    public function test_un_cliente_con_contratos_no_ofrece_el_boton_de_eliminar(): void
    {
        // Mejor decirlo de entrada que dejar pulsar para descubrirlo.
        $cliente = $this->clienteCreado();

        \App\Models\Contract::factory()->create([
            'branch_id' => $this->sucursal->id,
            'client_id' => $cliente->id,
            'plan_id' => \App\Models\Plan::factory()->create([
                'branch_id' => $this->sucursal->id,
                'user_id' => $this->usuario->id,
            ])->id,
            'user_id' => $this->usuario->id,
        ]);

        $this->get(route('clients.edit', $cliente))
            ->assertOk()
            ->assertSee('1 contrato', escape: false)
            ->assertDontSee('id="formEliminarCliente"', escape: false);
    }
}
