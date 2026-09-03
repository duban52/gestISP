<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los datos fiscales de cada linea, congelados al emitir (fase 10).
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * El XML de una factura electronica exige, POR LINEA, el codigo de
 * producto y la unidad de medida. Esos datos viven en `services`, pero
 * `invoice_items` no guarda de que servicio salio cada linea: solo
 * guarda su descripcion en texto.
 *
 * Buscar el servicio por su nombre para recuperarlos seria adivinar: el
 * nombre se puede cambiar, y ademas hay lineas que no vienen de ningun
 * servicio (los cargos adicionales y sus cuotas).
 *
 * POR QUE SE COPIAN Y NO SE ENLAZAN
 * ---------------------------------
 * Se podria haber anadido `service_id` y leer los codigos al vuelo. Se
 * copian a proposito, que es lo que hace el resto del sistema con todo
 * lo que va en un documento emitido —`document_kind`,
 * `affinity_group_id`, `concept_label` de las notas—:
 *
 *   Si manana alguien corrige el UNSPSC de un servicio, las facturas
 *   YA EMITIDAS tienen que seguir diciendo lo que decian. Un documento
 *   emitido no cambia porque se edite otra cosa despues.
 *
 * Y es que ademas, con la DIAN, cambiar no es una opcion: el XML ya se
 * transmitio y lo que quede aqui tiene que poder reproducirlo.
 *
 * NACEN NULAS
 * -----------
 * Las facturas que ya existen no las tienen y no se pueden inventar. Se
 * quedan nulas: son facturas internas de antes de la habilitacion, que
 * nunca van a producir un XML.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // Copiados del servicio en el momento de emitir. Nulos en
            // las lineas que no vienen de un servicio (cargos
            // adicionales) y en todo lo emitido antes de la fase 10.
            $table->string('product_code', 40)->nullable()->after('description');
            $table->string('product_code_type', 5)->nullable()->after('product_code');
            $table->string('unit_measure_code', 10)->nullable()->after('product_code_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['product_code', 'product_code_type', 'unit_measure_code']);
        });
    }
};
