<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El logo pasa de la sucursal a la empresa.
 *
 * POR QUE
 * -------
 * El logo es identidad del CONTRIBUYENTE, no de la sede: las cinco
 * sucursales de una empresa imprimen el mismo. Tenerlo por sucursal
 * obligaba a subirlo cinco veces y permitia que divergieran.
 *
 * Y hay un caso donde directamente no funciona: en panel consolidado
 * no hay UNA sucursal activa, asi que no habia de donde sacarlo. El
 * panel principal se quedaba sin logo.
 *
 * QUE PASA CON branches.image
 * ---------------------------
 * La columna se conserva. Se imprime en varios PDF y plantillas, y
 * Branch expone un accesor que devuelve el de la empresa: asi esos
 * consumidores siguen funcionando sin tocarlos mientras la fuente de
 * verdad pasa a ser companies.logo. Se retira cuando se hayan migrado.
 *
 * QUE LOGO SE QUEDA CADA EMPRESA
 * ------------------------------
 * El de su sucursal mas antigua que tenga uno. Es una eleccion
 * arbitraria pero razonable —normalmente la sede principal— y se
 * corrige desde la ficha de la empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conLogo = DB::table('branches')
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->orderBy('id')
            ->get(['company_id', 'image']);

        $puestos = [];

        foreach ($conLogo as $sucursal) {
            if (!$sucursal->company_id || isset($puestos[$sucursal->company_id])) {
                continue;
            }

            DB::table('companies')
                ->where('id', $sucursal->company_id)
                ->whereNull('logo')
                ->update(['logo' => $sucursal->image]);

            $puestos[$sucursal->company_id] = true;
        }
    }

    public function down(): void
    {
        // El logo de la sucursal nunca se borro, asi que basta con
        // soltar el de la empresa.
        DB::table('companies')->update(['logo' => null]);
    }
};
