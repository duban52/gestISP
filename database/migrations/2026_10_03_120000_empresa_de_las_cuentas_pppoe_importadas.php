<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve su empresa a las cuentas PPPoE importadas de un router.
 *
 * QUÉ PASÓ
 * --------
 * La importación insertaba las cuentas con `insert()` masivo, que es
 * rápido pero NO dispara los eventos del modelo. El gancho de
 * `BelongsToCompany` —el que rellena `company_id` a partir de la
 * sucursal— nunca corría.
 *
 * El resultado: cuentas con la empresa en NULL. Como el alcance de
 * empresa filtra por esa columna, no aparecían en ningún listado, en
 * ninguna búsqueda ni en ningún corte masivo. Estaban en la base de
 * datos y no existían para nadie.
 *
 * En la base de desarrollo eran 300 de 747.
 *
 * CÓMO SE REPARAN
 * ---------------
 * Por su sucursal, que sí tenían. Es el mismo camino que sigue el
 * gancho, así que no se inventa nada: se completa lo que debió
 * escribirse en su momento.
 *
 * La causa está corregida en PppoeAccountController::revisarSecrets,
 * que ahora pone `company_id` en el array que se inserta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pppoe_accounts')
            ->whereNull('company_id')
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->chunkById(500, function ($cuentas) {
                foreach ($cuentas->groupBy('branch_id') as $branchId => $grupo) {
                    $companyId = DB::table('branches')->where('id', $branchId)->value('company_id');

                    if (!$companyId) {
                        continue;
                    }

                    DB::table('pppoe_accounts')
                        ->whereIn('id', $grupo->pluck('id'))
                        ->update(['company_id' => $companyId]);
                }
            });
    }

    public function down(): void
    {
        // No se deshace: volver a dejarlas sin empresa las escondería
        // otra vez, que es exactamente el fallo que esto repara.
    }
};
