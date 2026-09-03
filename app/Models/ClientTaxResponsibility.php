<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una responsabilidad fiscal del cliente.
 *
 * Los codigos salen del catalogo `responsabilidad_fiscal`
 * (TipoResponsabilidad.gc). Un cliente puede tener varias y el XML las
 * lista una a una, de ahi la tabla en vez de una columna con
 * separadores.
 *
 * NO lleva el trait de empresa: cuelga del cliente, que ya lo lleva.
 */
class ClientTaxResponsibility extends Model
{
    protected $fillable = ['client_id', 'responsibility_code'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** El nombre legible del codigo, del catalogo. */
    public function nombre(): ?string
    {
        return FiscalCatalog::nombre(FiscalCatalog::RESPONSABILIDAD, $this->responsibility_code);
    }
}
