<?php

namespace App\Billing\Enums;

/**
 * Tipos de retención que un cliente puede practicar al pagar.
 *
 * QUÉ ES UNA RETENCIÓN Y POR QUÉ APARECE EN UN COBRO
 * ---------------------------------------------------
 * Cuando el cliente es "agente de retención" (grandes contribuyentes,
 * entidades públicas, la mayoría de personas jurídicas), la ley lo
 * obliga a NO entregarnos el 100% de la factura: descuenta un
 * porcentaje y lo consigna directamente a la DIAN o al municipio a
 * nombre nuestro.
 *
 * Es decir: la retención NO es un descuento ni una rebaja. La factura
 * se paga completa; lo que cambia es quién recibe cada parte:
 *
 *     Factura ................... $1.190.000
 *     - Retención renta 4% ......    $40.000  → la paga el cliente a la DIAN por nosotros
 *     - ReteIVA 15% .............    $28.500  → idem
 *     - ReteICA 6x1000 ..........     $6.000  → idem, al municipio
 *     = Efectivo que entra a caja  $1.115.500
 *
 * Consecuencias que el sistema debe respetar:
 *
 *  1. La factura queda SALDADA con efectivo + retenciones. Si solo se
 *     contara el efectivo, el cliente quedaría debiendo un dinero que
 *     ya pagó (al Estado) y se le suspendería el servicio sin razón.
 *  2. A la CAJA solo entra el efectivo. La retención no es plata en
 *     el cajón, así que no puede aparecer en el cuadre.
 *  3. Ese dinero retenido es un ANTICIPO DE IMPUESTO nuestro: al
 *     declarar renta/IVA/ICA se descuenta de lo que debemos pagar.
 *     Por eso hay que poder listarlo y totalizarlo (ver el reporte de
 *     retenciones), y por eso se guarda el número del certificado que
 *     el cliente está obligado a expedirnos.
 *
 * SOBRE LAS TARIFAS
 * -----------------
 * Las tarifas que trae cada concepto son las de referencia y sirven
 * para que el cajero no tenga que memorizarlas, pero SIEMPRE son
 * editables: cambian con cada reforma tributaria, dependen de si el
 * beneficiario es declarante y, en el caso del ICA, las fija cada
 * municipio en su acuerdo. Quien manda es el certificado del cliente.
 */
enum RetentionType: string
{
    /** Retención en la fuente a título de renta ("retefuente"). */
    case Renta = 'renta';

    /** Retención de IVA ("reteIVA"), Art. 437-2 del Estatuto Tributario. */
    case Iva = 'iva';

    /** Retención de industria y comercio ("reteICA"), de orden municipal. */
    case Ica = 'ica';

