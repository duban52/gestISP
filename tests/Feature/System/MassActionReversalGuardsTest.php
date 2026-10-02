<?php

namespace Tests\Feature\System;

use App\Billing\Enums\InvoiceStatus;
use App\MassActions\Enums\MassActionType;
use App\MassActions\MassActionRecorder;
use App\MassActions\MassActionReverter;
use App\Models\CreditDebitNote;
use App\Models\ElectronicDocument;
use App\Models\Invoice;
use App\Models\MassAction;
use App\Models\Payment;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Feature\Billing\ConstruyeFacturasElectronicas;

/**
 * Lo que la reversión tiene que NEGARSE a hacer.
 *
 * Las pruebas de que algo funciona son fáciles. Estas son las otras:
 * las que comprueban que el sistema se planta cuando deshacer haría
 * daño. Cada una corresponde a un hecho que ya ocurrió y que una fila
 * borrada no puede deshacer:
 *
 *   · Un pago está en el cuadre de una caja.
 *   · Una factura validada existe en los registros de la DIAN con su
 *     CUFE.
 *   · Una nota es un documento con su propio consecutivo.
 *
 * Si alguna de estas pruebas se pone en verde «arreglando» el código
 * para que borre igual, lo que se rompe no es una prueba: es la
 * contabilidad de alguien.
 */
class MassActionReversalGuardsTest extends BillingTestCase
{
    use ConstruyeFacturasElectronicas;

    /** Una corrida de facturación registrada, con su factura. */
    private function corridaCon(Invoice $factura): MassAction
    {
        $recorder = app(MassActionRecorder::class);

        $accion = $recorder->abrir(
            MassActionType::CorridaDeFacturacion,
            'Corrida de prueba',
            branchId: $this->branch->id,
            userId: $this->admin->id,
        );

        $recorder->registrar($accion, $factura, $factura->displayNumber());
        $recorder->cerrar($accion);

        return $accion->refresh();
    }

    public function test_no_anula_una_factura_con_pagos(): void
    {
        $factura = $this->emitir($this->createBillableContract());
        $accion = $this->corridaCon($factura);

        Payment::create([
            'invoice_id' => $factura->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'amount' => 1000,
            'payment_method' => 'Efectivo',
            'status' => \App\Billing\Enums\PaymentStatus::Completed->value,
            'payment_date' => now(),
        ]);

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame(0, $reversion->reverted_items);
        $this->assertSame(1, $reversion->conflict_items);
        $this->assertNotSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);

        $this->assertStringContainsString(
            'pagos',
            $accion->items()->firstOrFail()->fresh()->conflict_reason,
        );
    }

    public function test_no_anula_una_factura_que_la_dian_ya_valido(): void
    {
        // Esa se corrige con una nota crédito, que sí se transmite y
        // queda en los dos lados. Anularla solo aquí dejaría nuestra
        // contabilidad diciendo una cosa y la de la DIAN otra.
        $factura = $this->emitir($this->createBillableContract());
        $accion = $this->corridaCon($factura);

        ElectronicDocument::withoutGlobalScopes()->create([
            'company_id' => $this->branch->company_id,
            'invoice_id' => $factura->id,
            'environment_code' => '2',
            'cufe' => str_repeat('a', 96),
            'status' => ElectronicDocument::ACEPTADO,
            'accepted_at' => now(),
        ]);

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame(0, $reversion->reverted_items);
        $this->assertNotSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);

        $this->assertStringContainsString(
            'DIAN',
            $accion->items()->firstOrFail()->fresh()->conflict_reason,
        );
    }

    public function test_una_factura_limpia_si_se_anula(): void
    {
        // El contrapunto: sin pagos y sin DIAN, la reversión hace su
        // trabajo. Si solo se probara lo que se niega, un sistema que
        // no revierte nada pasaría todas las pruebas.
        $factura = $this->emitir($this->createBillableContract());
        $accion = $this->corridaCon($factura);

        $reversion = app(MassActionReverter::class)->revertir($accion);

        $this->assertSame(1, $reversion->reverted_items);
        $this->assertSame(InvoiceStatus::Anulada->value, $factura->fresh()->status);
    }

    public function test_no_borra_un_contrato_importado_con_pagos(): void
    {
        $contrato = $this->createBillableContract();
        $factura = $this->emitir($contrato);

        Payment::create([
            'invoice_id' => $factura->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'amount' => 1000,
            'payment_method' => 'Efectivo',
            'status' => \App\Billing\Enums\PaymentStatus::Completed->value,
            'payment_date' => now(),
        ]);

        $recorder = app(MassActionRecorder::class);
        $accion = $recorder->abrir(
            MassActionType::ImportacionDeClientes,
            'Importación de prueba',
            branchId: $this->branch->id,
            userId: $this->admin->id,
        );
        $recorder->registrar($accion, $contrato, $contrato->numero_visible, antes: ['cliente_creado' => true]);
        $recorder->cerrar($accion);

        $reversion = app(MassActionReverter::class)->revertir($accion->refresh());

        $this->assertSame(0, $reversion->reverted_items);
        $this->assertSame(1, $reversion->conflict_items);
        // Y, sobre todo: el contrato sigue ahí.
        $this->assertNotNull($contrato->fresh());
    }

    public function test_no_borra_un_contrato_importado_con_notas(): void
    {
        $contrato = $this->createBillableContract();
        $factura = $this->emitir($contrato);

        CreditDebitNote::create([
            'invoice_id' => $factura->id,
            'contract_id' => $contrato->id,
            'branch_id' => $this->branch->id,
            'user_id' => $this->admin->id,
            'type' => \App\Billing\Enums\NoteType::Credito->value,
            'prefix' => 'NC',
            'number' => 1,
            'full_number' => 'NC-1',
            'document_kind' => 'interno',
            'concept_label' => 'Prueba',
            'concept_code' => '3',
            'reason' => 'Prueba',
            'subtotal' => 1000,
            'tax' => 0,
            'total' => 1000,
            'status' => CreditDebitNote::EMITIDA,
            'issue_date' => now(),
        ]);

        $recorder = app(MassActionRecorder::class);
        $accion = $recorder->abrir(
            MassActionType::ImportacionDeClientes,
            'Importación de prueba',
            branchId: $this->branch->id,
            userId: $this->admin->id,
        );
        $recorder->registrar($accion, $contrato, $contrato->numero_visible);
        $recorder->cerrar($accion);

        app(MassActionReverter::class)->revertir($accion->refresh());

        $this->assertNotNull($contrato->fresh());
        $this->assertStringContainsString(
            'notas',
            $accion->items()->firstOrFail()->fresh()->conflict_reason,
        );
    }

    public function test_un_contrato_importado_sin_nada_si_se_borra(): void
    {
        // El otro contrapunto: lo que se importó y no tuvo vida, se
        // borra. Es lo que el usuario pidió, y sin esto la reversión
        // de una importación equivocada no serviría de nada.
        $contrato = $this->createBillableContract();
        $contratoId = $contrato->id;

        $recorder = app(MassActionRecorder::class);
        $accion = $recorder->abrir(
            MassActionType::ImportacionDeClientes,
            'Importación de prueba',
            branchId: $this->branch->id,
            userId: $this->admin->id,
        );
        $recorder->registrar($accion, $contrato, $contrato->numero_visible, antes: ['cliente_creado' => false]);
        $recorder->cerrar($accion);

        $reversion = app(MassActionReverter::class)->revertir($accion->refresh());

        $this->assertSame(1, $reversion->reverted_items);
        $this->assertNull(\App\Models\Contract::withoutGlobalScopes()->find($contratoId));
    }
}
