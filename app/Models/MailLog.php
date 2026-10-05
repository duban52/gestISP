<?php

namespace App\Models;

use App\Billing\Concerns\NotAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * Un intento de envío de correo.
 *
 * POR QUÉ HACE FALTA
 * ------------------
 * Un correo que no llega no dejaba rastro en ninguna parte. El cliente
 * llamaba tres semanas después diciendo que nunca recibió la factura y
 * no había forma de saber si se envió, si rebotó, o si la contraseña
 * del SMTP llevaba un mes mal escrita. Las tres cosas se arreglan de
 * forma distinta y ninguna se podía distinguir.
 *
 * NO ES LA TRAZABILIDAD
 * ---------------------
 * `audits` registra lo que hacen las PERSONAS. Esto registra lo que
 * hace el servidor de correo, que es otra cosa y en otro volumen: una
 * corrida de facturación son mil filas de golpe. Por eso lleva
 * `NotAudited` —anotar cada correo en la auditoría la ahogaría— y por
 * eso se puede podar.
 */
class MailLog extends Model
{
    use NotAudited;

    public const ENVIADO = 'enviado';
    public const FALLIDO = 'fallido';

    /**
     * No se intentó porque el correo estaba APAGADO.
     *
     * Se anota igual, y es deliberado: al volver a encenderlo hay que
     * poder saber qué se quedó sin mandar mientras tanto.
     */
    public const OMITIDO = 'omitido';

    protected $fillable = [
        'status', 'to', 'subject', 'mailer', 'from_address',
        'context', 'error', 'company_id', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function etiquetaDeEstado(): string
    {
        return match ($this->status) {
            self::ENVIADO => 'Enviado',
            self::FALLIDO => 'Falló',
            self::OMITIDO => 'No se envió',
            default => $this->status,
        };
    }

    public function colorDeEstado(): string
    {
        return match ($this->status) {
            self::ENVIADO => 'success',
            self::FALLIDO => 'danger',
            self::OMITIDO => 'secondary',
            default => 'light',
        };
    }

    /**
     * El motivo del fallo, en algo que se pueda leer y corregir.
     *
     * «Expected response code 235 but got code 535, with message 535
     * 5.7.8 Username and Password not accepted» no le dice nada a
     * quien administra el sistema. Que la contraseña está mal, sí.
     *
     * El error completo NO se pierde: se guarda entero en `error` y se
     * enseña debajo, porque el de verdad es el único que sirve cuando
     * el motivo no es ninguno de los conocidos.
     */
    public function motivoEnClaro(): ?string
    {
        if (!$this->error) {
            return null;
        }

        $e = $this->error;

        return match (true) {
            str_contains($e, '535') || stripos($e, 'Username and Password') !== false
                => stripos($e, 'gmail') !== false || stripos($e, 'BadCredentials') !== false
                    ? 'Gmail rechazó el usuario o la contraseña. Tiene que ser una CONTRASEÑA DE APLICACIÓN '
                        . '(16 letras, sin espacios) creada en la misma cuenta del usuario, con la verificación '
                        . 'en dos pasos activada.'
                    : 'El servidor rechazó el usuario o la contraseña.',
            str_contains($e, '534') || stripos($e, 'application-specific password') !== false
                => 'Gmail exige una «contraseña de aplicación»: la del correo no sirve.',
            stripos($e, 'Connection could not be established') !== false
                || stripos($e, 'Connection refused') !== false
                || stripos($e, 'timed out') !== false
                => 'No se pudo conectar con el servidor: revise el host, el puerto y que el firewall lo deje salir.',
            str_contains($e, '550') || stripos($e, 'does not exist') !== false
                => 'El destinatario no existe o el servidor lo rechazó.',
            str_contains($e, '552') || stripos($e, 'quota') !== false
                => 'El buzón del destinatario está lleno.',
            str_contains($e, '554') || stripos($e, 'spam') !== false
                => 'El servidor lo marcó como correo no deseado. Revise SPF, DKIM y DMARC del dominio.',
            str_contains($e, '421') || stripos($e, 'too many') !== false
                || stripos($e, 'rate') !== false
                => 'Se superó el límite de envío del proveedor. Baje los correos por minuto.',
            stripos($e, 'certificate') !== false
                => 'Problema con el certificado del servidor: revise el tipo de cifrado y el puerto.',
            default => null,
        };
    }
}
