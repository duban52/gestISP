<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos fiscales (fase 8 de multiempresa).
 *
 * QUÉ GUARDA
 * ----------
 * Las listas de códigos que publica la DIAN en su paquete de
 * *genericode*: tipos de documento de identidad, tipos de
 * organización, responsabilidades fiscales, formas y medios de pago,
 * tarifas de IVA, unidades de medida, monedas… y los códigos DANE de
 * departamentos y municipios, que viajan en el mismo paquete.
 *
 * POR QUÉ TABLA Y NO ENUMS EN PHP
 * -------------------------------
 * Cambian por resolución. Un código nuevo no puede exigir un
 * despliegue de código: son datos, no lógica.
 *
 * POR QUÉ UNA SOLA TABLA Y NO DOCE
 * --------------------------------
 * El plan hablaba de «~12 tablas». Una sola con un discriminador hace
 * exactamente lo mismo con mucho menos andamiaje: son todas listas de
 * código + nombre, sin comportamiento propio y sin claves foráneas
 * entre ellas —los códigos se guardan como texto en cada documento,
 * que es como los pide el XML—.
 *
 * Y tiene una ventaja concreta: **añadir un catálogo nuevo no pide
 * migración**. Se importa el archivo y ya está. Con doce tablas, cada
 * lista nueva serían una migración, un modelo y un seeder.
 *
 * LOS MUNICIPIOS CUELGAN DE SU DEPARTAMENTO
 * -----------------------------------------
 * `parent_code` lo resuelve sin una tabla aparte. En el catálogo DANE
 * el municipio ya lleva dentro su departamento —05001 es Medellín, y
 * 05 es Antioquia—, así que se extrae al importar y queda explícito en
 * vez de tener que recortar la cadena en cada consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_catalogs', function (Blueprint $table) {
            $table->id();

            // Qué lista es: 'tipo_documento', 'municipio', 'medio_pago'…
            $table->string('catalog', 60);

            // El código tal y como lo espera el XML. Texto, no entero:
            // los del DANE llevan ceros a la izquierda (05001) y
            // perderlos los rompe.
            $table->string('code', 20);

            $table->string('name', 255);

            // Para las listas jerárquicas. Hoy solo municipio →
            // departamento.
            $table->string('parent_code', 20)->nullable();

            // Lo que traiga el archivo y no encaje en código + nombre.
            // Algunas listas tienen columnas extra que hoy no se usan
            // pero conviene no tirar.
            $table->json('extra')->nullable();

            // Un código retirado por resolución deja de ofrecerse, pero
            // NO se borra: los documentos ya emitidos lo llevan, y
            // borrarlo dejaría sin nombre a lo que ya se emitió.
            $table->boolean('active')->default(true);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['catalog', 'code'], 'fiscal_catalogs_catalog_code_unique');
            $table->index(['catalog', 'active'], 'fiscal_catalogs_catalog_active_index');
            $table->index(['catalog', 'parent_code'], 'fiscal_catalogs_catalog_parent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_catalogs');
    }
};
