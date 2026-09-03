<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datos fiscales de clientes y servicios (fase 8 de multiempresa).
 *
 * QUÉ FALTABA
 * -----------
 * Del cliente, todo lo que el XML de una factura electrónica exige y
 * que hoy no existe en ninguna parte: el CÓDIGO del tipo de documento
 * —hoy es texto libre—, el dígito de verificación, el tipo de
 * organización jurídica, la dirección fiscal y los códigos DANE.
 *
 * Del servicio, el código de producto: la DIAN pide un UNSPSC o un
 * código interno declarado como tal.
 *
 * LA DIRECCIÓN FISCAL ES OTRA COSA QUE LA DEL CONTRATO
 * ----------------------------------------------------
 * Hoy la única dirección vive en el contrato, y es la del SERVICIO —
 * dónde está instalada la antena. La fiscal es dónde recibe
 * correspondencia el contribuyente, y no tienen por qué coincidir: un
 * cliente con tres contratos tiene tres direcciones de servicio y una
 * sola fiscal.
 *
 * TODO NACE VACÍO Y NULABLE
 * -------------------------
 * Son datos que hoy nadie tiene. Exigirlos ahora dejaría sin poder
 * guardar un cliente a quien solo quiere darlo de alta para
 * instalarle internet, que es el 100% de los casos hasta que la
 * facturación electrónica esté encendida.
 *
 * Lo que sí hay es un INFORME DE COMPLETITUD que dice qué falta, para
 * poder ir rellenándolo antes de que haga falta de verdad.
 *
 * `type_document` SE CONSERVA
 * ---------------------------
 * El texto libre se queda como red de seguridad durante la
 * transición, igual que `branches.nit`. La migración rellena el
 * código a partir de él, y lo que no sepa traducir lo deja nulo para
 * que salga en el informe — en vez de inventarse un código, que es lo
 * que no se puede hacer con un dato fiscal.
 */
return new class extends Migration
{
    /**
     * Del texto libre de hoy al código de la DIAN.
     *
     * Las claves son EXACTAMENTE lo que hay guardado, con sus erratas
     * incluidas: el formulario ofrecía «Cédula de extrangería».
     *
     * «Persona Jurídica» merece explicación: el formulario tenía
     * `<option value="Persona Jurídica">Pasaporte</option>`, es decir,
     * quien elegía «Pasaporte» guardaba «Persona Jurídica». Como no se
     * puede saber cuál de las dos cosas quiso decir, NO se traduce: se
     * deja nulo y sale en el informe de completitud para que alguien
     * lo decida mirando el cliente.
     */
    private const MAPA_DOCUMENTO = [
        'Cédula de ciudadanía' => '13',
        'Cedula de ciudadania' => '13',
        'Cédula de extrangería' => '22',
        'Cédula de extranjería' => '22',
        'Cedula de extranjeria' => '22',
        'NIT' => '31',
        'Pasaporte' => '41',
        'Tarjeta de identidad' => '12',
        'Registro civil' => '11',
    ];

    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // ---- Identificación fiscal ----
            $table->string('document_type_code', 5)->nullable()->after('type_document');
            $table->string('verification_digit', 1)->nullable()->after('identity_number');
            $table->string('organization_type_code', 5)->nullable()->after('type_client');

            // ---- Dirección fiscal ----
            $table->string('fiscal_address', 255)->nullable()->after('email');
            $table->string('department_dane_code', 5)->nullable()->after('fiscal_address');
            $table->string('municipality_dane_code', 5)->nullable()->after('department_dane_code');
            $table->string('postal_code', 10)->nullable()->after('municipality_dane_code');
            $table->string('country_code', 5)->nullable()->after('postal_code');

            $table->index('document_type_code', 'clients_document_type_index');
        });

        // Responsabilidades fiscales del cliente.
        //
        // Tabla aparte y no una columna con comas: un cliente puede
        // tener varias, el XML las lista una a una, y una columna de
        // texto con separadores es lo que hace que dentro de un año
        // nadie sepa si el separador era coma o punto y coma.
        //
        // Es la misma forma que ya tiene `company_tax_responsibilities`.
        Schema::create('client_tax_responsibilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')
                ->constrained('clients')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('responsibility_code', 10);

            $table->timestamps();

            $table->unique(['client_id', 'responsibility_code'], 'client_tax_resp_unique');
        });

        Schema::table('services', function (Blueprint $table) {
            // Código de producto. La DIAN admite el estándar UNSPSC o
            // uno interno, pero hay que DECIR cuál se está usando: de
            // eso se encarga product_code_type.
            $table->string('product_code', 40)->nullable()->after('name');
            $table->string('product_code_type', 5)->nullable()->after('product_code');

            // Unidad de medida y tipo de impuesto, de sus catálogos.
            $table->string('unit_measure_code', 10)->nullable()->after('product_code_type');
            $table->string('tax_code', 5)->nullable()->after('tax_percentage');
        });

        $this->traducirTiposDeDocumento();
    }

    /**
     * Rellena el código a partir del texto libre que ya existe.
     *
     * Lo que no sepa traducir se queda nulo. Inventarse un código
     * fiscal es peor que no tenerlo: el informe de completitud lo
     * señala y alguien lo decide mirando el cliente.
     */
    private function traducirTiposDeDocumento(): void
    {
        foreach (self::MAPA_DOCUMENTO as $texto => $codigo) {
            DB::table('clients')
                ->where('type_document', $texto)
                ->whereNull('document_type_code')
                ->update(['document_type_code' => $codigo]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_tax_responsibilities');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_document_type_index');
            $table->dropColumn([
                'document_type_code', 'verification_digit', 'organization_type_code',
                'fiscal_address', 'department_dane_code', 'municipality_dane_code',
                'postal_code', 'country_code',
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['product_code', 'product_code_type', 'unit_measure_code', 'tax_code']);
        });
    }
};
