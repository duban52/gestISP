<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué ONT no se importó, y por qué.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * La importación decía «839 importadas, 200 omitidas por datos
 * incompletos» y ahí se acababa la conversación: ni cuáles eran las
 * 200 ni qué les faltaba. El operador no podía corregir nada porque
 * no sabía qué corregir.
 *
 * Peor: esas 200 casi nunca eran «datos incompletos». El contador
 * `skipped_invalid` sumaba DOS cosas distintas —la ONT a la que de
 * verdad le faltaba el slot o el puerto, y el lote entero de cien que
 * reventó por cualquier motivo—, y el motivo real solo quedaba en el
 * log del servidor, donde nadie lo mira. Doscientas omitidas eran
 * exactamente dos lotes de cien caídos.
 *
 * QUÉ GUARDA
 * ----------
 * Una lista de `{sn, ubicacion, motivo}`. En JSON y no en una tabla
 * aparte porque se escribe una vez, se lee con su corrida y se borra
 * con ella; una tabla nueva solo añadiría una llave foránea que
 * mantener.
 *
 * ACOTADA A PROPÓSITO (ver ImportOltOnts::LIMITE_DETALLES): con mil
 * ONT ilegibles, guardar las mil no ayuda más que guardar las
 * primeras doscientas, y sí hincha la fila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ont_import_runs', function (Blueprint $table) {
            $table->json('skipped_details')->nullable()->after('matched_contracts');
        });
    }

    public function down(): void
    {
        Schema::table('ont_import_runs', function (Blueprint $table) {
            $table->dropColumn('skipped_details');
        });
    }
};
