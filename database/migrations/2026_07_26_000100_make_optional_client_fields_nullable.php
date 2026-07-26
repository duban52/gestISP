<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hace opcionales los datos que un cliente migrado puede no tener.
 *
 * El correo, la fecha de nacimiento y el estrato eran obligatorios en
 * la base de datos. Eso funciona cuando alguien crea el cliente a mano
 * en el formulario, pero al traer la cartera de otro software esos
 * datos muchas veces no existen, y obligarlos llevaría a inventarlos
 * (un correo falso o una fecha de nacimiento cualquiera), que es peor
 * que no tenerlos.
 *
 * Los formularios siguen exigiéndolos donde corresponde: esto solo
 * quita la imposición a nivel de base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clients MODIFY email VARCHAR(255) NULL');
        DB::statement('ALTER TABLE clients MODIFY birthday DATE NULL');
        DB::statement('ALTER TABLE contracts MODIFY social_stratum VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Se rellenan los vacíos antes de volver a exigirlos, o el
        // cambio fallaría con los datos ya importados.
        DB::table('clients')->whereNull('email')->update(['email' => '']);
        DB::table('clients')->whereNull('birthday')->update(['birthday' => '1900-01-01']);
        DB::table('contracts')->whereNull('social_stratum')->update(['social_stratum' => '']);

        DB::statement('ALTER TABLE clients MODIFY email VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE clients MODIFY birthday DATE NOT NULL');
        DB::statement('ALTER TABLE contracts MODIFY social_stratum VARCHAR(255) NOT NULL');
    }
};
