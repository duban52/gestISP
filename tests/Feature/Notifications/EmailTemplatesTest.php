<?php

namespace Tests\Feature\Notifications;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Notifications\ClientWelcome;
use App\Notifications\InvoiceDueSoon;
use App\Notifications\InvoiceGenerated;
use App\Notifications\InvoiceOverdue;
use App\Notifications\TechnicalOrderAssignedTechnician;
use App\Notifications\TechnicalOrderCreatedClient;
use App\Notifications\TechnicalOrderFinishedClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Plantilla de los correos que envía el sistema.
 *
 * Todos deben salir con la imagen de la sucursal (encabezado, datos de
 * contacto en el pie) y NUNCA con la plantilla genérica de Laravel,
 * que llega en inglés y con otra marca.
 */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Client $cliente;
    private Contract $contrato;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create([
            'name' => 'EasyNet Gómez Plata',
            'number_phone' => '3206181020',
            'address' => 'Parque principal',
        ]);

        $rol = Role::where('name', 'superadministrador')->firstOrFail();

        $this->usuario = User::factory()->create([
            'name' => 'Duban',
            'selected_branch_id' => $this->branch->id,
        ]);
        $this->usuario->assignRole($rol);
        $this->usuario->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $plan = Plan::create([
            'name' => 'Internet 100 Megas',
            'user_id' => $this->usuario->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->cliente = Client::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
            'name' => 'Ana',
        ]);

        $this->contrato = Contract::factory()->create([
            'branch_id' => $this->branch->id,
            'client_id' => $this->cliente->id,
            'plan_id' => $plan->id,
            'status' => 'Activo',
            'user_id' => $this->usuario->id,
        ]);
    }

    /** Renderiza el correo a HTML, tal como saldría enviado. */
    private function render($notificacion, $destinatario): string
    {
        $mensaje = $notificacion->toMail($destinatario);

        return (string) Mail::render($mensaje->view, $mensaje->viewData);
    }

    /** Comprobaciones comunes a todos los correos del sistema. */
    private function assertCorreoConMarca(string $html): void
    {
        // Encabezado y pie con los datos de la sucursal
        $this->assertStringContainsString('EasyNet Gómez Plata', $html);
        $this->assertStringContainsString('3206181020', $html);

        // La tarjeta va a 600 px: el ancho que respetan los gestores
        $this->assertStringContainsString('width="600"', $html);

        // Los estilos van en línea, o los gestores los descartan
        $this->assertStringContainsString('style="', $html);

        // Nunca debe salir la plantilla genérica en inglés
        $this->assertStringNotContainsString('Regards,', $html);
        $this->assertStringNotContainsString('Whoops!', $html);
        $this->assertStringNotContainsString('If you’re having trouble clicking', $html);
    }

    private function factura(array $extra = []): Invoice
    {
        return Invoice::create(array_merge([
            'contract_id' => $this->contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->usuario->id,
            'type' => 'Mensualidad',
            'billed_period' => 'Julio 2026',
            'billed_month_name' => 'Julio',
            'billed_year_month' => '202607',
            'issue_date' => now(),
            'due_date' => now()->addDays(10),
            'subtotal' => 85000,
            'total' => 85000,
            'pending_invoice_amount' => 85000,
            'status' => 'Pendiente',
        ], $extra));
    }

    private function orden(): TechnicalOrder
    {
        return TechnicalOrder::create([
            'contract_id' => $this->contrato->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->usuario->id,
            'created_by' => $this->usuario->id,
            'type' => 'Servicio',
            'detail' => 'Instalación de servicio',
            'status' => 'Asignada',
            'initial_comment' => 'Instalación nueva',
            'solution' => 'Servicio instalado y probado',
        ]);
    }

    // ==================== Facturación ====================

    public function test_el_correo_de_factura_nueva_muestra_el_valor_y_el_vencimiento(): void
    {
        $html = $this->render(new InvoiceGenerated($this->factura()), $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Su factura del mes ya está disponible', $html);
        $this->assertStringContainsString('$85.000', $html);
        $this->assertStringContainsString('Valor a pagar', $html);
        $this->assertStringContainsString('Hola Ana,', $html);
    }

    public function test_el_recordatorio_de_pago_indica_los_dias_que_faltan(): void
    {
        $notificacion = new InvoiceDueSoon($this->factura(), 3);

        $html = $this->render($notificacion, $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('vence en 3 días', $html);
        $this->assertStringContainsString('Saldo pendiente', $html);

        // El asunto también se adapta
        $this->assertSame('Su factura vence en 3 días', $notificacion->toMail($this->cliente)->subject);
    }

    public function test_el_recordatorio_dice_manana_cuando_queda_un_dia(): void
    {
        $notificacion = new InvoiceDueSoon($this->factura(), 1);

        $this->assertSame('Su factura vence mañana', $notificacion->toMail($this->cliente)->subject);
        $this->assertStringContainsString('vence mañana', $this->render($notificacion, $this->cliente));
    }

    public function test_el_correo_de_factura_vencida_usa_el_tono_de_alerta(): void
    {
        $html = $this->render(new InvoiceOverdue($this->factura(['status' => 'Vencida'])), $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Su factura está vencida', $html);
        // Color de alerta en el encabezado
        $this->assertStringContainsString('#B32020', $html);
    }

    // ==================== Cliente ====================

    public function test_la_bienvenida_incluye_el_numero_de_contrato_y_el_plan(): void
    {
        $html = $this->render(new ClientWelcome($this->contrato), $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('¡Bienvenido!', $html);
        $this->assertStringContainsString('Internet 100 Megas', $html);
        $this->assertStringContainsString($this->contrato->numero_visible, $html);
    }

    // ==================== Órdenes técnicas ====================

    public function test_el_aviso_de_solicitud_recibida_detalla_la_orden(): void
    {
        $html = $this->render(new TechnicalOrderCreatedClient($this->orden()), $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Recibimos su solicitud', $html);
        $this->assertStringContainsString('Instalación de servicio', $html);
    }

    public function test_el_aviso_de_servicio_resuelto_incluye_la_solucion(): void
    {
        $html = $this->render(new TechnicalOrderFinishedClient($this->orden()), $this->cliente);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Su servicio quedó resuelto', $html);
        $this->assertStringContainsString('Servicio instalado y probado', $html);
    }

    public function test_el_correo_al_tecnico_trae_los_datos_de_la_visita_y_un_boton(): void
    {
        $html = $this->render(new TechnicalOrderAssignedTechnician($this->orden()), $this->usuario);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Se le asignó una orden técnica', $html);
        // Datos que el técnico necesita en campo
        $this->assertStringContainsString('Ana', $html);
        $this->assertStringContainsString($this->contrato->address, $html);
        // Botón para entrar al sistema
        $this->assertStringContainsString(route('technicals_orders.my_technical_orders'), $html);
    }

    // ============ Correo del sistema: contraseña ============

    public function test_el_correo_para_restablecer_la_contrasena_usa_la_plantilla_propia(): void
    {
        $notificacion = new ResetPassword('token-de-prueba');
        $mensaje = $notificacion->toMail($this->usuario);

        $html = (string) Mail::render($mensaje->view, $mensaje->viewData);

        $this->assertCorreoConMarca($html);
        $this->assertStringContainsString('Restablecer su contraseña', $html);
        $this->assertStringContainsString('Crear contraseña nueva', $html);
        $this->assertStringContainsString('token-de-prueba', $html);
        $this->assertStringContainsString('Hola Duban,', $html);

        // Debe advertir la caducidad y el caso de "yo no fui"
        $this->assertStringContainsString('minutos', $html);
        $this->assertStringContainsString('no solicitó este cambio', $html);

        // Y el asunto en español, no el de Laravel ("Reset Password Notification")
        $this->assertStringContainsString('Restablecer su contraseña', $mensaje->subject);
    }

    // ==================== Plantilla ====================

    public function test_la_plantilla_avisa_que_no_se_responda_al_correo(): void
    {
        $html = $this->render(new InvoiceGenerated($this->factura()), $this->cliente);

        $this->assertStringContainsString('no responda a este correo', $html);
    }

    public function test_la_plantilla_incluye_texto_de_vista_previa(): void
    {
        // Es lo que se lee en la bandeja junto al asunto
        $html = $this->render(new InvoiceGenerated($this->factura()), $this->cliente);

        $this->assertStringContainsString('display:none', $html);
        $this->assertStringContainsString('Factura', $html);
    }
}
