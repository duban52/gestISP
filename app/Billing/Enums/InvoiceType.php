<?php

namespace App\Billing\Enums;

/**
 * Tipos de factura.
 *
 * Clasifican el origen del cobro. La generación mensual produce
 * Mensualidad; los demás tipos quedan disponibles para los flujos
 * de instalación, reconexión, venta de equipos y facturación
 * manual (borrador → emitida) que se construyen sobre esta base.
 */
enum InvoiceType: string
{
    case Mensualidad = 'Mensualidad';
    case Instalacion = 'Instalación';
    case Reconexion = 'Reconexión';
    case Equipos = 'Equipos';
    case Manual = 'Manual';
}
