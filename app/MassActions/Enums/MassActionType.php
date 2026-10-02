<?php

namespace App\MassActions\Enums;

/**
 * Qué clase de operación masiva fue.
 *
 * ES LA LLAVE DE LA REVERSIÓN: el tipo decide qué estrategia sabe
 * deshacerla (ver `MassActionRegistry`). Añadir un proceso masivo
 * nuevo al sistema es añadir un caso aquí y una estrategia; nada más
 * tiene que enterarse.
 *
 * LOS VALORES NO SE RENOMBRAN. Quedan escritos en filas que ya
 * existen; cambiar la cadena deja huérfanas las acciones viejas y el
 * historial empieza a mentir sobre lo que pasó.
 */
enum MassActionType: string
{
    case CorteDeContratos = 'corte_contratos';
    case CortePppoe = 'corte_pppoe';
    case ImportacionDeClientes = 'importacion_clientes';
    case ImportacionDeOnts = 'importacion_onts';
    case CorridaDeFacturacion = 'corrida_facturacion';

    /** Una acción de reversión: deshace a otra. */
    case Reversion = 'reversion';

    public function label(): string
    {
        return match ($this) {
            self::CorteDeContratos => 'Corte masivo por cartera',
            self::CortePppoe => 'Corte masivo de PPPoE',
            self::ImportacionDeClientes => 'Importación de clientes y contratos',
            self::ImportacionDeOnts => 'Importación de ONTs',
            self::CorridaDeFacturacion => 'Corrida de facturación',
            self::Reversion => 'Reversión',
        };
    }

    public function icono(): string
    {
        return match ($this) {
            self::CorteDeContratos, self::CortePppoe => 'fa-plug',
            self::ImportacionDeClientes, self::ImportacionDeOnts => 'fa-file-import',
            self::CorridaDeFacturacion => 'fa-file-invoice-dollar',
            self::Reversion => 'fa-undo',
        };
    }

    /** Las que se pueden elegir en el filtro del historial. */
    public static function paraFiltro(): array
    {
        return self::cases();
    }
}
