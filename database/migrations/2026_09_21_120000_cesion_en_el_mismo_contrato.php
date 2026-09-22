<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La cesión pasa a hacerse EN EL MISMO CONTRATO: cambia el titular y
 * el contrato conserva su número, su id, su estado y sus equipos.
 *
 * Lo que eso exigía resolver primero: las facturas leían al cliente EN
 * VIVO del contrato (por eso la versión anterior abría un contrato
 * nuevo). Con el titular cambiando dentro del mismo contrato, una
 * factura vieja regenerada, una nota crédito sobre ella o un recordatorio
 * saldrían a nombre de quien no la compró.
 *
 * Así que cada factura guarda ahora a quién se le emitió
 * (`invoices.client_id`), igual que ya guardaba su grupo y su tipo de
 * documento. Las que ya existen se rellenan con el titular actual de su
 * contrato, que es quien las compró: hasta hoy ningún contrato había
 * cambiado de titular sin cambiar de contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('contract_id')->constrained('clients');
        });

        DB::statement('UPDATE invoices SET client_id = (SELECT contracts.client_id FROM contracts WHERE contracts.id = invoices.contract_id)');

        // Un contrato puede cambiar de titular más de una vez. El índice
        // normal va ANTES de quitar el único: MySQL no deja la llave
        // foránea sin un índice que la respalde ni un instante.
        Schema::table('contract_cessions', function (Blueprint $table) {
            $table->index('from_contract_id', 'contract_cessions_from_contract_idx');
        });

        Schema::table('contract_cessions', function (Blueprint $table) {
            $table->dropUnique(['from_contract_id']);
        });
    }

    public function down(): void
    {
        Schema::table('contract_cessions', function (Blueprint $table) {
            $table->unique('from_contract_id');
        });

        Schema::table('contract_cessions', function (Blueprint $table) {
            $table->dropIndex('contract_cessions_from_contract_idx');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
