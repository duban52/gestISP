<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El PDF masivo deja de ser una caja negra.
 *
 * LO QUE HABÍA
 * ------------
 * La tabla solo guardaba la ruta del PDF terminado. La pantalla
 * preguntaba «¿hay algún PDF de esta sucursal?» y, como SIEMPRE había
 * uno de alguna vez anterior, contestaba que sí al instante: se abría
 * el visor con el PDF VIEJO mientras el nuevo todavía se estaba
 * generando. Con mil facturas eso son varios minutos enseñando un
 * documento que no es el que se pidió.
 *
 * Y si el trabajo fallaba, nadie se enteraba nunca: la pantalla
 * seguía mostrando el anterior tan tranquila.
 *
 * LO QUE SE GUARDA AHORA
 * ----------------------
 * El ESTADO de cada generación, no solo su resultado. Eso es lo que
 * permite preguntar por UNA generación concreta —la que acabo de
 * pedir— en vez de «la última que haya», y lo que da el porcentaje
 * para la barra de progreso.
 *
 * `user_id` es quien la pidió: a quien hay que avisar cuando termine,
 * y no a toda la sucursal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_reports', function (Blueprint $table) {
            // Quien lo pidió. Nullable por los informes que ya existen,
            // de cuando no se guardaba.
            $table->foreignId('user_id')->nullable()->after('company_id');

            $table->string('status', 20)->default('listo')->after('user_id');

            // Cuántas facturas lleva de cuántas. La barra de progreso
            // sale de aquí.
            $table->unsignedInteger('total')->default(0)->after('status');
            $table->unsignedInteger('procesadas')->default(0)->after('total');

            $table->text('error')->nullable()->after('procesadas');
            $table->timestamp('finished_at')->nullable()->after('error');
        });

        // `pdf_path` era NOT NULL: ahora la fila nace ANTES de que
        // exista el archivo, para poder seguir el progreso desde el
        // primer segundo.
        //
        // Con SQL directo y no con `->change()`: ese método necesita
        // doctrine/dbal, que este proyecto no tiene instalado.
        DB::statement('ALTER TABLE pdf_reports MODIFY pdf_path VARCHAR(255) NULL');

        // Las filas que ya existían son PDFs terminados: se marcan como
        // tales para que no aparezcan como generaciones a medias.
        DB::table('pdf_reports')->whereNull('finished_at')->update([
            'status' => 'listo',
            'finished_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('pdf_reports', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'status', 'total', 'procesadas', 'error', 'finished_at']);
        });
    }
};
