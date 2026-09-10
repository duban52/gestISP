<?php

namespace Tests\Feature\Billing;

use App\Models\DocumentTransmission;
use App\Models\ElectronicDocument;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * La pantalla que dice qué pasó con cada documento ante la DIAN.
 *
 * DE DÓNDE SALE
 * -------------
 * De que hasta ahora la única forma de saber si una factura llegó a la
 * DIAN, o por qué la rechazó, era entrar al servidor por SSH. Una
 * factura rechazada no avisa sola: se queda ahí, sin valor fiscal, hasta
 * que alguien mira.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * Que los motivos del rechazo se lean. La DIAN los devuelve pegados con
 * « | » en un solo texto —«Regla: FAS07, Rechazo: ... | Regla: FAK61,
 * ...»— y así son ilegibles. Son justo lo que hay que corregir.
 *
 * Y que la pantalla distinga las tres cosas que se confunden: validado
 * por la DIAN, entregado al cliente, y esperando envío.
 */
class ElectronicDocumentLogTest extends BillingTestCase
{
    // EL setUp DEL RASGO SE RENOMBRA, NO SE PISA.
    //
    // Un metodo declarado en la clase gana sobre el del rasgo, en
    // silencio. Al declarar aqui `setUp()` sin mas, el del rasgo no
    // corria: sin configuracion DIAN ni rango autorizado las facturas
    // salian INTERNAS y esta pantalla no tenia nada que enseñar.
    use ConstruyeFacturasElectronicas {
        setUp as prepararFacturacionElectronica;
    }

    protected function setUp(): void
    {
        $this->prepararFacturacionElectronica();

        // El permiso lo crea `permissions:sync` a partir del
        // controlador; en pruebas se declara a mano. Va al ROL y no al
        // usuario: es el rol de la sesion lo que mira el middleware.
        Permission::firstOrCreate(
            ['name' => 'dian.documents', 'guard_name' => 'web'],
            ['description' => 'Ver el estado de los documentos DIAN'],
        );

        Role::where('name', 'superadministrador')->firstOrFail()
            ->givePermissionTo('dian.documents');
    }

    private function documentoDe($factura): ElectronicDocument
    {
        return ElectronicDocument::withoutGlobalScopes()
            ->where('invoice_id', $factura->id)
            ->firstOrFail();
    }

    public function test_el_listado_muestra_los_documentos(): void
    {
        $factura = $this->facturaElectronica();

        $this->get(route('dian.log.index'))
            ->assertOk()
            ->assertSee($factura->full_number);
    }

    public function test_los_motivos_del_rechazo_se_leen_uno_por_uno(): void
    {
        // Es la razón de existir de la pantalla. Tal como los devuelve
        // la DIAN van pegados en un solo texto y no hay quien los lea.
        $factura = $this->facturaElectronica();
        $documento = $this->documentoDe($factura);

        $documento->forceFill([
            'status' => ElectronicDocument::RECHAZADO,
            'last_error' => 'Regla: FAS07, Rechazo: El valor del tributo informado no corresponde'
                . ' | Regla: FAK61, Rechazo: Si el valor de AdditionalAccountID es igual a "2"',
        ])->save();

        $respuesta = $this->get(route('dian.log.show', $documento))->assertOk();

        $respuesta->assertSee('FAS07', false);
        $respuesta->assertSee('FAK61', false);

        // Y se dice lo que hay que hacer: un rechazado NO se reintenta.
        $respuesta->assertSee('no tiene valor fiscal', false);
    }

    public function test_distingue_validado_de_entregado(): void
    {
        // Son dos obligaciones distintas, y confundirlas hace creer que
        // el cliente ya tiene su factura cuando no la tiene.
        $factura = $this->facturaElectronica();
        $documento = $this->documentoDe($factura);

        $documento->forceFill([
            'status' => ElectronicDocument::ACEPTADO,
            'accepted_at' => now(),
            'delivered_at' => null,
        ])->save();

        $this->get(route('dian.log.show', $documento))
            ->assertOk()
            ->assertSee('todavía no entregada', false);
    }

    public function test_avisa_de_lo_que_lleva_esperando_envio(): void
    {
        // Un documento firmado y sin transmitir significa que algo no
        // está corriendo. Nadie lo va a notar solo.
        $this->facturaElectronica();

        $this->get(route('dian.log.index'))
            ->assertOk()
            ->assertSee('todavía no se han', false);
    }

    public function test_el_historial_de_intentos_se_ve(): void
    {
        // Distingue un problema de CONTENIDO de uno de COMUNICACIÓN: si
        // hay errores y ningún rechazo, la DIAN no llegó a verlo.
        $factura = $this->facturaElectronica();
        $documento = $this->documentoDe($factura);

        DocumentTransmission::withoutGlobalScopes()->create([
            'electronic_document_id' => $documento->id,
            'company_id' => $documento->company_id,
            'attempt' => 1,
            'outcome' => 'error',
            'http_status' => 500,
            'errors' => ['La DIAN respondió con un error 500.'],
            'duration_ms' => 1234,
        ]);

        $this->get(route('dian.log.show', $documento))
            ->assertOk()
            ->assertSee('Error de comunicación', false)
            ->assertSee('error 500', false);
    }

    public function test_filtrar_por_estado(): void
    {
        $factura = $this->facturaElectronica();
        $documento = $this->documentoDe($factura);
        $documento->forceFill(['status' => ElectronicDocument::RECHAZADO])->save();

        $this->get(route('dian.log.index', ['estado' => ElectronicDocument::RECHAZADO]))
            ->assertOk()
            ->assertSee($factura->full_number);

        $this->get(route('dian.log.index', ['estado' => ElectronicDocument::ACEPTADO]))
            ->assertOk()
            ->assertDontSee($factura->full_number);
    }

    public function test_sin_permiso_no_se_ve(): void
    {
        // Lleva CUFE, motivos de rechazo y datos de clientes: no es una
        // pantalla para cualquiera.
        Role::where('name', 'superadministrador')->firstOrFail()
            ->revokePermissionTo('dian.documents');

        $this->get(route('dian.log.index'))->assertForbidden();
    }
}
