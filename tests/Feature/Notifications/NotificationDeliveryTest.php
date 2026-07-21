<?php

namespace Tests\Feature\Notifications;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Events\InvoiceIssued;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Notifications\ClientWelcome;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\InvoiceDueSoon;
use App\Notifications\InvoiceGenerated;
use App\Notifications\InvoiceOverdue;
use App\Notifications\TechnicalOrderAssignedTechnician;
use App\Notifications\TechnicalOrderCreatedClient;
use App\Notifications\TechnicalOrderFinishedClient;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Disparo de las notificaciones desde los flujos del negocio.
 *
 * Se usa Notification::fake(): no se envía nada real, solo se
 * comprueba que cada acción dispara la notificación correcta, al
 * destinatario correcto y por los canales esperados.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;
    private Role $superRole;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->superRole = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3001112233']);
        $this->admin->assignRole($this->superRole);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $this->superRole->id]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $this->superRole->id,
        ]);
    }

    private function cliente(array $atributos = []): Client
    {
        return Client::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'number_phone' => '3155554433',
            'email' => 'cliente@ejemplo.com',
        ], $atributos));
    }

    private function contrato(?Client $cliente = null, array $atributos = []): Contract
    {
        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => ($cliente ?? $this->cliente())->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'user_id' => $this->admin->id,
        ], $atributos));
    }

    private function factura(Contract $contrato, array $atributos = []): Invoice
    {
        return Invoice::create(array_merge([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'issue_date' => now(),
            'due_date' => now()->addDays(10),
            'subtotal' => 50000, 'total' => 50000,
            'pending_invoice_amount' => 50000,
            'status' => InvoiceStatus::Pendiente->value,
        ], $atributos));
    }

    // ==================== Cliente ====================

    public function test_bienvenida_al_crear_el_contrato(): void
    {
        Notification::fake();

        $cliente = $this->cliente();

        $this->post(route('contracts.store'), [
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'neighborhood' => 'Centro',
            'address' => 'Calle 1',
            'home_type' => 'Propia',
            'social_stratum' => '3',
        ]);

        Notification::assertSentTo($cliente, ClientWelcome::class);
    }

    public function test_factura_generada_avisa_al_cliente(): void
    {
        Notification::fake();

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $factura = $this->factura($contrato);

        event(new InvoiceIssued($factura));

        Notification::assertSentTo($cliente, InvoiceGenerated::class);
    }

    public function test_recordatorio_por_vencer_es_idempotente(): void
    {
        Notification::fake();

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        // Configuración por defecto: 3 días antes
        $this->factura($contrato, ['due_date' => now()->addDays(3)]);

        $this->artisan('invoices:notify-reminders');
        $this->artisan('invoices:notify-reminders'); // segunda corrida

        // Aunque el comando corra dos veces, el cliente recibe UN
        // solo recordatorio
        Notification::assertSentToTimes($cliente, InvoiceDueSoon::class, 1);
    }

    public function test_factura_vencida_avisa_una_sola_vez(): void
    {
        Notification::fake();

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $this->factura($contrato, [
            'status' => InvoiceStatus::Vencida->value,
            'due_date' => now()->subDays(5),
        ]);

        $this->artisan('invoices:notify-reminders');
        $this->artisan('invoices:notify-reminders');

        Notification::assertSentToTimes($cliente, InvoiceOverdue::class, 1);
    }

    public function test_orden_creada_avisa_al_cliente(): void
    {
        Notification::fake();

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);

        $this->post(route('technicals_orders.store'), [
            'contract_id' => $contrato->id,
            'order_type' => 'Servicio',
            'order_detail' => 'Sin servicio de internet',
            'initial_comment' => 'Reporta falla',
        ]);

        Notification::assertSentTo($cliente, TechnicalOrderCreatedClient::class);
    }

    public function test_orden_finalizada_avisa_al_cliente(): void
    {
        Notification::fake();

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);
        $orden = TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'user_assigned' => $this->admin->id,
            'type' => 'Servicio',
            'status' => 'Prefinalizada',
            'detail' => 'Reconexión',
            'initial_comment' => 'x',
        ]);

        $this->put(route('technical_order.verification_process', $orden), [
            'verification_comment' => 'Todo bien',
            'close_order' => '1',
        ]);

        Notification::assertSentTo($cliente, TechnicalOrderFinishedClient::class);
    }

    // ==================== Técnico ====================

    public function test_orden_asignada_avisa_al_tecnico_por_tres_canales(): void
    {
        Notification::fake();

        $tecnico = User::factory()->create(['number_phone' => '3159998877']);
        $tecnico->assignRole(Role::where('name', 'tecnico')->firstOrFail());
        $tecnico->branches()->attach($this->branch->id, [
            'role_id' => Role::where('name', 'tecnico')->firstOrFail()->id,
        ]);

        $contrato = $this->contrato();
        $orden = TechnicalOrder::create([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'created_by' => $this->admin->id,
            'type' => 'Servicio',
            'status' => 'Pendiente',
            'detail' => 'Instalación de servicio',
            'initial_comment' => 'x',
        ]);

        $this->put(route('technicals_orders.update', $orden), [
            'assigned_user_id' => $tecnico->id,
        ]);

        Notification::assertSentTo(
            $tecnico,
            TechnicalOrderAssignedTechnician::class,
            function ($notification, $channels) {
                return in_array('mail', $channels)
                    && in_array('database', $channels)
                    && in_array(WhatsAppChannel::class, $channels);
            }
        );
    }

    // ==================== Canales ====================

    public function test_un_cliente_sin_correo_no_usa_el_canal_de_correo(): void
    {
        // La columna email no admite null; un cliente sin correo se
        // representa con cadena vacía.
        $cliente = $this->cliente(['email' => '']);
        $contrato = $this->contrato($cliente);

        $via = (new ClientWelcome($contrato))->via($cliente);

        $this->assertNotContains('mail', $via);
        $this->assertContains(WhatsAppChannel::class, $via);
    }

    public function test_si_se_apaga_whatsapp_no_se_incluye_ese_canal(): void
    {
        config(['notifications.channels.whatsapp' => false]);

        $cliente = $this->cliente();
        $contrato = $this->contrato($cliente);

        $via = (new ClientWelcome($contrato))->via($cliente);

        $this->assertNotContains(WhatsAppChannel::class, $via);
        $this->assertContains('mail', $via);
    }
}
