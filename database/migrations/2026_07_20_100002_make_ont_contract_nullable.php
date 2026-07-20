<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permite ONTs sin contrato asignado.
 *
 * Al importar las ONTs que ya existen en una OLT, muchas no
 * corresponden a ningún contrato registrado en GestISP (o su
 * descripción no permite identificarlo con certeza). Antes la
 * columna era obligatoria, lo que impedía importarlas.
 *
 * Es un cambio que AMPLÍA lo permitido: ninguna ONT existente se
 * ve afectada y el código ya trataba la relación con seguridad
 * ante nulos ($ont->contract->client->name ?? 'N/A').
 *
 * Se usa SQL directo para no depender de doctrine/dbal, y se
 * recrea la llave foránea con ON DELETE SET NULL: si se elimina
 * un contrato, su ONT queda sin asignar en lugar de borrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quitar la llave foránea antes de alterar la columna
        DB::statement('ALTER TABLE onts DROP FOREIGN KEY onts_contract_id_foreign');
        DB::statement('ALTER TABLE onts MODIFY contract_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE onts ADD CONSTRAINT onts_contract_id_foreign
                       FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        // Las ONTs sin contrato impedirían volver a la restricción
        // original, así que se descartan primero
        DB::statement('DELETE FROM onts WHERE contract_id IS NULL');

        DB::statement('ALTER TABLE onts DROP FOREIGN KEY onts_contract_id_foreign');
        DB::statement('ALTER TABLE onts MODIFY contract_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE onts ADD CONSTRAINT onts_contract_id_foreign
                       FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE');
    }
};
