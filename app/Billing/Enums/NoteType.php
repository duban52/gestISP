<?php

namespace App\Billing\Enums;

/**
 * Tipo de nota contable sobre una factura.
 *
 * La nota CRÉDITO disminuye lo que el cliente debe (devoluciones,
 * descuentos, anulaciones, ajustes a la baja). La nota DÉBITO lo
 * aumenta (intereses, gastos por cobrar, ajustes al alza).
 */
enum NoteType: string
{
    case Credito = 'credito';
    case Debito = 'debito';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Credito => 'Nota crédito',
            self::Debito => 'Nota débito',
        };
    }

    /** Prefijo por defecto de la numeración. */
    public function prefijo(): string
    {
        return match ($this) {
            self::Credito => 'NC',
            self::Debito => 'ND',
        };
    }

    /** ¿Reduce el saldo de la factura? */
    public function disminuye(): bool
    {
        return $this === self::Credito;
    }

    /**
     * Conceptos oficiales de la DIAN para cada tipo de nota.
     *
     * Corresponden al anexo técnico de facturación electrónica
     * (tablas de "Conceptos de nota crédito" y "Conceptos de nota
     * débito"). El código es el que exige la DIAN; el texto es su
     * descripción.
     *
     * IMPORTANTE: estos códigos identifican el MOTIVO de la nota y
     * son obligatorios al reportarla electrónicamente. Conviene
     * contrastarlos con la versión del anexo técnico vigente antes
     * de conectar el sistema con el proveedor tecnológico.
     *
     * @return array<string, string>
     */
    public function conceptos(): array
    {
        return match ($this) {
            self::Credito => [
                '1' => 'Devolución parcial de bienes o no aceptación parcial del servicio',
                '2' => 'Anulación de factura electrónica',
                '3' => 'Rebaja o descuento parcial o total',
                '4' => 'Ajuste de precio',
                '5' => 'Descuento comercial por pronto pago',
                '6' => 'Descuento comercial por volumen de ventas',
            ],
            self::Debito => [
                '1' => 'Intereses',
                '2' => 'Gastos por cobrar',
                '3' => 'Cambio del valor',
                '4' => 'Otros',
            ],
        };
    }

    /** Descripción de un concepto concreto. */
    public function concepto(?string $codigo): ?string
    {
        return $this->conceptos()[$codigo] ?? null;
    }

    /** Los dos tipos, para los formularios. */
    public static function opciones(): array
    {
        return [
            self::Credito->value => self::Credito->etiqueta(),
            self::Debito->value => self::Debito->etiqueta(),
        ];
    }
}
