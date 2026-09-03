<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuracion DIAN de una empresa.
 *
 * ARRANCA EN PRUEBAS
 * ------------------
 * `environment_code` nace en '2' (pruebas). Nadie emite en produccion
 * por accidente: pasar a produccion es un acto deliberado, y ademas
 * exige que la habilitacion este aprobada (`enabled_at`).
 *
 * EL PIN VA CIFRADO
 * -----------------
 * Entra en el calculo del codigo de seguridad del software. Con el y
 * el certificado se puede firmar en nombre del contribuyente, asi que
 * un volcado de la base no puede ser suficiente para hacerlo.
 */
class DianConfiguration extends Model
{
    /** Catalogo TipoAmbiente. */
    public const PRODUCCION = '1';
    public const PRUEBAS = '2';

    protected $fillable = [
        'company_id', 'environment_code', 'software_id', 'software_pin',
        'test_set_id', 'enabled_at',
    ];

    protected $casts = [
        // Cifrado con la clave de la aplicacion.
        'software_pin' => 'encrypted',
        'enabled_at' => 'datetime',
    ];

    protected $attributes = [
        'environment_code' => self::PRUEBAS,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * ¿Esta empresa puede emitir en produccion?
     *
     * Hacen falta las DOS cosas: estar en ambiente de produccion y
     * tener la habilitacion aprobada. Solo el ambiente no basta —
     * alguien podria cambiarlo sin haber pasado el set de pruebas.
     *
     * Es la tercera condicion que ElectronicInvoicingDecider tenia
     * marcada como pendiente desde la fase 9.
     */
    public function estaEnProduccion(): bool
    {
        return $this->environment_code === self::PRODUCCION
            && $this->enabled_at !== null;
    }

    public function ambienteLegible(): string
    {
        return $this->environment_code === self::PRODUCCION ? 'Producción' : 'Pruebas';
    }
}
