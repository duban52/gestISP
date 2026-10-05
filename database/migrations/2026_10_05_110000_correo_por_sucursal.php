<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El correo del CLIENTE sale de su sucursal; el del sistema, del panel.
 *
 * DOS CORREOS QUE NO SON EL MISMO
 * -------------------------------
 * Al cliente le escribe su operador: la factura, el aviso de
 * vencimiento y la orden técnica tienen que llegar desde la sede que
 * lo atiende, con su dirección y su dominio. Que le respondan a esa
 * sede y no a una central es media razón de ser de esto.
 *
 * El restablecimiento de contraseña de un usuario del panel es otra
 * cosa: es correo interno de GestISP y no tiene por qué salir del
 * buzón comercial de ninguna sucursal.
 *
 * CÓMO SE DISTINGUEN
 * ------------------
 * Una sola tabla, con `branch_id`:
 *
 *   NULL   → la del SISTEMA. Correo interno, y respaldo de todo.
 *   con id → la de esa SUCURSAL. Lo que se le manda a sus clientes.
 *
 * Una tabla y no dos porque la forma es idéntica —servidor, puerto,
 * usuario, contraseña— y partirla obligaría a mantener dos modelos,
 * dos pantallas y dos juegos de pruebas que dicen lo mismo.
 *
 * LA CADENA DE RESPALDO, QUE ES LO QUE EVITA ROMPER NADA
 * ------------------------------------------------------
 * Sucursal → sistema → `.env`. Una sucursal sin configurar envía por
 * donde envía hoy, así que esto se puede desplegar sobre un sistema
 * que está facturando sin que cambie nada hasta que alguien lo
 * configure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_settings', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')
                ->constrained('branches')->cascadeOnDelete();

            // Una configuración por sucursal, y una sola del sistema.
            $table->unique('branch_id');
        });

        Schema::table('mail_logs', function (Blueprint $table) {
            // Por dónde salió: es lo que permite leer la bitácora
            // cuando una sucursal tiene problemas y las demás no.
            $table->foreignId('branch_id')->nullable()->after('company_id')
                ->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });

        Schema::table('mail_settings', function (Blueprint $table) {
            $table->dropUnique(['branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
