<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Importar de la OLT deja de ir con el permiso de activar una ONT.
 *
 * NO ERAN LA MISMA COSA
 * ---------------------
 * `onts.activate` es autorizar UNA ONT: lo que hace quien atiende el
 * mostrador cuando llega un cliente nuevo. La importación lee la OLT
 * entera, da de alta miles de equipos de una vez, deja una acción
 * masiva y los vincula a contratos. Compartir permiso significaba que
 * cualquiera que pudiera activar una ONT veía la pantalla de
 * importación y podía lanzarla.
 *
 * NADIE PIERDE ACCESO AL DESPLEGAR
 * --------------------------------
 * El permiso nuevo se le concede a los roles que HOY tienen
 * `onts.activate`. Quien venía usando la importación la sigue
 * teniendo, y a partir de ahora se le puede quitar desde el módulo de
 * roles sin tocar su capacidad de activar ONTs — que es justo lo que
 * antes no se podía.
 *
 * Quitarlo de golpe habría dejado sin la función a quien la usa, sin
 * avisar y sin forma de saber por qué desapareció del menú.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SOLO EN INSTALACIONES YA SEMBRADAS.
        //
        // En una base nueva las migraciones corren ANTES del seeder, y
        // el seeder crea `onts.import` con todos los demas. Si esto lo
        // creara antes, el seeder reventaria con
        // «PermissionAlreadyExists» y no habria instalacion nueva que
        // funcionara. Esta migracion esta aqui para reparar lo que ya
        // existe, y se reconoce porque tiene `onts.activate`.
        $existeElViejo = Permission::where('name', 'onts.activate')->exists();

        if (!$existeElViejo) {
            return;
        }

        $permiso = Permission::firstOrCreate(
            ['name' => 'onts.import', 'guard_name' => 'web'],
            ['description' => 'Importar ONTs desde una OLT'],
        );

        $conActivar = Role::whereHas(
            'permissions',
            fn ($q) => $q->where('name', 'onts.activate'),
        )->get();

        foreach ($conActivar as $rol) {
            $rol->givePermissionTo($permiso);
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'onts.import')->delete();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
