<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Por que no se pudo generar un documento electronico.
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * Armar el XML puede fallar por motivos perfectamente normales: al
 * cliente le falta el municipio, la empresa no tiene resolucion
 * vigente, el rango se agoto. Eso NO puede tumbar la emision de la
 * factura —la factura ya existe y el cliente ya tiene el servicio—,
 * asi que el fallo se traga y se anota.
 *
 * Sin esta columna, «se trago el fallo» significa que el documento se
 * queda en borrador sin que nadie sepa por que, y hay que ir a leer los
 * logs del servidor para averiguarlo. Con ella, la propia fila lo dice.
 *
 * SE LIMPIA AL LOGRARLO
 * ---------------------
 * Cuando el documento se genera bien, el error se borra: si se quedara,
 * una pantalla que lo muestre estaria enseñando un problema que ya no
 * existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->text('last_error')->nullable()->after('status');
        });

        // Y el ambiente pasa a poder ser nulo.
        //
        // Nacio obligatorio porque un documento EMITIDO siempre tiene
        // uno, y congelarlo es justo lo que hace que su QR siga
        // apuntando al catalogo correcto cuando la empresa pase de
        // pruebas a produccion.
        //
        // Pero un documento que fallo ANTES de poder averiguarlo —la
        // empresa no tiene configuracion DIAN todavia— no tiene
        // ninguno, y ponerle uno inventado seria peor que dejarlo
        // vacio: diria que se emitio en un ambiente en el que no se
        // emitio. Se hace con SQL directo porque cambiar una columna
        // con el Blueprint exige doctrine/dbal, que no esta instalado.
        DB::statement('ALTER TABLE electronic_documents MODIFY environment_code VARCHAR(2) NULL');
    }

    public function down(): void
    {
        Schema::table('electronic_documents', function (Blueprint $table) {
            $table->dropColumn('last_error');
        });

        // Los borradores que fallaron no tienen ambiente: se les pone
        // el de pruebas para poder volver a la columna obligatoria.
        DB::table('electronic_documents')->whereNull('environment_code')->update(['environment_code' => '2']);

        DB::statement('ALTER TABLE electronic_documents MODIFY environment_code VARCHAR(2) NOT NULL');
    }
};
