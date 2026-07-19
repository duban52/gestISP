<?php

use App\Billing\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalización de datos de facturas + unicidad de período (fase 1).
 *
 * 1. Estados: unifica las variantes históricas de mayúsculas
 *    ('pendiente'/'Pendiente', 'vencida'/'Vencida'...) a los
 *    valores canónicos de App\Billing\Enums\InvoiceStatus. El
 *    código solo funcionaba porque la collation de MySQL es
 *    case-insensitive; los enums eliminan esa fragilidad.
 *    'Generada' (estado del generador antiguo, ya eliminado) pasa
 *    a 'Pendiente'.
 *
 * 2. Backfill: branch_id desde el contrato; period_start/period_end
 *    desde billed_year_month (mes completo — para facturas
 *    prorrateadas antiguas no hay fecha exacta recuperable, el
 *    generador escribe fechas exactas de aquí en adelante);
 *    subtotal = total - tax (no había descuentos históricos).
 *
 * 3. Único (contract_id, billed_year_month): la deduplicación por
 *    código (check-then-insert) permite duplicados en concurrencia;
 *    la base de datos debe ser la garantía final. Si existieran
 *    duplicados la migración ABORTA con la lista, para que se
 *    resuelvan conscientemente (jamás borrar facturas en silencio).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Normalizar estados (la comparación WHERE es
        // case-insensitive en MySQL, así que cada UPDATE captura
        // todas las variantes y escribe el valor canónico) ---
        foreach (InvoiceStatus::cases() as $status) {
            DB::table('invoices')
                ->where('status', $status->value)
                ->update(['status' => $status->value]);
        }

        // Estado del generador antiguo (eliminado): equivale a Pendiente
        DB::table('invoices')
            ->where('status', 'Generada')
            ->update(['status' => InvoiceStatus::Pendiente->value]);

        // --- 2. Backfill ---
        DB::statement('
            UPDATE invoices i
            JOIN contracts c ON c.id = i.contract_id
            SET i.branch_id = c.branch_id
            WHERE i.branch_id IS NULL
        ');

        DB::statement("
            UPDATE invoices
            SET period_start = STR_TO_DATE(CONCAT(billed_year_month, '01'), '%Y%m%d'),
                period_end   = LAST_DAY(STR_TO_DATE(CONCAT(billed_year_month, '01'), '%Y%m%d'))
            WHERE billed_year_month IS NOT NULL
              AND billed_year_month REGEXP '^[0-9]{6}$'
              AND period_start IS NULL
        ");

        DB::statement('
            UPDATE invoices
            SET subtotal = total - tax
            WHERE subtotal = 0
        ');

        // --- 3. Unicidad de período por contrato ---
        $duplicates = DB::table('invoices')
            ->select('contract_id', 'billed_year_month', DB::raw('COUNT(*) AS total'))
            ->whereNotNull('billed_year_month')
            ->groupBy('contract_id', 'billed_year_month')
            ->having('total', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates
                ->map(fn ($d) => "contrato {$d->contract_id}, período {$d->billed_year_month} ({$d->total} facturas)")
                ->implode('; ');

            throw new RuntimeException(
                'No se puede crear la restricción única: existen facturas duplicadas ' .
                "por período que deben resolverse manualmente antes de migrar: {$detail}"
            );
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['contract_id', 'billed_year_month'], 'invoices_contract_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_contract_period_unique');
        });

        // La normalización de estados y los backfills no se
        // revierten: son correcciones de datos, no cambios de
        // esquema, y revertirlas reintroduciría la inconsistencia.
    }
};
