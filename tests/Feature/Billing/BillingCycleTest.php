<?php

namespace Tests\Feature\Billing;

use App\Billing\Enums\BillingCycle;
use App\Billing\Enums\ProrationMode;
use App\Billing\Services\InvoiceGenerator;
use App\Models\BranchBillingSetting;
use App\Models\Contract;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * QUÉ MES SE COBRA, Y QUÉ PASA CON LOS DÍAS QUE NADIE COBRÓ.
 *
 * DE DÓNDE SALE ESTA PRUEBA
 * -------------------------
 * De dos preguntas del usuario que resultaron ser dos defectos:
 *
 * 1. «Si se factura el 25 y el cliente entra el 26, ¿el prorrateo de
 *    esos 4 días se suma a la factura del mes siguiente?»
 *    NO se sumaba. La corrida de octubre cobraba octubre entero y del
 *    26 al 30 de septiembre no lo cobraba nadie, nunca. Días de
 *    servicio prestado y regalado sin que nadie lo decidiera.
 *
 * 2. «¿Esto factura mes actual, vencido o adelantado?»
 *    El mes en curso, siempre, porque estaba escrito en el código. Un
 *    ISP que cobra por adelantado —lo normal— no tenía cómo decirlo.
 *
 * LO QUE SE DEFIENDE
 * ------------------
 * · Que el ciclo mueva el PERÍODO y no la emisión.
 * · Que el arrastre cobre los días sueltos UNA vez, en la primera
 *   factura, y nunca más.
 * · Que con mes completo no haya arrastre: ahí regalar esos días es
 *   la decisión, no el olvido.
 * · Que cambiar el ciclo no rompa el candado que impide facturar dos
 *   veces el mismo período.
 */
class BillingCycleTest extends BillingTestCase
{
    private function configurar(BillingCycle $ciclo, ProrationMode $prorrateo = ProrationMode::Prorated): void
    {
        BranchBillingSetting::forBranch($this->branch->id)->update([
            'billing_cycle' => $ciclo,
            'proration_mode' => $prorrateo,
        ]);
    }

    private function facturar(Contract $contrato, Carbon $cuando): ?Invoice
    {
        app(InvoiceGenerator::class)->generateForContract($contrato, $cuando, $this->admin->id);

        return Invoice::where('contract_id', $contrato->id)->latest('id')->first();
    }

    // ==================== Qué mes se cobra ====================

    public function test_en_curso_cobra_el_mes_de_la_corrida(): void
    {
        // El comportamiento histórico, y el que sigue viniendo de
        // fábrica: nadie se encuentra un cambio sin pedirlo.
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-09-25'));

        $this->assertSame('202609', $factura->billed_year_month);
        $this->assertSame('2026-09-01', $factura->period_start->toDateString());
        $this->assertSame('2026-09-30', $factura->period_end->toDateString());
    }

    public function test_anticipado_cobra_el_mes_siguiente(): void
    {
        $this->configurar(BillingCycle::Anticipado);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-09-25'));

        $this->assertSame('202610', $factura->billed_year_month);
        $this->assertSame('2026-10-01', $factura->period_start->toDateString());
        $this->assertSame('2026-10-31', $factura->period_end->toDateString());
        $this->assertSame('Octubre', $factura->billed_month_name);
    }

    public function test_vencido_cobra_el_mes_anterior(): void
    {
        $this->configurar(BillingCycle::Vencido);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-09-25'));

        $this->assertSame('202608', $factura->billed_year_month);
        $this->assertSame('2026-08-01', $factura->period_start->toDateString());
        $this->assertSame('2026-08-31', $factura->period_end->toDateString());
    }

    public function test_el_ciclo_mueve_el_periodo_pero_no_la_emision(): void
    {
        // La factura se emite HOY y se paga en los días de plazo de la
        // sucursal, cobre el mes que cobre.
        $this->configurar(BillingCycle::Anticipado);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-09-25'));

        $this->assertSame('2026-09-25', $factura->issue_date->toDateString());
        $this->assertSame(
            Carbon::parse('2026-09-25')->addDays(BranchBillingSetting::forBranch($this->branch->id)->due_days)->toDateString(),
            $factura->due_date->toDateString(),
        );
    }

    public function test_el_candado_de_no_facturar_dos_veces_sigue_puesto(): void
    {
        $this->configurar(BillingCycle::Anticipado);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');

        $this->facturar($contrato, Carbon::parse('2026-09-25'));
        // Otra corrida el mismo mes: el período ya está cobrado.
        $this->facturar($contrato, Carbon::parse('2026-09-28'));

        $this->assertSame(1, Invoice::where('contract_id', $contrato->id)->count());
    }

    // ============ Los días que nadie cobró (el arrastre) ============

