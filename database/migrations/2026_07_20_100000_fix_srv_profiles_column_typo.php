<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige el nombre de la columna del identificador de srv-profile.
 *
 * La tabla se creó con "id_srv_pofile" (falta la "r"), pero el
 * modelo declara "id_srv_profile" en $fillable. Al no coincidir,
 * Eloquent descartaba el valor y el INSERT fallaba porque la
 * columna real no admite nulos: crear un srv-profile era imposible.
 *
 * Se renombra con SQL directo porque el proyecto no tiene
 * doctrine/dbal, que es lo que necesitaría $table->renameColumn().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('srv_profiles', 'id_srv_pofile')) {
            return;
        }

        DB::statement('ALTER TABLE srv_profiles CHANGE id_srv_pofile id_srv_profile VARCHAR(50) NOT NULL');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('srv_profiles', 'id_srv_profile')) {
            return;
        }

        DB::statement('ALTER TABLE srv_profiles CHANGE id_srv_profile id_srv_pofile VARCHAR(50) NOT NULL');
    }
};
