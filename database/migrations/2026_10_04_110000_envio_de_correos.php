<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El envío de correos: cómo se manda y qué salió de verdad.
 *
 * POR QUÉ SALE DEL .env
 * ---------------------
 * Hasta ahora el SMTP vivía en el `.env` del servidor. Eso significa
 * que cambiar de proveedor —o corregir una contraseña— exige entrar
 * por SSH, editar un archivo y reiniciar PHP. Quien administra el
 * sistema no tiene por qué tener acceso al servidor, y el día que el
 * correo deja de salir no puede ni mirar por qué.
 *
 * El `.env` NO desaparece: sigue siendo el valor por defecto. Mientras
 * no se guarde nada aquí, todo envía exactamente como hoy.
 *
 * LA BITÁCORA ES LO QUE DE VERDAD FALTABA
 * ---------------------------------------
 * Un correo que no llega no deja rastro en ningún sitio: el cliente
 * llama tres semanas después diciendo que nunca recibió la factura y
 * no hay forma de saber si se envió, si rebotó o si la contraseña del
 * SMTP llevaba un mes mal. Cada intento queda aquí con su destinatario,
 * su asunto y —si falló— el motivo que devolvió el servidor.
 *
 * EL INTERRUPTOR
 * --------------
 * `enabled` apaga TODO el correo saliente. Sirve para una migración de
 * proveedor, para una prueba de carga o para el día que se descubre
 * que la corrida de facturación está mandando lo que no debe. Apagarlo
 * no pierde nada: los intentos se siguen anotando como omitidos, así
 * que al volver a encenderlo se sabe qué no salió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();

            // El interruptor general del correo saliente.
            $table->boolean('enabled')->default(true);

            // Preajuste elegido (ses, brevo, gmail, otro): solo sirve
            // para que la pantalla sepa qué ayuda enseñar.
            $table->string('preset', 20)->nullable();

            $table->string('host', 150)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            // tls, ssl o ninguno
            $table->string('encryption', 10)->nullable();
            $table->string('username', 190)->nullable();
            // Cifrada en reposo, igual que el PIN de la DIAN y la
            // contraseña del certificado (ver el cast del modelo).
            $table->text('password')->nullable();

            $table->string('from_address', 190)->nullable();
            $table->string('from_name', 150)->nullable();

            // Cuántos correos por minuto como mucho. Una corrida de mil
            // facturas sale de golpe, y todos los proveedores tienen un
            // límite por segundo: sin freno, la mitad se rechaza.
            $table->unsignedSmallInteger('per_minute')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();

            // enviado | fallido | omitido
            $table->string('status', 20)->index();

            $table->string('to', 190)->index();
            $table->string('subject', 255)->nullable();
            // Con qué se mandó: ayuda a leer la bitácora de una
            // migración de proveedor, donde conviven los dos.
            $table->string('mailer', 40)->nullable();
            $table->string('from_address', 190)->nullable();

            // Qué lo provocó, cuando se puede saber: la clase de la
            // notificación («InvoiceGenerated») dice más que el asunto.
            $table->string('context', 120)->nullable();

            // El motivo del servidor, tal cual. Es lo único que permite
            // distinguir «contraseña mal» de «buzón lleno» de «dominio
            // inexistente», y cada uno se arregla de forma distinta.
            $table->text('error')->nullable();

            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('mail_settings');
    }
};
