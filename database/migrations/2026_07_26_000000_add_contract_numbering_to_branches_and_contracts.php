<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número de contrato visible, consecutivo por sucursal.
 *
 * Hasta ahora el contrato se identificaba por su id, que es un
 * consecutivo GLOBAL: en una operación con varias sucursales los
 * números salían intercalados y no servían de cara al cliente.
 *
 * A partir de aquí cada sucursal lleva su propia numeración con un
 * prefijo elegible (por ejemplo ENG000001 para "EasyNet Gómez
 * Plata"). El id se conserva intacto para el uso interno del sistema
 * y para no romper ninguna relación existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Letras que anteceden al consecutivo. Se puede cambiar
            // desde la edición de la sucursal.
            $table->string('contract_prefix', 10)->nullable()->after('name');

            // Último consecutivo entregado. Vive en la sucursal para
            // poder bloquear la fila y que dos altas simultáneas
            // nunca reciban el mismo número.
            $table->unsignedInteger('contract_next_number')->default(0)->after('contract_prefix');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('contract_number', 20)->nullable()->after('id');
            $table->unique('contract_number');
            $table->index(['branch_id', 'contract_number']);
        });

        $this->asignarPrefijosYNumerosExistentes();
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'contract_number']);
            $table->dropUnique(['contract_number']);
            $table->dropColumn('contract_number');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['contract_prefix', 'contract_next_number']);
        });
    }

    /**
     * Da prefijo a las sucursales que ya existen y numera los
     * contratos actuales.
     *
     * Los contratos se numeran por sucursal en orden de creación, de
     * modo que el consecutivo respete la antigüedad real. Sin esto,
     * los contratos anteriores a este cambio se quedarían sin número
     * y no se podrían buscar por él.
     */
    private function asignarPrefijosYNumerosExistentes(): void
    {
        foreach (DB::table('branches')->get() as $sucursal) {
            $prefijo = $this->prefijoDesdeNombre($sucursal->name);

            DB::table('branches')->where('id', $sucursal->id)->update([
                'contract_prefix' => $prefijo,
            ]);

            $consecutivo = 0;

            $contratos = DB::table('contracts')
                ->where('branch_id', $sucursal->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($contratos as $contratoId) {
                $consecutivo++;

                DB::table('contracts')->where('id', $contratoId)->update([
                    'contract_number' => $prefijo . str_pad((string) $consecutivo, 6, '0', STR_PAD_LEFT),
                ]);
            }

            DB::table('branches')->where('id', $sucursal->id)->update([
                'contract_next_number' => $consecutivo,
            ]);
        }
    }

    /**
     * Prefijo propuesto a partir del nombre de la sucursal.
     *
     * Toma la inicial de cada palabra significativa: "EasyNet Gómez
     * Plata" produce ENG. Es solo el valor de partida; después se
     * edita desde la sucursal.
     */
    private function prefijoDesdeNombre(?string $nombre): string
    {
        $palabras = preg_split('/\s+/', trim((string) $nombre));
        $ignoradas = ['de', 'del', 'la', 'las', 'los', 'el', 'y'];

        $letras = '';

        foreach ($palabras as $palabra) {
            if ($palabra === '' || in_array(mb_strtolower($palabra), $ignoradas, true)) {
                continue;
            }

            $letras .= mb_substr($palabra, 0, 1);
        }

        // Sin nombre utilizable se usa CTR (contrato)
        $letras = mb_strtoupper($letras) ?: 'CTR';

        // Se quitan acentos y cualquier símbolo
        $letras = strtr($letras, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        $letras = preg_replace('/[^A-Z0-9]/', '', $letras);

        return mb_substr($letras ?: 'CTR', 0, 5);
    }
};
