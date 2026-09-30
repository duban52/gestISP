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
    private function configurar(
        BillingCycle $ciclo,
        ProrationMode $prorrateo = ProrationMode::Prorated,
        int $diaLimite = 31,
    ): void {
        BranchBillingSetting::forBranch($this->branch->id)->update([
            'billing_cycle' => $ciclo,
            'proration_mode' => $prorrateo,
            'proration_day' => $diaLimite,
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

    // ============ Dias de cortesia (el dia limite) ============

    /**
     * EL CASO QUE LO PIDIO. «Si entra antes del 16 le cobro los dias;
     * despues, se los regalo.»
     */
    public function test_antes_del_dia_limite_se_prorratea_como_siempre(): void
    {
        $this->configurar(BillingCycle::EnCurso, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-10');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-10-10', $factura->period_start->toDateString());
        // Del 10 al 31 son 22 dias.
        $this->assertEqualsWithDelta(100000 * 22 / 31, (float) $factura->total, 0.01);
    }

    public function test_el_dia_limite_todavia_se_cobra(): void
    {
        // El borde exacto: el 16 paga, el 17 ya no.
        $this->configurar(BillingCycle::EnCurso, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-16');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertNotNull($factura, 'El dia limite todavia se prorratea.');
        $this->assertSame('2026-10-16', $factura->period_start->toDateString());
    }

    public function test_despues_del_dia_limite_no_se_factura_ese_mes(): void
    {
        // Regalar NO es facturar cero: es no emitir. Un documento de
        // cero renglones en electronica gasta un consecutivo
        // autorizado que no se recupera.
        $this->configurar(BillingCycle::EnCurso, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-20');

        $this->assertNull($this->facturar($contrato, Carbon::parse('2026-10-25')));
        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
    }

    /**
     * LO QUE MAS IMPORTA DE LA CORTESIA: que no se cobre despues.
     *
     * El arrastre existe para cobrar los dias que nadie cobro. Si
     * mirara la activacion a secas, el mes que acaba de regalarse
     * reaparecerIa en la factura siguiente y el regalo seria un
     * aplazamiento.
     */
    public function test_los_dias_regalados_no_se_arrastran_al_mes_siguiente(): void
    {
        $this->configurar(BillingCycle::EnCurso, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-20');

        $this->facturar($contrato, Carbon::parse('2026-10-25'));
        $factura = $this->facturar($contrato, Carbon::parse('2026-11-25'));

        $this->assertSame('2026-11-01', $factura->period_start->toDateString());
        $this->assertEqualsWithDelta(100000, (float) $factura->total, 0.01);
    }

    public function test_con_mes_completo_el_dia_limite_no_pinta_nada(): void
    {
        // Quien elige «mes completo» cobra meses enteros: no hay
        // fraccion que regalar ni que cobrar.
        $this->configurar(BillingCycle::EnCurso, ProrationMode::FullMonth, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-10-20');
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertNotNull($factura);
        $this->assertEqualsWithDelta(100000, (float) $factura->total, 0.01);
    }

    public function test_en_febrero_el_limite_es_el_mismo_dia(): void
    {
        $this->configurar(BillingCycle::EnCurso, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-02-14');
        $factura = $this->facturar($contrato, Carbon::parse('2026-02-25'));

        // Del 14 al 28 son 15 dias de 28.
        $this->assertEqualsWithDelta(100000 * 15 / 28, (float) $factura->total, 0.01);
    }

    /**
     * Cobrando por adelantado, el que entra pasado el limite tampoco
     * entra en la corrida de su propio mes: empieza en la siguiente.
     * Decision del 2026-09-30.
     */
    public function test_anticipado_tampoco_lo_factura_en_su_propia_corrida(): void
    {
        $this->configurar(BillingCycle::Anticipado, diaLimite: 16);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-20');

        $this->assertNull($this->facturar($contrato, Carbon::parse('2026-09-25')));

        // En la corrida siguiente si: cobra noviembre y arrastra
        // octubre, que no era de cortesia y nadie habia cobrado.
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-10-01', $factura->period_start->toDateString());
        $this->assertSame('2026-11-30', $factura->period_end->toDateString());
    }

    // ============ Un periodo en el que no fue cliente ============

    /**
     * EL COBRO INDEBIDO QUE TRAJO EL CICLO VENCIDO.
     *
     * La corrida de septiembre cobra agosto. Un contrato activado el
     * 20 de septiembre no cae ni dentro del periodo ni antes de el:
     * se colaba con el multiplicador en 1 y le cobraba agosto entero
     * a quien no era cliente en agosto.
     */
    public function test_vencido_no_cobra_un_mes_anterior_al_alta(): void
    {
        $this->configurar(BillingCycle::Vencido);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-20');

        $this->assertNull($this->facturar($contrato, Carbon::parse('2026-09-25')));
        $this->assertSame(0, Invoice::where('contract_id', $contrato->id)->count());
    }

    public function test_vencido_empieza_a_cobrar_cuando_el_periodo_ya_fue_suyo(): void
    {
        $this->configurar(BillingCycle::Vencido);

        $contrato = $this->createBillableContract(price: 100000, activationDate: '2026-09-20');

        // La corrida de octubre cobra septiembre, que si fue suyo
        // desde el dia 20.
        $factura = $this->facturar($contrato, Carbon::parse('2026-10-25'));

        $this->assertSame('2026-09-20', $factura->period_start->toDateString());
        $this->assertEqualsWithDelta(100000 * 11 / 30, (float) $factura->total, 0.01);
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
            ->assertSee('Prorratear hasta el día')
            ->assertSee('Anticipado (el mes siguiente)');
    }
}
