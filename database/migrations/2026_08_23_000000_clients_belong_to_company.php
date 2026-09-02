<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cliente pertenece a la EMPRESA, no a la sucursal.
 *
 * POR QUE
 * -------
 * Una persona puede tener servicio en varias sedes de la misma
 * empresa. Con el cliente atado a una sucursal, esa persona hay que
 * crearla dos veces —dos fichas, dos historiales— y ante la DIAN es
 * UN SOLO adquiriente. En los datos reales que revisamos, 215 personas
 * tenian contratos en mas de una sede.
 *
 * Ademas, en panel consolidado no hay UNA sucursal activa, asi que
 * crear un cliente fallaba: no habia de donde sacar branch_id.
 *
 * QUE PASA CON branch_id
 * ----------------------
 * Se conserva y pasa a ser OPCIONAL: es la "sucursal de origen", donde
 * se dio de alta. Sirve para saber de donde salio, pero ya no acota
 * quien lo ve — eso lo hace company_id.
 *
 * El CONTRATO sigue siendo de una sucursal. Esa es la division: el
 * cliente es de la empresa, el servicio es de la sede.
 *
 * UNICIDAD
 * --------
 * El mismo documento no puede repetirse DENTRO de una empresa —seria
 * la misma persona dos veces—, pero SI puede existir en empresas
 * distintas: son contribuyentes distintos y sus datos estan aislados.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sin esto, en panel consolidado no se puede crear un cliente.
        DB::statement('ALTER TABLE clients MODIFY branch_id BIGINT UNSIGNED NULL');

        Schema::table('clients', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'type_document', 'identity_number'],
                'clients_company_document_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique('clients_company_document_unique');
        });

        // No se vuelve a NOT NULL: para entonces puede haber clientes
        // creados desde un panel consolidado, sin sucursal de origen, y
        // la migracion fallaria.
    }
};
