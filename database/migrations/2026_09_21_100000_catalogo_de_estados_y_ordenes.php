<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los estados de contrato y los tipos de orden, configurables.
 *
 * QUÉ CAMBIA
 * ----------
 * Hasta ahora los siete estados vivían en un enum de PHP y los doce
 * detalles de orden en dos constantes. Añadir «Exonerado» —tiene
 * servicio pero no se le cobra— exigía tocar código y desplegar.
 *
 * LO QUE **NO** CAMBIA, Y ES LO IMPORTANTE
 * ----------------------------------------
 * `contracts.status` sigue guardando el NOMBRE del estado, igual que
 * siempre. Esto es un catálogo, no una llave foránea: cambiar la
 * columna a un id obligaría a reescribir todas las comparaciones del
 * sistema —hay decenas— y a migrar el histórico, para no ganar nada.
 *
 * Lo que aporta la tabla es lo que el enum no podía decir: si un
 * estado factura, si tiene servicio y si es una baja. Esas tres
 * respuestas estaban repartidas en listas dentro del código.
 *
 * LOS QUE YA EXISTEN SE INSERTAN AQUÍ, marcados `is_system`: son los
 * que el código nombra por su valor (ContractStatus::Activo y demás),
 * así que se pueden desactivar o describir, pero no renombrar ni
 * borrar. Si desaparecieran, dejarían de funcionar la facturación, la
 * suspensión por mora y el cierre de órdenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('description', 255)->nullable();

            // Las preguntas que el sistema le hace a un estado.
            //
            // OJO CON LAS DOS PRIMERAS: no son la misma. Hoy «Por
            // Instalar» no entra en la corrida mensual pero SÍ se le
            // puede facturar a mano —un cobro de instalación antes de
            // instalar—, y juntarlas en un solo campo pondría a la
            // corrida a facturar contratos que hoy no factura.
            $table->boolean('bills')->default(true);       // ¿se le PUEDE facturar?
            $table->boolean('auto_bills')->default(false); // ¿entra en la corrida mensual?
            $table->boolean('has_service')->default(true); // ¿tiene servicio? (aprovisionamiento)
            $table->boolean('is_final')->default(false);   // ¿es una baja definitiva?

            $table->string('color', 20)->default('secondary');
            $table->boolean('active')->default(true);
            // Los del enum: el código los nombra, no se pueden renombrar.
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('technical_order_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('technical_order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('technical_order_type_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);

            // La clave normalizada (sin tildes, en minúsculas) con la que
            // OrderDetailMap agrupa los informes. Se guarda para que un
            // detalle nuevo entre en los informes sin tocar código.
            $table->string('key', 80)->unique();

            // QUÉ LE HACE AL CONTRATO al cerrar la orden.
            //
            // El estado al que lo lleva, si lo lleva a alguno: es lo que
            // hasta ahora estaba escrito en ContractStatusFromOrder.
            $table->string('target_contract_status', 40)->nullable();

            // Y qué le hace a los equipos. Un corte deshabilita la cuenta
            // PPPoE y la ONT; una reconexión las vuelve a habilitar.
            // 'ninguna' es lo que hacen hoy todos menos el retiro, que va
            // por su estado final y libera de verdad (ContractDecommissioner).
            $table->enum('pppoe_action', ['ninguna', 'deshabilitar', 'habilitar'])->default('ninguna');
            $table->enum('ont_action', ['ninguna', 'deshabilitar', 'habilitar'])->default('ninguna');

            $table->string('color', 20)->default('#adb5bd');
            $table->boolean('active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $this->sembrarLoQueYaExiste();
    }

    /**
     * Lo que hoy está en el código, tal cual, para que nada cambie de
     * comportamiento el día que se despliegue esto.
     */
    private function sembrarLoQueYaExiste(): void
    {
        $ahora = now();

        // ---- Estados: los siete de ContractStatus ----
        // Los valores salen de donde estaban escritos: auto_bills de
        // billable(), bills del negativo de noFacturables() y is_final
        // de finales().
        //
        // [nombre, descripción, se le puede facturar, entra en la corrida, tiene servicio, es baja, color]
        $estados = [
            ['Por Instalar', 'Contrato creado; el servicio todavía no se ha instalado.', true, false, false, false, 'info'],
            ['Activo', 'Prestando servicio y al día.', true, true, true, false, 'success'],
            ['Pre-suspensión', 'Tiene facturas vencidas pero conserva el servicio.', true, true, true, false, 'warning'],
            ['Suspendido', 'Servicio cortado por no pago.', false, false, false, false, 'danger'],
            ['Por Reconexión', 'Pagó estando cortado; espera la visita de reconexión.', true, false, false, false, 'info'],
            ['Retirado', 'Tuvo servicio y lo dejó. No se le factura más.', false, false, false, true, 'secondary'],
            ['Anulado', 'Firmó el contrato y nunca tomó el servicio. No se le factura.', false, false, false, true, 'secondary'],
            // «Cortado» es el nombre viejo de Suspendido y sigue habiendo
            // contratos con él: tiene que estar para que el sistema sepa
            // que no se le factura, pero no se ofrece al cambiar estado.
            ['Cortado', 'Nombre antiguo de «Suspendido». No se le factura.', false, false, false, false, 'danger'],
        ];

        foreach ($estados as $orden => [$nombre, $descripcion, $factura, $automatica, $servicio, $final, $color]) {
            DB::table('contract_statuses')->insert([
                'name' => $nombre,
                'description' => $descripcion,
                'bills' => $factura,
                'auto_bills' => $automatica,
                'has_service' => $servicio,
                'is_final' => $final,
                'color' => $color,
                'active' => $nombre !== 'Cortado',
                'is_system' => true,
                'sort_order' => $orden,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // ---- Tipos de orden ----
        $tipos = [];

        foreach ([
            ['Servicio', 'Altas, bajas, cortes y traslados del servicio.'],
            ['Incidencia', 'Fallas reportadas por el cliente.'],
            ['Administrativa', 'Cambia el estado del contrato sin visita técnica.'],
        ] as $orden => [$nombre, $descripcion]) {
            $tipos[$nombre] = DB::table('technical_order_types')->insertGetId([
                'name' => $nombre,
                'description' => $descripcion,
                'active' => true,
                'is_system' => true,
                'sort_order' => $orden,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // ---- Detalles: los doce del formulario ----
        // [nombre, clave, tipo, estado al que lleva, pppoe, ont, color]
        //
        // Los estados salen de ContractStatusFromOrder::POR_DETALLE y los
        // colores de OrderDetailMap, que es donde estaban.
        $detalles = [
            ['Instalación de servicio', 'instalacion de servicio', 'Servicio', 'Activo', 'ninguna', 'ninguna', '#28a745'],
            ['Retiro de servicio', 'retiro de servicio', 'Servicio', 'Retirado', 'ninguna', 'ninguna', '#6c757d'],
            ['Corte de servicio', 'corte de servicio', 'Servicio', 'Suspendido', 'deshabilitar', 'deshabilitar', '#dc3545'],
            ['Traslado de servicio', 'traslado de servicio', 'Servicio', null, 'ninguna', 'ninguna', '#6610f2'],
            ['Adición de servicio', 'adicion de servicio', 'Servicio', null, 'ninguna', 'ninguna', '#20c997'],
            ['Suspensión temporal', 'suspension temporal', 'Servicio', 'Suspendido', 'deshabilitar', 'deshabilitar', '#fd7e14'],
            ['Reconexión', 'reconexion', 'Servicio', 'Activo', 'habilitar', 'habilitar', '#17a2b8'],
            ['Sin servicio de TV', 'sin servicio de tv', 'Incidencia', null, 'ninguna', 'ninguna', '#e83e8c'],
            ['Sin servicio de internet', 'sin servicio de internet', 'Incidencia', null, 'ninguna', 'ninguna', '#dc3545'],
            ['Sin servicio', 'sin servicio', 'Incidencia', null, 'ninguna', 'ninguna', '#b02a37'],
            ['Configuraciones', 'configuraciones', 'Incidencia', null, 'ninguna', 'ninguna', '#0d6efd'],
            ['Otros', 'otros', 'Incidencia', null, 'ninguna', 'ninguna', '#adb5bd'],
        ];

        foreach ($detalles as $orden => [$nombre, $clave, $tipo, $estado, $pppoe, $ont, $color]) {
            DB::table('technical_order_details')->insert([
                'technical_order_type_id' => $tipos[$tipo],
                'name' => $nombre,
                'key' => $clave,
                'target_contract_status' => $estado,
                'pppoe_action' => $pppoe,
                'ont_action' => $ont,
                'color' => $color,
                'active' => true,
                'is_system' => true,
                'sort_order' => $orden,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_order_details');
        Schema::dropIfExists('technical_order_types');
        Schema::dropIfExists('contract_statuses');
    }
};
