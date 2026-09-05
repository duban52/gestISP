<?php

namespace App\Billing\Enums;

/**
 * Cómo trata el IVA un servicio.
 *
 * NO SON TRES MATICES DE LO MISMO
 * -------------------------------
 * · **Gravado** — sujeto a IVA, a su tarifa.
 * · **Excluido** — la ley dice que ese servicio NO está sujeto. No
 *   causa IVA, y el vendedor **no puede descontar** el IVA de sus
 *   compras asociadas: se le vuelve costo.
 * · **Exento** — sí está sujeto, pero a tarifa **0%**. El vendedor
 *   **sí puede** descontarlo, e incluso pedir devolución.
 *
 * La diferencia entre excluido y exento no es cosmética: es si la
 * empresa recupera o no el IVA que pagó. Para un ISP eso es dinero.
 *
 * Y EN EL XML SON ESTRUCTURAS DISTINTAS
 * -------------------------------------
 * Verificado en los ejemplos oficiales de la DIAN:
 *
 * · Excluido → el documento **no lleva `cac:TaxTotal`**, ni en la línea
 *   ni en el total. Simplemente no existe el bloque de impuestos.
 * · Exento → **sí lo lleva**, con `TaxAmount` 0.00, la base en
 *   `TaxableAmount` y `Percent` 0.00.
 *
 * Decir «cero impuesto» y «no hay impuesto» son cosas diferentes para
 * la DIAN.
 *
 * POR QUÉ ESTO ES UN DATO Y NO UN PORCENTAJE EN CERO
 * --------------------------------------------------
 * Antes la distinción vivía implícita en `tax_percentage = 0`, y ese
 * cero significaba tres cosas que el sistema no podía diferenciar:
 * excluido, exento, o que a alguien se le olvidó poner la tarifa. Con
 * la clasificación explícita, un servicio al 0% dice **por qué** lo
 * está.
 */
enum TaxClassification: string
{
    case Gravado = 'gravado';
    case Excluido = 'excluido';
    case Exento = 'exento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Gravado => 'Gravado',
            self::Excluido => 'Excluido de IVA',
            self::Exento => 'Exento de IVA (tarifa 0%)',
        };
    }

    public function explicacion(): string
    {
        return match ($this) {
            self::Gravado => 'Se le cobra IVA a su tarifa.',
            self::Excluido => 'La ley no lo sujeta a IVA. El XML no lleva bloque de impuestos, '
                . 'y el IVA de las compras asociadas no se puede descontar.',
            self::Exento => 'Sujeto a IVA pero a tarifa 0%. El XML sí lleva el bloque, en ceros, '
                . 'y el IVA de las compras sí se puede descontar.',
        };
    }

    /** ¿Lleva bloque de impuestos en el XML? */
    public function llevaBloqueDeImpuestos(): bool
    {
        return $this !== self::Excluido;
    }

    /** ¿Puede tener una tarifa distinta de cero? */
    public function admiteTarifa(): bool
    {
        return $this === self::Gravado;
    }

    /** @return array<string, string> para los desplegables */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->value] = $caso->etiqueta();
        }

        return $opciones;
    }
}
