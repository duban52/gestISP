<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La empresa: el contribuyente (fase 1 de multiempresa).
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Hasta ahora el único eje organizativo era la sucursal, y `branches`
 * llevaba su propio NIT: en el modelo de datos, sucursal ERA
 * contribuyente. Eso funciona mientras cada instalación sea de una
 * sola empresa, pero se rompe en cuanto dos empresas comparten la
 * plataforma — y se rompe de una forma fea, porque la facturación
 * electrónica es el punto donde un error de aislamiento deja de ser
 * un fallo y pasa a ser un problema tributario de un tercero.
 *
 * Además hoy el NIT está duplicado: cinco sucursales de la misma
 * empresa guardan cinco copias del mismo dato, que pueden divergir.
 * Un dato fiscal no puede tener cinco versiones.
 *
 * QUÉ VIVE AQUÍ Y QUÉ EN LA SUCURSAL
 * ----------------------------------
 * Aquí, lo FISCAL: identidad tributaria, régimen, responsabilidades,
 * y más adelante el certificado digital, las resoluciones de
 * numeración y la configuración DIAN — todo ello es del NIT, no de la
 * sede.
 *
 * En la sucursal, lo OPERATIVO: dirección de la sede, contacto,
 * precios, cajas, almacenes, red, y sus propios prefijos y
 * consecutivos.
 *
 * LOS CAMPOS FISCALES NACEN VACÍOS
 * --------------------------------
 * Se crean ya para no tener que alterar la tabla otra vez, pero se
 * rellenan en la fase de datos fiscales. Exigirlos ahora dejaría sin
 * poder guardar una empresa a quien todavía no tiene esa información
 * a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // ---- Identidad ----
            $table->string('legal_name');
            $table->string('trade_name')->nullable();

            // Código del tipo de documento según la DIAN (31 = NIT).
            // Se guarda el CÓDIGO y no el texto para no repetir el
            // problema de clients.type_document, que hoy es texto
            // libre con tres variantes y una errata.
            $table->string('document_type_code', 5)->default('31');
            $table->string('document_number', 20);
            // Se calcula a partir del NIT, pero se guarda: recalcularlo
            // en cada uso es una ocasión más de equivocarse.
            $table->string('verification_digit', 1)->nullable();

            // ---- Datos tributarios (fase de datos fiscales) ----
            $table->string('organization_type_code', 5)->nullable();
            $table->string('tax_regime_code', 5)->nullable();

            // ---- Domicilio fiscal: el del RUT, no el de la sede ----
            $table->string('address')->nullable();
            $table->string('department_dane_code', 5)->nullable();
            $table->string('municipality_dane_code', 5)->nullable();
            $table->string('postal_code', 10)->nullable();

            // ---- Contacto ----
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('logo')->nullable();

            /**
             * Modalidad de operación.
             *
             * independent  → cada sucursal es un contexto propio; el
             *                usuario elige una al entrar. Es el
             *                comportamiento de siempre.
             * consolidated → la empresa trabaja todas sus sucursales
             *                desde un panel único; al crear un
             *                documento se elige la sucursal.
             *
             * Es propiedad de la EMPRESA y no preferencia del usuario:
             * define cómo opera esa organización. Qué ve cada persona
             * dentro sigue dependiendo de sus sucursales y su rol, así
             * que el gerente que ve las cinco y el cajero que ve la
             * suya conviven con la misma arquitectura.
             */
            $table->enum('operation_mode', ['independent', 'consolidated'])
                ->default('independent');

            /**
             * Interruptor maestro de facturación electrónica.
             *
             * Hay empresas que no facturan electrónicamente, y otras en
             * proceso de habilitación. Sin esto habría que apagarlo
             * contrato a contrato, que es justo lo que no se quiere.
             *
             * NO basta por sí solo para emitir: la decisión final
             * cruza esto con el grupo del contrato y con el estado de
             * la configuración DIAN.
             */
            $table->boolean('electronic_invoicing_enabled')->default(false);

            $table->boolean('active')->default(true);
            $table->timestamps();

            // Dos empresas no pueden compartir identificación fiscal.
            // Si algún día hiciera falta separar operaciones bajo el
            // mismo NIT, esta restricción hay que quitarla ANTES de
            // tener datos.
            $table->unique(['document_type_code', 'document_number'], 'companies_document_unique');
            $table->index('active');
        });

        /**
         * Responsabilidades fiscales de la empresa.
         *
         * Son VARIAS por empresa (O-13, O-15, O-23, R-99-PN...), así
         * que una columna no basta. Va en tabla aparte y no en un JSON
         * porque hay que poder filtrar y validar por código.
         */
        Schema::create('company_tax_responsibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('responsibility_code', 10);
            $table->timestamps();

            $table->unique(['company_id', 'responsibility_code'], 'company_responsibility_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_tax_responsibilities');
        Schema::dropIfExists('companies');
    }
};
