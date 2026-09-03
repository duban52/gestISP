<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un documento emitido electronicamente.
 *
 * SOLO ENTRA AQUI LO QUE VA A LA DIAN
 * -----------------------------------
 * Una factura interna no toca esta tabla, ni una columna. Eso es lo
 * que permite mirarla y saber que todo lo que hay dentro es, sin
 * excepcion, lo que se reporto.
 *
 * SE GUARDA EL XML, NO SE REGENERA
 * --------------------------------
 * Lo que vale ante la DIAN es lo que se envio, no lo que se pueda
 * volver a construir despues. Si manana cambia una plantilla, un
 * catalogo o un dato del cliente, el documento emitido tiene que
 * seguir siendo el que fue.
 *
 * Por lo mismo se guardan el id de la resolucion, el del certificado y
 * el ambiente: son con lo que se emitio, no con lo que la empresa
 * tenga configurado hoy.
 */
class ElectronicDocument extends Model
{
    use BelongsToCompany;

    /** Estados del ciclo de vida. */
    public const BORRADOR = 'draft';
    public const GENERADO = 'generated';
    public const FIRMADO = 'signed';
    public const ENVIADO = 'sent';
    public const ACEPTADO = 'accepted';
    public const RECHAZADO = 'rejected';

    protected $fillable = [
        'company_id', 'invoice_id', 'dian_resolution_id', 'dian_certificate_id',
        'environment_code', 'cufe', 'signed_xml', 'qr_content',
        'status', 'last_error', 'generated_at', 'signed_at',
        'dian_track_id', 'accepted_at', 'attempts',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'signed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'attempts' => 'integer',
    ];

    protected $attributes = ['status' => self::BORRADOR];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(DianResolution::class, 'dian_resolution_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(DianCertificate::class, 'dian_certificate_id');
    }

    /** Los intentos de transmision, del mas reciente al mas antiguo. */
    public function transmissions()
    {
        return $this->hasMany(DocumentTransmission::class)->orderByDesc('attempt');
    }

    /**
     * Ya no hay nada mas que hacer con este documento.
     *
     * Aceptado o rechazado: los dos son definitivos. Reintentar un
     * documento que la DIAN rechazo por su contenido da el mismo
     * rechazo; lo que hay que hacer es corregir y emitir otro.
     */
    public function estaCerrado(): bool
    {
        return in_array($this->status, [self::ACEPTADO, self::RECHAZADO], true);
    }

    /** ¿Esta listo para mandarse? */
    public function sePuedeTransmitir(): bool
    {
        return $this->status === self::FIRMADO && !empty($this->signed_xml);
    }

    /** ¿Se emitio en pruebas? */
    public function esDePruebas(): bool
    {
        return $this->environment_code === DianConfiguration::PRUEBAS;
    }

    public function estadoLegible(): string
    {
        return match ($this->status) {
            self::BORRADOR => 'Borrador',
            self::GENERADO => 'Generado',
            self::FIRMADO => 'Firmado',
            self::ENVIADO => 'Enviado',
            self::ACEPTADO => 'Aceptado',
            self::RECHAZADO => 'Rechazado',
            default => $this->status,
        };
    }
}
