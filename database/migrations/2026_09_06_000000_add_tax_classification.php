<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La clasificacion fiscal del servicio: gravado, excluido o exento.
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * Hasta ahora la distincion vivia IMPLICITA en `tax_percentage = 0`, y
 * ese cero significaba tres cosas que el sistema no podia diferenciar:
 *
 *   · excluido —la ley no lo sujeta a IVA—,
 *   · exento —sujeto, pero a tarifa 0%—,
 *   · o que a alguien se le olvido poner la tarifa.
 *
 * Y en el XML de la DIAN las dos primeras NO se escriben igual: el
 * excluido no lleva bloque de impuestos y el exento lo lleva en ceros.
 * Sin saber cual es, no se puede armar el documento correcto.
 *
 * COMO SE MIGRA LO QUE YA EXISTE
 * ------------------------------
 * Con tarifa > 0 -> gravado, que es lo unico que puede ser.
 * Con tarifa = 0 -> excluido, porque es lo que corresponde al caso real
 * de este sistema: el internet residencial de estratos 1, 2 y 3 esta
 * excluido de IVA, y es lo que tienen hoy los servicios al 0%.
 *
 * OJO: la migracion ADIVINA a partir de la tarifa, que es justo lo que
 * este campo viene a dejar de hacer. Hay que revisar el resultado en la
 * pantalla de servicios — sobre todo si algun servicio al 0% no era
 * excluido sino exento, o al reves.
 *
 * TAMBIEN SE CONGELA EN LA LINEA
 * ------------------------------
 * Igual que el codigo de producto: la factura emitida tiene que seguir
 * diciendo como se trato el IVA, aunque manana se reclasifique el
 * servicio. Su XML ya se transmitio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('tax_classification', 20)
                ->default('gravado')
                ->after('tax_percentage');
        });

        // Lo que ya existe: la tarifa es lo unico de lo que se puede
        // deducir, y por eso hay que revisarlo despues.
        DB::table('services')->where('tax_percentage', '>', 0)
            ->update(['tax_classification' => 'gravado']);

        DB::table('services')->where('tax_percentage', '<=', 0)
            ->update(['tax_classification' => 'excluido']);

        Schema::table('invoice_items', function (Blueprint $table) {
            // Nulable: las lineas emitidas antes de esto no la tienen y
            // no se puede inventar. Solo las nuevas la llevan.
            $table->string('tax_classification', 20)
                ->nullable()
                ->after('unit_measure_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('tax_classification');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('tax_classification');
        });
    }
};
