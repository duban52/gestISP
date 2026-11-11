<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Que borrar una fila no destruya la contabilidad.
 *
 * EL PROBLEMA
 * -----------
 * El esquema estaba lleno de `ON DELETE CASCADE` encadenados, y las
 * cadenas acababan todas en el mismo sitio:
 *
 *     branches → contracts → invoices → payments
 *                         → invoice_items
 *                         → credit_debit_notes
 *     clients  → contracts → (lo mismo)
 *     users    → invoices
 *     users    → cash_registers → cash_register_transactions
 *
 * Es decir: borrar una SUCURSAL se llevaba su facturación entera,
 * incluidas las facturas electrónicas transmitidas a la DIAN y las
 * secuencias de numeración autorizadas. Borrar un USUARIO se llevaba
 * las facturas que hubiera emitido y su caja. Y nada de eso daba
 * error: la base lo hacía obedientemente, en silencio.
 *
 * Lo más engañoso era `UserController::destroy`, que atrapa
 * `QueryException` y responde «no se puede eliminar porque tiene
 * registros asociados». Esa protección NUNCA se disparaba para las
 * facturas: con CASCADE no hay excepción que atrapar, hay borrado.
 *
 * QUÉ CAMBIA Y POR QUÉ ASÍ
 * ------------------------
 * · **RESTRICT** donde el hijo es un documento con valor legal o
 *   contable —facturas, pagos, notas, retenciones, movimientos de
 *   caja, contratos—. La base pasa a NEGARSE, y quien quiera borrar
 *   el padre tiene que resolver antes lo que cuelga. Un error es
 *   infinitamente mejor que un borrado silencioso.
 *
 * · **SET NULL** donde el hijo DEBE sobrevivir y la referencia es
 *   informativa: la sucursal de un cliente es su «sede de origen»
 *   (el cliente es de la empresa), y el usuario de una factura es
 *   quien la emitió. Perder el dato de quién la emitió es malo;
 *   perder la factura es ilegal.
 *
 * LO QUE NO SE TOCA
 * -----------------
 * Las cascadas donde el hijo no tiene sentido sin el padre y se
 * puede reconstruir: `invoice_items` de su factura, `plan_service`,
 * los puertos de una OLT, las métricas de una ONT. Ahí la cascada es
 * correcta y quitarla solo dejaría basura huérfana.
 *
 * NO SE PIERDE NINGÚN DATO AL APLICAR ESTO. Solo cambian las reglas
 * de borrado futuro: ninguna fila se toca.
 */
return new class extends Migration
{
    /**
     * [tabla, columna, restricción, tabla referida, nueva regla]
     *
     * El nombre de la restricción se lee de la base y no se asume:
     * en una base que lleva años migrándose, los nombres no siempre
     * siguen la convención de Laravel.
     */
    private const CAMBIOS = [
        // ---- Lo que cuelga de una SUCURSAL ----
        ['contracts', 'branch_id', 'branches', 'RESTRICT'],
        ['account_credits', 'branch_id', 'branches', 'RESTRICT'],
        ['credit_debit_notes', 'branch_id', 'branches', 'RESTRICT'],
        ['payment_retentions', 'branch_id', 'branches', 'RESTRICT'],
        ['invoice_numbering_sequences', 'branch_id', 'branches', 'RESTRICT'],
        ['cash_registers', 'branch_id', 'branches', 'RESTRICT'],
        // El cliente es de la EMPRESA; la sucursal solo dice dónde se
        // dio de alta. Sobrevive sin ella.
        ['clients', 'branch_id', 'branches', 'SET NULL'],

        // ---- Lo que cuelga de un CLIENTE ----
        ['contracts', 'client_id', 'clients', 'RESTRICT'],

        // ---- Lo que cuelga de un CONTRATO ----
        ['invoices', 'contract_id', 'contracts', 'RESTRICT'],
        ['payment_retentions', 'contract_id', 'contracts', 'RESTRICT'],

        // ---- Lo que cuelga de una FACTURA ----
        ['payments', 'invoice_id', 'invoices', 'RESTRICT'],
        ['credit_debit_notes', 'invoice_id', 'invoices', 'RESTRICT'],
        ['payment_retentions', 'invoice_id', 'invoices', 'RESTRICT'],

        // ---- Lo que cuelga de un USUARIO ----
        // La factura sobrevive a quien la emitió: el dato de autoría
        // se pierde, el documento fiscal no.
        ['invoices', 'user_id', 'users', 'SET NULL'],
        // La caja y sus movimientos son contabilidad: no se borran
        // porque alguien dé de baja a un cajero.
        ['cash_registers', 'user_id', 'users', 'RESTRICT'],
        ['cash_register_transactions', 'created_by', 'users', 'RESTRICT'],
    ];

    public function up(): void
    {
        foreach (self::CAMBIOS as [$tabla, $columna, $referida, $regla]) {
            $this->rehacer($tabla, $columna, $referida, $regla);
        }
    }

    /**
     * Vuelve a crear la clave foránea con otra regla de borrado.
     *
     * MySQL no sabe cambiar la regla: hay que soltar la restricción y
     * volver a ponerla. El índice que la acompaña se conserva, así
     * que la operación es de metadatos y no reescribe la tabla —en
     * una tabla de facturas de un ISP eso importa—.
     */
    private function rehacer(string $tabla, string $columna, string $referida, string $regla): void
    {
        if (!Schema::hasTable($tabla) || !Schema::hasColumn($tabla, $columna)) {
            return;
        }

        $nombre = $this->nombreDeLaRestriccion($tabla, $columna);

        if ($nombre) {
            DB::statement("ALTER TABLE `{$tabla}` DROP FOREIGN KEY `{$nombre}`");
        }

        // Se reutiliza el nombre que tenía, o se compone el de
        // Laravel si no había restricción previa.
        $nombre = $nombre ?: "{$tabla}_{$columna}_foreign";

        DB::statement(
            "ALTER TABLE `{$tabla}` ADD CONSTRAINT `{$nombre}` "
            . "FOREIGN KEY (`{$columna}`) REFERENCES `{$referida}` (`id`) "
            . "ON DELETE {$regla} ON UPDATE CASCADE"
        );
    }

    /** El nombre real de la clave foránea, leído de la base. */
    private function nombreDeLaRestriccion(string $tabla, string $columna): ?string
    {
        $fila = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS nombre
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            [$tabla, $columna],
        );

        return $fila->nombre ?? null;
    }

    /**
     * Volver atrás restaura el CASCADE.
     *
     * Se implementa por honestidad con la reversibilidad de las
     * migraciones, pero deshacer esto devuelve el sistema a un estado
     * en el que borrar una sucursal destruye su contabilidad. No se
     * ejecute sin saber exactamente por qué.
     */
    public function down(): void
    {
        foreach (self::CAMBIOS as [$tabla, $columna, $referida, $regla]) {
            $this->rehacer($tabla, $columna, $referida, 'CASCADE');
        }
    }
};
