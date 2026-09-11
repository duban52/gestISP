<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El dueño del almacén y quien lo creó son dos cosas distintas.
 *
 * EL PROBLEMA
 * -----------
 * `warehouses.user_id` hacía de las dos: el formulario lo llamaba
 * «vincular usuario a almacén» —el dueño— pero `store()` lo rellenaba
 * con `Auth::id()` cuando se dejaba vacío, y el listado lo mostraba como
 * «Creado por». Así que:
 *
 * · Un almacén creado por la oficina para un técnico quedaba a nombre de
 *   la oficina si a alguien se le olvidaba elegirlo.
 * · El almacén PRINCIPAL quedaba a nombre de quien lo creó, y por eso
 *   una orden técnica cerrada desde la oficina descargaba de ahí.
 * · No había forma de dejar un almacén SIN dueño, que es justo lo que es
 *   una bodega, un almacén general o el de cabecera.
 *
 * CÓMO QUEDA
 * ----------
 * · `user_id`    = DUEÑO. Nullable de verdad: null significa que el
 *   almacén no es de nadie en particular — bodega, cabecera, general.
 * · `created_by` = quién lo dio de alta. Solo informativo.
 *
 * EL REPARTO DE LO EXISTENTE
 * --------------------------
 * `created_by` se rellena con el `user_id` actual, que es lo único que
 * se sabe: hasta hoy esa columna era el creador en la práctica.
 *
 * NO se toca `user_id`. Podría argumentarse que los almacenes cuyo
 * nombre suena a bodega deberían quedarse sin dueño, pero adivinar por
 * el nombre es justo el tipo de suposición que crea el problema
 * siguiente. Se deja como está y se corrige a mano desde la pantalla de
 * edición, que ahora existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::statement('UPDATE warehouses SET created_by = user_id WHERE created_by IS NULL');
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
