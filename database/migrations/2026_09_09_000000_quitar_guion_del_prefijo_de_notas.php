<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quita el guion del prefijo de las series de notas: "NC-" pasa a "NC".
 *
 * POR QUÉ
 * -------
 * La DIAN acepta una nota numerada "NC-2", pero la marca: «Valida
 * número de factura no contenga caracteres adicionales como espacios o
 * guiones» —regla CAD05a—. Es una notificación, no un rechazo, pero
 * queda pegada al documento en su catálogo para siempre.
 *
 * QUÉ NO TOCA
 * -----------
 * Las notas YA EMITIDAS. Su `full_number` es del documento, no de la
 * serie: "NC-1" y "NC-2" están validadas por la DIAN con ese número y
 * reescribirlas sería falsear un documento fiscal. Solo cambia de qué
 * prefijo salen las SIGUIENTES, que serán "NC3", "NC4"...
 *
 * No hay riesgo de repetir un número: el consecutivo sigue su cuenta y
 * "NC3" no colisiona con "NC-1" ni "NC-2" en el UNIQUE de
 * `full_number`.
 *
 * Solo toca las series cuyo prefijo TERMINA en guion, así que volver a
 * ejecutarla no hace nada.
 */
return new class extends Migration
{
    /** Los tipos de serie afectados: solo las notas. */
    private const TIPOS = ['credit_note', 'debit_note'];

    public function up(): void
    {
        DB::table('document_sequences')
            ->whereIn('document_type', self::TIPOS)
            ->where('prefix', 'like', '%-')
            ->update(['prefix' => DB::raw("TRIM(TRAILING '-' FROM prefix)")]);
    }

    public function down(): void
    {
        // Se devuelve el guion a las mismas series. Las notas emitidas
        // entre medias conservan el número con el que se emitieron: eso
        // no se revierte, y no debe revertirse.
        DB::table('document_sequences')
            ->whereIn('document_type', self::TIPOS)
            ->where('prefix', 'not like', '%-')
            ->update(['prefix' => DB::raw("CONCAT(prefix, '-')")]);
    }
};
