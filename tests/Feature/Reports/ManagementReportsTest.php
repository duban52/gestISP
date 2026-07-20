<?php

namespace Tests\Feature\Reports;

use App\Billing\Enums\InvoiceStatus;
use App\Billing\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Ont;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TechnicalOrder;
use App\Models\User;
use App\Reports\BillingReport;
use App\Reports\Enums\Granularity;
use App\Reports\GrowthReport;
use App\Reports\ProvisioningReport;
use App\Reports\Support\ContractStatusMap;
use App\Reports\Support\ReportPeriod;
use App\Reports\TechnicalOrdersReport;
use Database\Seeders\ManagementReportsPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Informes gerenciales.
 *
 * Se comprueba lo que hace que las cifras sean creíbles: que los
 * dos vocabularios de estado se cuenten juntos, que los períodos
 * sin movimiento aparezcan en cero, que una sucursal no vea los
 * datos de otra y que las cifras de dinero excluyan lo que no es
 * ingreso real.
 */
class ManagementReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otraBranch;
    private User $admin;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(ManagementReportsPermissionSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->otraBranch = Branch::factory()->create();

        $role = Role::where('name', 'superadministrador')->firstOrFail();

        $this->admin = User::factory()->create(['number_phone' => '3000000000']);
        $this->admin->assignRole($role);
        $this->admin->branches()->attach($this->branch->id, ['role_id' => $role->id]);

        $this->actingAs($this->admin)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $role->id,
        ]);

        $this->plan = Plan::create([
            'name' => 'Plan 100M',
            'user_id' => $this->admin->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function contrato(array $atributos = []): Contract
    {
        $cliente = Client::factory()->create([
            'branch_id' => $atributos['branch_id'] ?? $this->branch->id,
            'user_id' => $this->admin->id,
        ]);

        // Se usa la factory para no tener que enumerar aquí todas
        // las columnas obligatorias de la tabla
        return Contract::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'client_id' => $cliente->id,
            'plan_id' => $this->plan->id,
            'status' => 'Activo',
            'activation_date' => '2026-03-10',
            'user_id' => $this->admin->id,
        ], $atributos));
    }

    private function periodo(string $desde = '2026-01-01', string $hasta = '2026-06-30', string $gran = 'month'): ReportPeriod
    {
        return ReportPeriod::fromRequest($desde, $hasta, $gran);
    }

    // ==================== Períodos ====================

    public function test_la_serie_incluye_los_periodos_sin_movimiento(): void
    {
        $this->contrato(['activation_date' => '2026-03-10']);

        $series = (new GrowthReport($this->periodo(), $this->branch->id))->series();

        // Enero a junio: seis puntos, aunque solo marzo tenga datos
        $this->assertCount(6, $series['labels']);
        $this->assertSame(1, $series['altas']->sum());
        $this->assertSame(0, $series['altas'][0], 'Enero debe aparecer en cero, no ausente');
    }

    public function test_cada_granularidad_produce_su_propia_serie(): void
    {
        $esperado = [
            'day' => 31,
            'week' => 5,
            'month' => 1,
            'year' => 1,
        ];

        foreach ($esperado as $gran => $minimo) {
            $series = (new GrowthReport(
                $this->periodo('2026-03-01', '2026-03-31', $gran),
                $this->branch->id
            ))->series();

            $this->assertGreaterThanOrEqual(
                $minimo,
                $series['labels']->count(),
                "La granularidad {$gran} no generó los períodos esperados"
            );
        }
    }

    public function test_el_periodo_anterior_tiene_la_misma_duracion(): void
    {
        $periodo = $this->periodo('2026-04-01', '2026-04-30');
        $anterior = $periodo->anterior();

        $this->assertSame('2026-03-02', $anterior->from->format('Y-m-d'));
        $this->assertSame('2026-03-31', $anterior->to->format('Y-m-d'));
    }

    // ============ Normalización de estados ============

    /**
     * El corazón del módulo: "Cortado" y "Suspendido" son el mismo
     * estado escrito por dos partes distintas del sistema.
     */
    public function test_agrupa_los_dos_vocabularios_de_estado(): void
    {
        $this->contrato(['status' => 'Suspendido']);
        $this->contrato(['status' => 'Cortado']);
        $this->contrato(['status' => 'Por Reconexión']);
        $this->contrato(['status' => 'Por Reconectar']);

        $estados = (new GrowthReport($this->periodo(), $this->branch->id))
            ->distribucionEstados()
            ->keyBy('grupo');

        $this->assertSame(2, $estados['suspendido']['total'], 'Suspendido y Cortado deben sumarse');
        $this->assertSame(2, $estados['por_reconectar']['total'], 'Ambas formas de reconexión deben sumarse');
    }

    public function test_los_retirados_cuentan_como_baja_y_no_como_vigentes(): void
    {
        $this->contrato(['status' => 'Activo']);
        $this->contrato(['status' => 'Retirado']);

        $resumen = (new GrowthReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(1, $resumen['vigentes']);
    }

    /**
     * Un estado nuevo no debe desaparecer del informe: se muestra
     * aparte para que alguien lo note.
     */
    public function test_un_estado_desconocido_queda_visible(): void
    {
        $this->contrato(['status' => 'Congelado']);

        $estados = (new GrowthReport($this->periodo(), $this->branch->id))
            ->distribucionEstados()
            ->keyBy('grupo');

        $this->assertSame(1, $estados['otro']['total']);
        $this->assertContains('Congelado', ContractStatusMap::estadosSinClasificar($this->branch->id));
    }

    // ==================== Aislamiento ====================

    public function test_no_mezcla_los_datos_de_otra_sucursal(): void
    {
        $this->contrato(['status' => 'Activo']);
        $this->contrato(['status' => 'Activo', 'branch_id' => $this->otraBranch->id]);

        $resumen = (new GrowthReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(1, $resumen['vigentes']);
    }

    /**
     * Cada sucursal se informa por separado: la sucursal sale de la
     * sesión y no se puede forzar otra desde la URL.
     */
    public function test_no_se_puede_cambiar_la_sucursal_desde_la_peticion(): void
    {
        $this->contrato(['status' => 'Activo']);
        $this->contrato(['status' => 'Activo', 'branch_id' => $this->otraBranch->id]);

        $respuesta = $this->get(route('reports.growth', [
            'sucursal' => 'todas',
            'branchId' => $this->otraBranch->id,
        ]));

        $respuesta->assertOk()
            ->assertSee($this->branch->name)
            ->assertDontSee($this->otraBranch->name);
    }

    // ==================== Facturación ====================

    private function factura(array $atributos = []): Invoice
    {
        $contrato = $atributos['contract'] ?? $this->contrato();
        unset($atributos['contract']);

        return Invoice::create(array_merge([
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'issue_date' => '2026-03-05',
            'due_date' => '2026-03-20',
            'subtotal' => 100000,
            'total' => 100000,
            'pending_invoice_amount' => 100000,
            'status' => InvoiceStatus::Pendiente->value,
        ], $atributos));
    }

    public function test_lo_facturado_excluye_anuladas_y_borradores(): void
    {
        $this->factura(['total' => 100000]);
        $this->factura(['total' => 50000, 'status' => InvoiceStatus::Anulada->value]);
        $this->factura(['total' => 70000, 'status' => InvoiceStatus::Borrador->value]);

        $resumen = (new BillingReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(100000.0, $resumen['facturado']);
    }

    public function test_lo_recaudado_solo_suma_pagos_completados(): void
    {
        $factura = $this->factura();

        Payment::create([
            'invoice_id' => $factura->id,
            'user_id' => $this->admin->id,
            'payment_date' => '2026-03-15',
            'amount' => 40000,
            'payment_method' => 'Efectivo',
            'status' => PaymentStatus::Completed->value,
        ]);

        Payment::create([
            'invoice_id' => $factura->id,
            'user_id' => $this->admin->id,
            'payment_date' => '2026-03-16',
            'amount' => 25000,
            'payment_method' => 'Efectivo',
            'status' => PaymentStatus::Voided->value,
        ]);

        $resumen = (new BillingReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(40000.0, $resumen['recaudado'], 'El pago anulado no debe sumar');
    }

    public function test_un_pago_eliminado_deja_de_contarse(): void
    {
        $factura = $this->factura();

        $pago = Payment::create([
            'invoice_id' => $factura->id,
            'user_id' => $this->admin->id,
            'payment_date' => '2026-03-15',
            'amount' => 40000,
            'payment_method' => 'Efectivo',
            'status' => PaymentStatus::Completed->value,
        ]);

        $pago->delete();

        $resumen = (new BillingReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(0.0, $resumen['recaudado']);
    }

    public function test_clasifica_la_cartera_por_antiguedad(): void
    {
        // Vencida hace mucho
        $this->factura(['due_date' => now()->subDays(120)->format('Y-m-d'), 'total' => 90000, 'pending_invoice_amount' => 90000]);
        // Aún no vence
        $this->factura(['due_date' => now()->addDays(10)->format('Y-m-d'), 'total' => 30000, 'pending_invoice_amount' => 30000]);

        $cartera = (new BillingReport($this->periodo(), $this->branch->id))
            ->carteraPorAntiguedad()
            ->keyBy('etiqueta');

        $this->assertSame(90000.0, $cartera['Más de 90 días']['total']);
        $this->assertSame(30000.0, $cartera['Por vencer']['total']);
    }

    public function test_las_facturas_sin_saldo_no_aparecen_en_cartera(): void
    {
        $this->factura(['pending_invoice_amount' => 0, 'status' => InvoiceStatus::Pagada->value]);

        $resumen = (new BillingReport($this->periodo(), $this->branch->id))->resumen();

        $this->assertSame(0.0, $resumen['cartera']);
    }

    // ==================== Órdenes técnicas ====================

    private function orden(array $atributos = []): TechnicalOrder
    {
        return TechnicalOrder::create(array_merge([
            'contract_id' => $this->contrato()->id,
            'branch_id' => $this->branch->id,
            'user_assigned' => $this->admin->id,
            'type' => 'Servicio',
            'status' => 'Cerrada',
            'detail' => 'Instalación',
            'initial_comment' => 'Orden de prueba',
            'created_by' => $this->admin->id,
        ], $atributos));
    }

    public function test_mide_el_rendimiento_por_tecnico(): void
    {
        $this->orden(['status' => 'Cerrada']);
        $this->orden(['status' => 'Cerrada']);
        $this->orden(['status' => 'Pendiente']);

        $tecnicos = (new TechnicalOrdersReport(
            ReportPeriod::fromRequest(now()->subMonth()->format('Y-m-d'), now()->format('Y-m-d'), 'month'),
            $this->branch->id
        ))->porTecnico();

        $this->assertCount(1, $tecnicos);
        $this->assertSame(3, $tecnicos[0]['asignadas']);
        $this->assertSame(2, $tecnicos[0]['cerradas']);
        $this->assertEqualsWithDelta(66.7, $tecnicos[0]['efectividad'], 0.1);
    }

    public function test_cuenta_las_ordenes_sin_asignar(): void
    {
        $this->orden(['user_assigned' => null, 'status' => 'Pendiente']);

        $resumen = (new TechnicalOrdersReport(
            ReportPeriod::fromRequest(now()->subMonth()->format('Y-m-d'), now()->format('Y-m-d'), 'month'),
            $this->branch->id
        ))->resumen();

        $this->assertSame(1, $resumen['sin_asignar']);
    }

    // ============ Clasificación por detalle ============

    /**
     * El mismo trabajo se guarda con variantes: sin tilde desde el
     * formulario y con el sufijo "(creación automática)" cuando lo
     * crea el sistema. Deben contarse como uno solo.
     */
    public function test_unifica_las_variantes_del_mismo_detalle(): void
    {
        $this->orden(['detail' => 'Instalacion de servicio']);
        $this->orden(['detail' => 'Instalación de servicio']);
        $this->orden(['detail' => 'Instalación de servicio (creación automática)']);

        $detalles = (new TechnicalOrdersReport($this->periodoActual(), $this->branch->id))
            ->porDetalle()
            ->keyBy('etiqueta');

        $this->assertCount(1, $detalles);
        $this->assertSame(3, $detalles['Instalación de servicio']['total']);
    }

    /**
     * "Sin servicio de TV" contiene "Sin servicio": debe ganar la
     * coincidencia más específica, no la más corta.
     */
    public function test_distingue_los_detalles_que_se_contienen(): void
    {
        $this->orden(['detail' => 'Sin servicio', 'type' => 'Incidencia']);
        $this->orden(['detail' => 'Sin servicio de TV', 'type' => 'Incidencia']);
        $this->orden(['detail' => 'Sin servicio de internet', 'type' => 'Incidencia']);

        $detalles = (new TechnicalOrdersReport($this->periodoActual(), $this->branch->id))
            ->porDetalle()
            ->keyBy('etiqueta');

        $this->assertSame(1, $detalles['Sin servicio']['total']);
        $this->assertSame(1, $detalles['Sin servicio de TV']['total']);
        $this->assertSame(1, $detalles['Sin servicio de internet']['total']);
    }

    public function test_asocia_cada_detalle_con_su_tipo(): void
    {
        $this->orden(['detail' => 'Reconexión']);
        $this->orden(['detail' => 'Configuraciones', 'type' => 'Incidencia']);

        $detalles = (new TechnicalOrdersReport($this->periodoActual(), $this->branch->id))
            ->porDetalle()
            ->keyBy('etiqueta');

        $this->assertSame('Servicio', $detalles['Reconexión']['tipo']);
        $this->assertSame('Incidencia', $detalles['Configuraciones']['tipo']);
    }

    public function test_un_detalle_desconocido_no_desaparece(): void
    {
        $this->orden(['detail' => 'Algo que nadie previó']);

        $detalles = (new TechnicalOrdersReport($this->periodoActual(), $this->branch->id))
            ->porDetalle()
            ->keyBy('etiqueta');

        $this->assertSame(1, $detalles['Sin clasificar']['total']);
    }

    public function test_cruza_el_detalle_con_su_estado(): void
    {
        $this->orden(['detail' => 'Reconexión', 'status' => 'Cerrada']);
        $this->orden(['detail' => 'Reconexión', 'status' => 'Pendiente']);
        $this->orden(['detail' => 'Reconexión', 'status' => 'Rechazada']);

        $fila = (new TechnicalOrdersReport($this->periodoActual(), $this->branch->id))
            ->detallePorEstado()
            ->firstWhere('etiqueta', 'Reconexión');

        $this->assertSame(3, $fila['total']);
        $this->assertSame(1, $fila['cerradas']);
        $this->assertSame(1, $fila['rechazadas']);
        $this->assertSame(1, $fila['abiertas']);
    }

    // ================ Aprovisionamiento ================

    private function ont(array $atributos = []): Ont
    {
        return Ont::create(array_merge([
            'branch_id' => $this->branch->id,
            'olt_id' => $this->olt()->id,
            'contract_id' => null,
            'slot' => 1, 'port' => 1,
            'onu_id' => random_int(1, 120),
            'sn' => 'HWTC-' . strtoupper(bin2hex(random_bytes(4))),
            'status' => 1,
            'admin_enabled' => true,
        ], $atributos));
    }

    private function olt(): Olt
    {
        return Olt::firstOrCreate(
            ['ip_address' => '10.0.0.1'],
            [
                'branch_id' => $this->branch->id, 'name' => 'OLT pruebas',
                'ssh_port' => 22, 'telnet_port' => 23, 'snmp_port' => 161,
                'read_snmp_comunity' => 'public', 'username' => 'a',
                'password' => 'b', 'brand' => 'huawei', 'uptime' => '0',
            ]
        );
    }

    public function test_cuenta_los_equipos_de_la_red(): void
    {
        $contrato = $this->contrato();

        $this->ont(['contract_id' => $contrato->id]);
        $this->ont();
        $this->ont(['admin_enabled' => false]);

        $resumen = (new ProvisioningReport($this->periodoActual(), $this->branch->id))->resumen();

        $this->assertSame(3, $resumen['onts']);
        $this->assertSame(2, $resumen['onts_sin_contrato']);
        $this->assertSame(1, $resumen['onts_deshabilitadas']);
    }

    /**
     * Lo que ningún otro informe ve: contratos que se facturan sin
     * ningún equipo registrado.
     */
    public function test_detecta_contratos_sin_equipo(): void
    {
        $conOnt = $this->contrato(['status' => 'Activo']);
        $this->ont(['contract_id' => $conOnt->id]);

        $this->contrato(['status' => 'Activo']);
        $this->contrato(['status' => 'Activo']);

        $cobertura = (new ProvisioningReport($this->periodoActual(), $this->branch->id))->cobertura();

        $this->assertSame(3, $cobertura['vigentes']);
        $this->assertSame(1, $cobertura['con_ont']);
        $this->assertSame(2, $cobertura['sin_ont']);
        $this->assertSame(2, $cobertura['sin_nada']);
    }

    public function test_los_retirados_no_cuentan_en_la_cobertura(): void
    {
        $this->contrato(['status' => 'Activo']);
        $this->contrato(['status' => 'Retirado']);

        $cobertura = (new ProvisioningReport($this->periodoActual(), $this->branch->id))->cobertura();

        $this->assertSame(1, $cobertura['vigentes']);
    }

    public function test_clasifica_la_potencia_optica(): void
    {
        $this->ont(['rx_power' => -18.5]);   // Excelente
        $this->ont(['rx_power' => -23.0]);   // Buena
        $this->ont(['rx_power' => -29.0]);   // Crítica
        $this->ont(['rx_power' => null]);    // Sin lectura

        $optica = (new ProvisioningReport($this->periodoActual(), $this->branch->id))
            ->calidadOptica()
            ->keyBy('etiqueta');

        $this->assertSame(1, $optica['Excelente (≥ -22 dBm)']['total']);
        $this->assertSame(1, $optica['Buena (-22 a -25)']['total']);
        $this->assertSame(1, $optica['Crítica (< -27 dBm)']['total']);
        $this->assertSame(1, $optica['Sin lectura SNMP']['total']);
    }

    public function test_lista_las_onts_sin_contrato(): void
    {
        $contrato = $this->contrato();
        $this->ont(['contract_id' => $contrato->id, 'sn' => 'HWTC-ASIGNADA']);
        $this->ont(['sn' => 'HWTC-HUERFANA']);

        $huerfanas = (new ProvisioningReport($this->periodoActual(), $this->branch->id))->ontsHuerfanas();

        $this->assertCount(1, $huerfanas);
        $this->assertSame('HWTC-HUERFANA', $huerfanas[0]['sn']);
    }

    public function test_el_aprovisionamiento_no_ve_otra_sucursal(): void
    {
        $this->ont();
        $this->ont(['branch_id' => $this->otraBranch->id]);

        $resumen = (new ProvisioningReport($this->periodoActual(), $this->branch->id))->resumen();

        $this->assertSame(1, $resumen['onts']);
    }

    private function periodoActual(): ReportPeriod
    {
        return ReportPeriod::fromRequest(
            now()->subMonth()->format('Y-m-d'),
            now()->format('Y-m-d'),
            'month'
        );
    }

    // ==================== Pantallas ====================

    public function test_las_cuatro_pantallas_cargan(): void
    {
        $this->contrato();

        foreach (['reports.index', 'reports.growth', 'reports.technical', 'reports.billing', 'reports.provisioning'] as $ruta) {
            $this->get(route($ruta))->assertOk();
        }
    }

    public function test_las_pantallas_avisan_de_estados_sin_clasificar(): void
    {
        $this->contrato(['status' => 'Congelado']);

        $this->get(route('reports.growth'))
            ->assertOk()
            ->assertSee('Calidad de datos')
            ->assertSee('Congelado');
    }

    public function test_sin_permiso_no_se_accede(): void
    {
        $sinPermiso = User::factory()->create(['number_phone' => '3022222222']);
        $rol = Role::where('name', 'tecnico')->firstOrFail();
        $sinPermiso->assignRole($rol);
        $sinPermiso->branches()->attach($this->branch->id, ['role_id' => $rol->id]);

        $this->actingAs($sinPermiso)->withSession([
            'branch_id' => (string) $this->branch->id,
            'current_role_id' => (string) $rol->id,
        ]);

        $this->get(route('reports.billing'))->assertForbidden();
    }

    // ==================== PDF ====================

    public function test_los_cuatro_pdf_se_generan(): void
    {
        $this->contrato();
        $this->factura();
        $this->orden();

        $rutas = [
            'reports.summary.pdf',
            'reports.growth.pdf',
            'reports.technical.pdf',
            'reports.billing.pdf',
            'reports.provisioning.pdf',
        ];

        foreach ($rutas as $ruta) {
            $respuesta = $this->get(route($ruta));

            $respuesta->assertOk();
            $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));

            // Se comprueba la firma del archivo: un PDF válido
            // siempre empieza por "%PDF"
            $this->assertStringStartsWith('%PDF', $respuesta->getContent(), "Ruta {$ruta}");
            $this->assertGreaterThan(1000, strlen($respuesta->getContent()), "El PDF de {$ruta} salió vacío");
        }
    }

    public function test_el_pdf_respeta_los_filtros_de_la_pantalla(): void
    {
        $respuesta = $this->get(route('reports.growth.pdf', [
            'desde' => '2026-01-01',
            'hasta' => '2026-06-30',
            'granularidad' => 'week',
        ]));

        $respuesta->assertOk()
            ->assertHeader('content-disposition');

        $this->assertStringContainsString('20260101', $respuesta->headers->get('content-disposition'));
    }

    public function test_la_granularidad_invalida_cae_en_mensual(): void
    {
        $this->assertSame(Granularity::Mes, Granularity::fromRequest('trimestral'));
        $this->assertSame(Granularity::Mes, Granularity::fromRequest(null));
    }
}
