<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La contraseña del SMTP deja de guardarse cifrada.
 *
 * POR QUÉ
 * -------
 * Decisión del dueño del sistema, pedida de forma explícita. Lo que
 * se gana es poder leerla y corregirla directamente en la base de
 * datos cuando el correo no sale y hay prisa.
 *
 * LO QUE CUESTA, PARA QUE CONSTE
 * ------------------------------
 * Cualquiera con acceso a la base —o a una copia de seguridad, que se
 * descarga desde el propio panel— lee la contraseña del servidor de
 * correo. Si esa cuenta es la que manda las facturas, quien la tenga
 * puede escribirles a los clientes en nombre de la empresa.
 *
 * El resto de credenciales del sistema (el PIN de la DIAN, la
 * contraseña del certificado) siguen cifradas.
 *
 * QUÉ HACE ESTA MIGRACIÓN
 * -----------------------
 * Descifra lo que ya estaba guardado. Sin esto, al quitar el cast la
 * aplicación le mandaría al servidor de correo la cadena
 * «eyJpdiI6...» tal cual, y entonces sí que no autenticaría nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fila = DB::table('mail_settings')->first();

        if (!$fila || blank($fila->password)) {
            return;
        }

        try {
            DB::table('mail_settings')
                ->where('id', $fila->id)
                ->update(['password' => Crypt::decryptString($fila->password)]);
        } catch (\Throwable $e) {
            // Ya estaba en claro, o se guardó con otra APP_KEY. En
            // ninguno de los dos casos hay que tocarla: si se vuelve a
            // guardar desde la pantalla queda bien.
        }
    }

    public function down(): void
    {
        $fila = DB::table('mail_settings')->first();

        if (!$fila || blank($fila->password)) {
            return;
        }

        DB::table('mail_settings')
            ->where('id', $fila->id)
            ->update(['password' => Crypt::encryptString($fila->password)]);
    }
};
