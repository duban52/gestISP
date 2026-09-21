<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cesión de contratos: cambio de titular sin cortar el servicio.
 *
 * POR QUÉ UN CONTRATO NUEVO Y NO CAMBIAR EL CLIENTE DEL VIEJO
 * -----------------------------------------------------------
 * Las facturas no guardan los datos del cliente: el XML de la DIAN, el
 * AttachedDocument, las notas crédito y los avisos de mora los leen EN
 * VIVO del contrato. Si la cesión cambiara `contracts.client_id`:
 *
 *   · una factura regenerada —en contingencia, por ejemplo— declararía
 *     como adquiriente a alguien que no la compró;
 *   · los avisos de mora del cedente le llegarían al cesionario;
 *   · una nota crédito sobre una factura vieja saldría a nombre del
 *     nuevo titular.
 *
 * Así que la cesión CIERRA el contrato del cedente —queda «Cedido»,
 * con su historial, sus facturas y sus deudas a nombre de quien las
 * contrajo— y ABRE uno nuevo para el cesionario, al que se le pasan el
 * servicio y los equipos sin tocar la red.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- El registro de cada cesión ----
        //
        // Es un acto con consecuencias legales: quién cedió, a quién,
        // cuándo, quién lo autorizó y por qué. Los dos clientes se
        // guardan aquí aunque se puedan deducir de los contratos,
        // porque este registro no puede depender de que nadie edite
        // después esos contratos.
        Schema::create('contract_cessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_contract_id')->constrained('contracts');
            $table->foreignId('to_contract_id')->constrained('contracts');
            $table->foreignId('from_client_id')->constrained('clients');
            $table->foreignId('to_client_id')->constrained('clients');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            // Lo que pasó: facturas que se le emitieron al cedente,
            // equipos y puerto que cambiaron de contrato, saldo a favor
            // que le quedó. Es lo que se le enseña a quien cedió.
            $table->json('summary')->nullable();
            $table->timestamp('ceded_at');
            $table->timestamps();

            // Un contrato solo se cede una vez: después está cerrado.
            $table->unique('from_contract_id');
        });

        // ---- Desde cuándo se le factura a un contrato ----
        //
        // El mes de la cesión lo paga el cedente. Sin esta fecha, la
        // corrida del mes le facturaría al contrato nuevo el mismo mes
        // que ya se le cobró al viejo: doble cobro por el mismo
        // servicio en la misma casa.
        Schema::table('contracts', function (Blueprint $table) {
            $table->date('billing_start_date')->nullable()->after('activation_date');
        });

        // ---- El estado ----
        //
        // Final —el contrato terminó para ese titular—, sin servicio y
        // sin facturación. INACTIVO a propósito: no se ofrece al cambiar
        // el estado a mano, porque llegar aquí sin pasar por la cesión
        // dispararía la baja definitiva —liberar el puerto, borrar la
        // ONT— sobre un servicio que sigue funcionando para otro.
        DB::table('contract_statuses')->insert([
            'name' => 'Cedido',
            'description' => 'Pasó a otro titular por cesión. Sus facturas y deudas siguen a nombre del cedente.',
            'bills' => false,
            'auto_bills' => false,
            'has_service' => false,
            'is_final' => true,
            'color' => 'secondary',
            'active' => false,
            'is_system' => true,
            'sort_order' => 8,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('contract_statuses')->where('name', 'Cedido')->delete();

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('billing_start_date');
        });

        Schema::dropIfExists('contract_cessions');
    }
};
