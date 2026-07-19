<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Amplía todas las columnas de dinero a DECIMAL(15,2).
 *
 * El decimal() por defecto de Laravel es DECIMAL(8,2): tope de
 * $999.999,99. En pesos colombianos una factura con plan +
 * instalación + equipos + vencidas acumuladas supera ese tope y
 * MySQL rechaza o trunca el valor. DECIMAL(15,2) cubre hasta
 * $9.999.999.999.999,99.
 *
 * Los porcentajes pasan a DECIMAL(5,2) (0.00–999.99) y la cantidad
 * de ítems a DECIMAL(10,2), suficientes para su dominio.
 *
 * Se usa ALTER TABLE ... MODIFY directo (MySQL) para no depender
 * de doctrine/dbal; cada MODIFY restaura la nulabilidad y el
 * default originales de la columna. Ampliar un DECIMAL nunca
 * pierde datos.
 */
return new class extends Migration
{
    /**
     * [tabla => [columna => definición]] con la nulabilidad y
     * defaults reales del esquema actual.
     */
    private const MONEY_COLUMNS = [
        'invoices' => [
            'pending_invoice_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'tax' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'total' => 'DECIMAL(15,2) NOT NULL',
        ],
        'invoice_items' => [
            'quantity' => 'DECIMAL(10,2) NOT NULL',
            'unit_price' => 'DECIMAL(15,2) NOT NULL',
            'percentage_tax' => 'DECIMAL(5,2) NOT NULL',
            'tax' => 'DECIMAL(15,2) NOT NULL',
            'total' => 'DECIMAL(15,2) NOT NULL',
        ],
        'payments' => [
            'amount' => 'DECIMAL(15,2) NOT NULL',
        ],
        'aditional_charges' => [
            'amount' => 'DECIMAL(15,2) NOT NULL',
        ],
        'cash_registers' => [
            'initial_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
            'final_amount' => 'DECIMAL(15,2) NULL',
            'total_income' => 'DECIMAL(15,2) NULL',
            'total_expenses' => 'DECIMAL(15,2) NULL',
            'expected_amount' => 'DECIMAL(15,2) NULL',
            'difference' => 'DECIMAL(15,2) NULL',
        ],
        'cash_register_transactions' => [
            'amount' => 'DECIMAL(15,2) NOT NULL',
        ],
        'branches' => [
            'moving_price' => 'DECIMAL(15,2) NULL',
            'reconnection_price' => 'DECIMAL(15,2) NULL',
        ],
        'services' => [
            'base_price' => 'DECIMAL(15,2) NOT NULL',
            'tax_percentage' => 'DECIMAL(5,2) NOT NULL',
        ],
    ];

    /**
     * Definiciones originales, para poder revertir. La reversión
     * puede fallar si ya existen valores que no caben en el tamaño
     * original (comportamiento deseado: nunca truncar en silencio).
     */
    private const ORIGINAL_COLUMNS = [
        'invoices' => [
            'pending_invoice_amount' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            'tax' => 'DECIMAL(8,2) NOT NULL DEFAULT 0',
            'total' => 'DECIMAL(8,2) NOT NULL',
        ],
        'invoice_items' => [
            'quantity' => 'DECIMAL(8,2) NOT NULL',
            'unit_price' => 'DECIMAL(8,2) NOT NULL',
            'percentage_tax' => 'DECIMAL(8,2) NOT NULL',
            'tax' => 'DECIMAL(8,2) NOT NULL',
            'total' => 'DECIMAL(8,2) NOT NULL',
        ],
        'payments' => [
            'amount' => 'DECIMAL(8,2) NOT NULL',
        ],
        'aditional_charges' => [
            'amount' => 'DECIMAL(8,2) NOT NULL',
        ],
        'cash_registers' => [
            'initial_amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
            'final_amount' => 'DECIMAL(12,2) NULL',
            'total_income' => 'DECIMAL(12,2) NULL',
            'total_expenses' => 'DECIMAL(12,2) NULL',
            'expected_amount' => 'DECIMAL(12,2) NULL',
            'difference' => 'DECIMAL(12,2) NULL',
        ],
        'cash_register_transactions' => [
            'amount' => 'DECIMAL(12,2) NOT NULL',
        ],
        'branches' => [
            'moving_price' => 'DECIMAL(8,2) NULL',
            'reconnection_price' => 'DECIMAL(8,2) NULL',
        ],
        'services' => [
            'base_price' => 'DECIMAL(8,2) NOT NULL',
            'tax_percentage' => 'DECIMAL(8,2) NOT NULL',
        ],
    ];

    public function up(): void
    {
        $this->applyDefinitions(self::MONEY_COLUMNS);
    }

    public function down(): void
    {
        $this->applyDefinitions(self::ORIGINAL_COLUMNS);
    }

    private function applyDefinitions(array $definitions): void
    {
        foreach ($definitions as $table => $columns) {
            foreach ($columns as $column => $definition) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` {$definition}");
            }
        }
    }
};
