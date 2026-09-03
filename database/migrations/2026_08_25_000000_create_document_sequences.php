<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración interna unificada (fase 6 de multiempresa).
 *
 * DE DÓNDE VIENE
 * --------------
 * Hoy hay TRES mecanismos distintos para numerar documentos, cada uno
 * con su propia copia de la misma lógica —bloquear la fila, sumar uno,
 * formatear—:
 *
 *   · Contratos: dos columnas en la propia fila de `branches`.
 *   · Facturas:  tabla `invoice_numbering_sequences`.
 *   · Notas C/D: tabla `note_numbering_sequences`.
 *
 * Los tres funcionan. El problema no es que estén rotos, es que son
 * tres sitios donde arreglar el mismo fallo, y tres suites donde
 * probar la misma garantía: que dos altas simultáneas nunca reciban el
 * mismo número.
 *
 * QUÉ ENTRA AQUÍ Y QUÉ NO
 * -----------------------
 * Esta tabla es la numeración **interna**: la que se inventa la
 * empresa. Contratos y notas se mudan a ella.
 *
 * Las FACTURAS no mudan su contador todavía, y es deliberado. Su tabla
 * ya lleva resolución, vigencia y rango —se diseñó anticipando la
 * DIAN—, y cuando llegue la numeración fiscal esos consecutivos se
 * moverán a `numbering_ranges`. Moverlos ahora aquí sería moverlos dos
 * veces, y un consecutivo de factura movido de más es exactamente el
 * tipo de operación que no conviene repetir. Lo que sí se unifica es
 * la LÓGICA: `InvoiceNumerator` pasa por el mismo servicio.
 *
 * LA SERIE PUEDE SER DE EMPRESA O DE SUCURSAL
 * -------------------------------------------
 * `branch_id` admite nulo. Con sucursal, cada sede lleva su propio
 * consecutivo —es lo que hacen hoy contratos y notas—; sin ella, toda
 * la empresa comparte uno. Hace falta admitir las dos porque la serie
 * fiscal es del NIT, y conviene que la interna pueda imitar esa forma
 * sin cambiar la tabla.
 *
 * UNA SOLA SERIE ACTIVA POR COMBINACIÓN
 * -------------------------------------
 * Si hubiera dos activas para el mismo tipo y la misma sucursal, de
 * cuál sale el número siguiente dependería del orden de la consulta —
 * y el resultado serían números repetidos con dos series distintas
 * avanzando en paralelo.
 *
 * Se garantiza en la base con una columna generada, la misma técnica
 * que los grupos de afinidad: vale la combinación cuando la serie está
 * activa y NULL cuando no, y un UNIQUE encima admite tantas inactivas
 * como haga falta. Guardar las inactivas importa: son el histórico de
 * qué prefijo se usó y hasta dónde llegó.
 *
 * LA TABLA NACE VACÍA
 * -------------------
 * El relleno NO va aquí, va en un comando con `--dry-run`
 * (`numeracion:migrar`). Un contador mal copiado repite números de
 * documentos ya emitidos, así que ese paso tiene que poder revisarse
 * antes de escribir nada.
 *
 * Mientras tanto no se rompe nada: `DocumentNumberService` crea la
 * serie que le falte tomando como punto de partida el mayor
 * consecutivo REALMENTE usado, así que aunque el comando no se
 * ejecute, el sistema no puede repetir un número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();

            // SIN cascadeOnUpdate en ninguna de las dos: las dos son
            // columnas base de `active_key`, que es generada STORED, y
            // MySQL prohíbe CASCADE/SET NULL sobre esas. Lo rechaza con
            // un «Cannot add foreign key constraint» que no menciona la
            // columna generada por ningún lado.
            $table->foreignId('company_id')
                ->constrained('companies')
                ->restrictOnDelete();

            // Nulo = serie de toda la empresa. Con valor = una por sede.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->restrictOnDelete();

            // 'contract', 'credit_note', 'debit_note'… No es un enum de
            // base a propósito: añadir un tipo no debe pedir un ALTER.
            $table->string('document_type', 40);

            $table->string('prefix', 10);

            // Dígitos del consecutivo. Contratos usan 6 (ENG000001);
            // las notas no rellenan (NC-1). Lo que hoy es una constante
            // distinta en cada servicio pasa a ser un dato de la serie.
            $table->unsignedTinyInteger('padding')->default(0);

            $table->unsignedBigInteger('current_number')->default(0);

            // Rango autorizado. Aquí siempre nulo —la numeración
            // interna no tiene rango—, pero la columna existe para que
            // el servicio sea uno solo: comprueba rango si lo hay, y si
            // no, no. Sin esto habría dos caminos y solo uno probado.
            $table->unsignedBigInteger('range_start')->nullable();
            $table->unsignedBigInteger('range_end')->nullable();

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Una sola ACTIVA por empresa + sucursal + tipo.
            //
            // COALESCE porque branch_id admite nulo y NULL nunca es
            // igual a NULL en un índice: sin él, dos series de empresa
            // (branch_id nulo) del mismo tipo pasarían las dos.
            $table->string('active_key', 120)
                ->nullable()
                ->storedAs(
                    "IF(active = 1, CONCAT(company_id, '-', COALESCE(branch_id, 0), '-', document_type), NULL)"
                );

            $table->unique('active_key', 'document_sequences_one_active');
            $table->index(['company_id', 'document_type'], 'document_sequences_company_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
