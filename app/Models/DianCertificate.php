<?php

namespace App\Models;

use App\Billing\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Certificado de firma de una empresa.
 *
 * EL ARCHIVO NO VIVE EN LA BASE
 * -----------------------------
 * Se guarda su RUTA, fuera del directorio publico. Con el archivo y la
 * contraseña se puede firmar en nombre del contribuyente, asi que
 * meterlo en la base lo pondria en cada copia de seguridad.
 */
class DianCertificate extends Model
{
    // Quien carga o retira un certificado de firma cambia quien puede
    // firmar en nombre del contribuyente. Eso tiene que quedar anotado.
    use Auditable;

    protected $fillable = [
        'company_id', 'name', 'path', 'password',
        'valid_from', 'valid_until', 'active', 'self_signed',
    ];

    protected $casts = [
        'password' => 'encrypted',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'active' => 'boolean',
        'self_signed' => 'boolean',
    ];

    protected $attributes = ['active' => true];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * ¿La DIAN lo va a aceptar?
     *
     * Un certificado autofirmado firma igual de bien —la mecánica es
     * la misma— pero la DIAN lo rechaza: exige que encadene contra una
     * entidad acreditada por la ONAC. Sirve para probar el flujo
     * completo, no para emitir.
     */
    public function sirveParaLaDian(): bool
    {
        return !$this->self_signed;
    }

    /**
     * El .p12 en binario.
     *
     * El certificado se guarda como ARCHIVO fuera del directorio
     * publico y solo su ruta va en la base: es una clave privada, y
     * meterla en una columna la mete tambien en cada copia de seguridad
     * y en cada volcado que alguien haga.
     *
     * La ruta se admite absoluta o relativa a `storage/app`, porque en
     * el servidor se configuro de las dos formas.
     *
     * @throws RuntimeException si el archivo no esta donde dice la base.
     */
    public function contenido(): string
    {
        $ruta = (string) $this->path;

        if (!is_file($ruta)) {
            $ruta = storage_path('app/' . ltrim($ruta, '/'));
        }

        if (!is_file($ruta)) {
            throw new RuntimeException(sprintf(
                'El certificado «%s» no esta en su ruta (%s): no se puede firmar.',
                $this->name,
                $this->path,
            ));
        }

        return (string) file_get_contents($ruta);
    }

    /** ¿Sirve hoy? */
    public function vigente(): bool
    {
        if (!$this->active) {
            return false;
        }

        $hoy = now()->startOfDay();

        return (!$this->valid_from || $this->valid_from->lte($hoy))
            && (!$this->valid_until || $this->valid_until->gte($hoy));
    }

    /**
     * Dias que quedan antes de que caduque.
     *
     * Un certificado vencido detiene la facturacion de golpe, asi que
     * hay que poder avisar ANTES.
     */
    public function diasParaCaducar(): ?int
    {
        return $this->valid_until
            ? (int) now()->startOfDay()->diffInDays($this->valid_until, false)
            : null;
    }
}