    /**
     * EL CASO DEL USUARIO: se factura el 25, el cliente entra el 26.
     *
     * En septiembre no hay factura —el contrato todavía no existía
     * cuando corrió—, así que la de octubre tiene que cubrir del 26 de
     * septiembre al 31 de octubre: 5 + 31 = 36 días.
     */
    public function test_la_primera_factura_arrastra_los_dias_sin_cobrar(): void
    {
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-26');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-09-26', $factura->period_start->toDateString());
        $this->assertSame('2026-10-31', $factura->period_end->toDateString());

        // 36 días al precio diario de octubre (31 días).
        $this->assertEqualsWithDelta(100000 * 36 / 31, (float) $factura->total, 0.01);
    }

    public function test_los_dias_arrastrados_se_cobran_una_sola_vez(): void
    {
        // Es lo que separa «cobrar lo que se debe» de «cobrar dos
        // veces»: a partir de la segunda factura, lo anterior ya está
        // cobrado y la activación deja de contar.
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-26');

        $this->facturar($contrato, Carbon::parse('2026-10-25'));
        $segunda = $this->facturar($contrato, Carbon::parse('2026-11-25'));

        $this->assertSame('2026-11-01', $segunda->period_start->toDateString());
        $this->assertEqualsWithDelta(100000, (float) $segunda->total, 0.01);
    }

    public function test_con_mes_completo_esos_dias_se_regalan(): void
    {
        // Es una decisión comercial, no un olvido: quien elige «mes
        // completo» está diciendo que cobra meses enteros.
        $this->configurar(BillingCycle::EnCurso, ProrationMode::FullMonth);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-26');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-10-01', $factura->period_start->toDateString());
        $this->assertEqualsWithDelta(100000, (float) $factura->total, 0.01);
    }

    public function test_activado_dentro_del_periodo_se_prorratea_como_siempre(): void
    {
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-11');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-10-11', $factura->period_start->toDateString());
        // Del 11 al 31 son 21 días.
        $this->assertEqualsWithDelta(100000 * 21 / 31, (float) $factura->total, 0.01);
    }

    /**
     * UN CONTRATO OLVIDADO NO SE COBRA DE GOLPE.
     *
     * ES LA PRUEBA QUE MÁS IMPORTA DE ESTE ARCHIVO. Ocho meses activo
     * sin una sola factura no es un cliente que deba ocho meses: es un
     * dato torcido —una migración a medias, una sucursal que empieza a
     * usar el sistema con su cartera ya andando—. Sin este límite, la
     * primera corrida después de actualizar le habría cobrado un mes
     * de más a todos esos contratos a la vez, y esa factura ya está
     * emitida cuando alguien se da cuenta.
     *
     * Se arrastra solo lo que la corrida anterior habría cobrado. Lo
     * de más atrás se resuelve a mano y mirándolo.
     */
    public function test_una_activacion_vieja_no_arrastra_nada(): void
    {
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-02-10');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-10-01', $factura->period_start->toDateString());
        $this->assertEqualsWithDelta(100000, (float) $factura->total, 0.01);
    }

    public function test_se_arrastra_desde_el_primer_dia_del_mes_anterior(): void
    {
        // El borde exacto del arrastre: justo un mes antes sí entra.
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-09-01', $factura->period_start->toDateString());
        $this->assertEqualsWithDelta(100000 * 61 / 31, (float) $factura->total, 0.01);
    }

    public function test_el_periodo_que_cruza_dos_meses_se_lee_entero(): void
    {
        // «DEL 26 al 31 DEL MES DE Octubre» era una fecha que no
        // existe y un período que no es.
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(activationDate: '2026-09-26');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('DEL 26 DE Septiembre AL 31 DE Octubre', $factura->periodoLegible());
    }

    public function test_un_periodo_normal_se_sigue_leyendo_corto(): void
    {
        $this->configurar(BillingCycle::EnCurso);

        $contrato = $this->createBillableContract(activationDate: '2026-01-01');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('DEL 01 AL 31 DEL MES DE Octubre', $factura->periodoLegible());
    }

    // ==================== La corrida y el formulario ====================

    public function test_la_corrida_se_rotula_con_el_periodo_que_cobro(): void
    {
        // Es la clave con la que el informe la busca: una corrida de
        // septiembre que cobró octubre tiene que decir octubre.
        $this->configurar(BillingCycle::Anticipado);
        $this->createBillableContract(activationDate: '2026-01-01');

        Carbon::setTestNow('2026-09-25');
        app(\App\Billing\Services\MonthlyBillingRun::class)->runForBranch($this->branch->id, $this->admin->id);
        Carbon::setTestNow();

        $this->assertSame('202610', \App\Models\BillingRun::latest('id')->firstOrFail()->billed_year_month);
    }

    public function test_el_ciclo_se_puede_cambiar_desde_la_sucursal(): void
    {
        $this->get(route('branches.edit', $this->branch))
            ->assertOk()
            ->assertSee('Qué mes se cobra')
            ->assertSee('Anticipado (el mes siguiente)');
    }
}