    /** Retención de timbre nacional. */
    case Timbre = 'timbre';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Renta => 'Retención en la fuente (renta)',
            self::Iva => 'Retención de IVA',
            self::Ica => 'Retención de ICA',
            self::Timbre => 'Retención de timbre',
        };
    }

    /** Nombre corto, para el recibo y las tablas. */
    public function abreviatura(): string
    {
        return match ($this) {
            self::Renta => 'RteFuente',
            self::Iva => 'RteIVA',
            self::Ica => 'RteICA',
            self::Timbre => 'RteTimbre',
        };
    }

    /**
     * Sobre qué valor de la factura se calcula habitualmente.
     *
     * Es una AYUDA para precargar la base en el formulario, no una
     * regla: el cliente puede retener sobre otra base (por ejemplo
     * solo sobre parte de los conceptos facturados).
     */
    public function baseSugerida(): string
    {
        return match ($this) {
            // Renta e ICA se calculan sobre el valor del servicio
            // antes de impuestos.
            self::Renta, self::Ica, self::Timbre => 'subtotal',
            // El reteIVA se calcula sobre el IVA facturado, no sobre
            // el servicio: es un porcentaje del impuesto.
            self::Iva => 'iva',
        };
    }

    /** Explicación de la base, para mostrarla en el formulario. */
    public function explicacionBase(): string
    {
        return match ($this) {
            self::Renta => 'Se calcula sobre el valor del servicio antes de IVA.',
            self::Iva => 'Se calcula sobre el IVA facturado, no sobre el servicio.',
            self::Ica => 'Se calcula sobre los ingresos brutos (valor del servicio).',
            self::Timbre => 'Se calcula sobre el valor del documento.',
        };
    }

    /**
     * Conceptos de referencia con su tarifa habitual, en porcentaje.
     *
     * Los de renta corresponden a los conceptos del Decreto 1625 de
     * 2016 (Decreto Único Reglamentario en materia tributaria) que
     * puede encontrarse un ISP. La tarifa de "servicios en general"
     * es la que aplica al servicio de internet y televisión.
     *
     * OJO con renta: la tarifa depende de si somos DECLARANTES de
     * renta (4%) o no (6%). Una empresa constituida es declarante,
     * así que lo normal es el 4%.
     *
     * El ICA no trae tarifa porque la fija cada municipio: se deja en
     * cero para obligar a escribirla. Se expresa en porcentaje, de
     * modo que un "6 por mil" se registra como 0,6.
     *
     * @return array<string, array{label: string, rate: float}>
     */
    public function conceptos(): array
    {
        return match ($this) {
            self::Renta => [
                'servicios_generales_declarante' => ['label' => 'Servicios en general (beneficiario declarante)', 'rate' => 4.0],
                'servicios_generales_no_declarante' => ['label' => 'Servicios en general (beneficiario no declarante)', 'rate' => 6.0],
                'compras_generales_declarante' => ['label' => 'Compras generales (declarante)', 'rate' => 2.5],
                'compras_generales_no_declarante' => ['label' => 'Compras generales (no declarante)', 'rate' => 3.5],
                'honorarios_comisiones_juridica' => ['label' => 'Honorarios y comisiones (persona jurídica)', 'rate' => 11.0],
                'honorarios_comisiones_natural' => ['label' => 'Honorarios y comisiones (persona natural)', 'rate' => 10.0],
                'arrendamiento_muebles' => ['label' => 'Arrendamiento de bienes muebles', 'rate' => 4.0],
                'arrendamiento_inmuebles' => ['label' => 'Arrendamiento de bienes inmuebles', 'rate' => 3.5],
                'otros_ingresos_tributarios' => ['label' => 'Otros ingresos tributarios', 'rate' => 2.5],
            ],
            self::Iva => [
                'reteiva_general' => ['label' => 'Retención de IVA (régimen común)', 'rate' => 15.0],
                'reteiva_no_domiciliados' => ['label' => 'Retención de IVA a no domiciliados', 'rate' => 100.0],
            ],
            self::Ica => [
                'ica_servicios' => ['label' => 'ICA — actividad de servicios', 'rate' => 0.0],
                'ica_comercial' => ['label' => 'ICA — actividad comercial', 'rate' => 0.0],
                'ica_industrial' => ['label' => 'ICA — actividad industrial', 'rate' => 0.0],
            ],
            self::Timbre => [
                // La tarifa del timbre ha ido cambiando por decreto.
                // Se deja en cero a propósito: quien registre la
                // retención debe copiar la del certificado, no confiar
                // en un número quemado en el código.
                'timbre_documentos' => ['label' => 'Timbre sobre documentos', 'rate' => 0.0],
            ],
        };
    }

    /** Etiqueta de un concepto concreto. */
    public function concepto(?string $codigo): ?string
    {
        return $this->conceptos()[$codigo]['label'] ?? null;
    }

    /** Tarifa de referencia de un concepto (0 si no la tiene fijada). */
    public function tarifa(?string $codigo): float
    {
        return (float) ($this->conceptos()[$codigo]['rate'] ?? 0.0);
    }

    /** Todos los tipos, para los formularios. */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $tipo) {
            $opciones[$tipo->value] = $tipo->etiqueta();
        }

        return $opciones;
    }

    /**
     * Catálogo completo (tipos → conceptos → tarifa) para entregarlo
     * al formulario del navegador y que precargue solo.
     *
     * @return array<string, array{label: string, short: string, base: string, help: string, concepts: array<string, array{label: string, rate: float}>}>
     */
    public static function catalogo(): array
    {
        $catalogo = [];

        foreach (self::cases() as $tipo) {
            $catalogo[$tipo->value] = [
                'label' => $tipo->etiqueta(),
                'short' => $tipo->abreviatura(),
                'base' => $tipo->baseSugerida(),
                'help' => $tipo->explicacionBase(),
                'concepts' => $tipo->conceptos(),
            ];
        }

        return $catalogo;
    }
}
