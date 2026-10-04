<?php

namespace App\Models;

use App\Billing\Concerns\NotAudited;
use Illuminate\Database\Eloquent\Model;

/**
 * Cómo manda correo el sistema.
 *
 * UNA SOLA FILA. Es configuración de la instalación, no de una
 * empresa ni de una sucursal: por eso vive en Gestión del sistema y
 * la toca el superadministrador. (El envío por empresa está previsto
 * y es la continuación natural de esto; cuando llegue, esta fila
 * seguirá siendo el valor por defecto de quien no configure el suyo.)
 *
 * EL .env SIGUE MANDANDO MIENTRAS AQUÍ NO HAYA NADA
 * -------------------------------------------------
 * `host` vacío significa «usa lo de siempre». Así el día del
 * despliegue no cambia nada: el correo sale igual que ayer hasta que
 * alguien entre, lo configure y lo pruebe.
 */
class MailSetting extends Model
{
    use NotAudited;

    protected $fillable = [
        'enabled', 'preset',
        'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'per_minute', 'updated_by',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'port' => 'integer',
        'per_minute' => 'integer',
        // Cifrada en reposo, como el PIN de la DIAN. Nunca se enseña
        // en la pantalla ni se escribe en el log.
        'password' => 'encrypted',
    ];

    /**
     * La configuración vigente. Si no existe, una recién nacida con
     * el correo ENCENDIDO: la ausencia de configuración no puede
     * apagar el correo de un sistema que estaba funcionando.
     */
    public static function vigente(): self
    {
        return static::first() ?? new static(['enabled' => true]);
    }

    /** ¿Hay un SMTP propio, o se usa el del .env? */
    public function tieneServidorPropio(): bool
    {
        return filled($this->host);
    }

    /**
     * Los ajustes de Laravel que hay que sobreescribir.
     *
     * Solo lo que esté relleno: un campo en blanco es «deja el del
     * .env», no «ponlo vacío». Es la diferencia entre completar una
     * configuración y romperla a medias.
     *
     * @return array<string, mixed>
     */
    public function comoConfigDeLaravel(): array
    {
        $ajustes = [];

        foreach ([
            'mail.mailers.smtp.host' => $this->host,
            'mail.mailers.smtp.port' => $this->port,
            'mail.mailers.smtp.username' => $this->username,
            'mail.mailers.smtp.password' => $this->password,
            'mail.from.address' => $this->from_address,
            'mail.from.name' => $this->from_name,
        ] as $clave => $valor) {
            if (filled($valor)) {
                $ajustes[$clave] = $valor;
            }
        }

        // El cifrado SÍ admite el vacío: «ninguno» es una opción
        // válida y hay servidores que la exigen.
        if ($this->tieneServidorPropio()) {
            $ajustes['mail.mailers.smtp.encryption'] = $this->encryption ?: null;
        }

        return $ajustes;
    }

    /** Preajustes de los proveedores habituales. */
    public const PREAJUSTES = [
        'ses' => [
            'etiqueta' => 'Amazon SES',
            'host' => 'email-smtp.us-east-1.amazonaws.com',
            'port' => 587,
            'encryption' => 'tls',
            'nota' => 'Sin tope diario una vez fuera del sandbox. Cambie la región del host si su cuenta no es us-east-1.',
        ],
        'brevo' => [
            'etiqueta' => 'Brevo',
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'encryption' => 'tls',
            'nota' => 'El plan gratuito son 300 correos al día: una corrida de facturación grande no cabe.',
        ],
        'gmail' => [
            'etiqueta' => 'Gmail / Google Workspace',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'nota' => 'Entre 500 y 2.000 correos al día, y reescribe el remitente si no es un alias verificado. '
                . 'Sirve para empezar; no para una corrida de mil facturas.',
        ],
        'otro' => [
            'etiqueta' => 'Otro servidor',
            'host' => null,
            'port' => 587,
            'encryption' => 'tls',
            'nota' => 'Pida a su proveedor el servidor, el puerto y el tipo de cifrado.',
        ],
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
