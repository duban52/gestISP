<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El bloque de facturación electrónica (fase 10).
 *
 * QUÉ ENTRA AQUÍ Y POR QUÉ SEPARADO DE `invoices`
 * -----------------------------------------------
 * Todo lo que solo existe para la DIAN: el CUFE, el XML firmado, el QR,
 * la resolución y el certificado usados, el ambiente y el estado.
 *
 * Va en tablas aparte a propósito. **Una factura interna no toca
 * ninguna de estas tablas, ni una columna.** Eso es exactamente lo que
 * evita que los documentos que no se reportan queden mezclados con los
 * que sí, y lo que permite mirar `electronic_documents` y saber que
 * todo lo que hay ahí es, sin excepción, lo que fue a la DIAN.
 *
 * LAS CUATRO TABLAS
 * -----------------
 *   · `dian_configurations` — 1:1 con la empresa. Ambiente, software
 *     autorizado y su PIN.
 *   · `dian_certificates`   — 1:N. El certificado de firma.
 *   · `dian_resolutions`    — la resolución de numeración autorizada.
 *   · `numbering_ranges`    — el prefijo y el rango de esa resolución.
 *   · `electronic_documents`— un documento emitido electrónicamente.
 *
 * LOS SECRETOS VAN CIFRADOS
 * -------------------------
 * El PIN del software y la contraseña del certificado son secretos
 * que, juntos con el certificado, permiten firmar en nombre del
 * contribuyente. Se guardan cifrados con la clave de la aplicación
 * —el cast `encrypted` de Eloquent— y NO en texto plano, porque un
 * volcado de la base no puede ser suficiente para firmar.
 *
 * Y por lo mismo: el archivo del certificado **no vive en la base**.
 * Se guarda su ruta, fuera del directorio público y fuera de la copia
 * de seguridad general.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Configuración DIAN de la empresa ----------
        Schema::create('dian_configurations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->unique()
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // '1' producción, '2' pruebas (catálogo TipoAmbiente).
            // Arranca en PRUEBAS: nadie emite en producción por
            // accidente. Pasar a producción es un acto deliberado.
            $table->string('environment_code', 2)->default('2');

            // El identificador del software autorizado por la DIAN y su
            // PIN. El PIN entra en el cálculo del código de seguridad
            // del software, así que es un secreto.
            $table->string('software_id', 60)->nullable();
            $table->text('software_pin')->nullable();

            // El identificador del set de pruebas, mientras se está en
            // habilitación.
            $table->string('test_set_id', 60)->nullable();

            // Cuándo se aprobó la habilitación. Mientras sea nulo, la
            // empresa no está habilitada por mucho que tenga los datos.
            $table->timestamp('enabled_at')->nullable();

            $table->timestamps();
        });

        // ---------- Certificados de firma ----------
        Schema::create('dian_certificates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('name', 120);

            // La RUTA, no el archivo. Fuera del directorio público.
            $table->string('path', 255);

            // Cifrada. Con ella y el archivo se puede firmar en nombre
            // del contribuyente.
            $table->text('password');

            // Para avisar antes de que caduque: un certificado vencido
            // detiene la facturación de golpe.
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'active'], 'dian_certificates_company_active_index');
        });

        // ---------- Resoluciones de numeración ----------
        Schema::create('dian_resolutions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('resolution_number', 40);

            // '01' factura de venta, '91' nota crédito, '92' nota débito.
            $table->string('document_type_code', 5)->default('01');

            $table->date('valid_from');
            $table->date('valid_until');

            // La CLAVE TÉCNICA. Entra en el cálculo del CUFE y no viaja
            // en ningún XML: es el secreto que hace que un CUFE no se
            // pueda falsificar conociendo solo la factura. Cifrada.
            $table->text('technical_key')->nullable();

            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'resolution_number', 'document_type_code'], 'dian_resolutions_unique');
        });

        // ---------- Rangos autorizados ----------
        Schema::create('numbering_ranges', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dian_resolution_id')
                ->constrained('dian_resolutions')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Nulo = el rango es de toda la empresa. Con valor, de una
            // sede. La resolución la da la DIAN al NIT, pero se puede
            // repartir por sucursal.
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('prefix', 10);
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('current_number')->default(0);

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['dian_resolution_id', 'active'], 'numbering_ranges_resolution_active_index');
            $table->index(['branch_id', 'active'], 'numbering_ranges_branch_active_index');
        });

        // El índice crítico del plan: **dos rangos activos del mismo
        // NIT no pueden compartir prefijo**. Sin él, dos sucursales
        // podrían usar el mismo y producir consecutivos duplicados ante
        // la DIAN aunque la base los viera como filas distintas.
        //
        // company_id no está en esta tabla —cuelga de la resolución—,
        // así que se desnormaliza en una columna generada… que no puede
        // serlo, porque depende de otra tabla. Se guarda como columna
        // normal y la mantiene el modelo al guardar.
        Schema::table('numbering_ranges', function (Blueprint $table) {
            $table->foreignId('company_id')
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });

        Schema::table('numbering_ranges', function (Blueprint $table) {
            // Igual que en las otras tablas con «uno activo por
            // combinación»: la columna generada vale cuando está activo
            // y NULL cuando no, y el UNIQUE deja pasar tantos
            // inactivos como haga falta.
            $table->string('active_prefix_key', 40)
                ->nullable()
                ->storedAs("IF(active = 1, CONCAT(company_id, '-', prefix), NULL)");

            $table->unique('active_prefix_key', 'numbering_ranges_one_active_prefix');
        });

        // ---------- El documento electrónico ----------
        Schema::create('electronic_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Una factura tiene como mucho UN documento electrónico.
            $table->foreignId('invoice_id')
                ->nullable()
                ->unique()
                ->constrained('invoices')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Con qué se emitió. Se guarda el id y no se deduce: si la
            // resolución cambia mañana, este documento tiene que seguir
            // diciendo con cuál salió.
            $table->foreignId('dian_resolution_id')
                ->nullable()
                ->constrained('dian_resolutions')
                ->nullOnDelete();

            $table->foreignId('dian_certificate_id')
                ->nullable()
                ->constrained('dian_certificates')
                ->nullOnDelete();

            // El ambiente en el que se emitió, congelado: una factura
            // emitida en pruebas no puede parecer de producción porque
            // la empresa haya cambiado de ambiente después.
            $table->string('environment_code', 2);

            // El CUFE: SHA-384 de la factura más la clave técnica.
            $table->string('cufe', 96)->nullable();

            // El XML firmado y el QR. El XML se guarda porque es el
            // documento: lo que vale ante la DIAN es lo que se envió,
            // no lo que se pueda regenerar después.
            $table->longText('signed_xml')->nullable();
            $table->text('qr_content')->nullable();

            // Dónde está en su ciclo de vida.
            $table->string('status', 20)->default('draft');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'electronic_documents_company_status_index');
            $table->index('cufe', 'electronic_documents_cufe_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
        Schema::dropIfExists('numbering_ranges');
        Schema::dropIfExists('dian_resolutions');
        Schema::dropIfExists('dian_certificates');
        Schema::dropIfExists('dian_configurations');
    }
};
