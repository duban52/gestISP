<?php

namespace App\Support;

/**
 * Etiquetas legibles de permisos.
 *
 * Fuente única de los nombres de módulo y acción que se muestran
 * en el formulario de roles y que usa el comando permissions:sync
 * al crear permisos nuevos.
 */
class PermissionLabels
{
    /**
     * Etiqueta por prefijo de permiso (lo que va antes del punto).
     * Varios prefijos históricos apuntan al mismo módulo
     * (ej. warehouse/warehouses).
     */
    public const MODULES = [
        'gestisp' => 'Dashboard',
        'branches' => 'Sucursales',
        'services' => 'Servicios',
        'plans' => 'Planes',
        'clients' => 'Clientes',
        'contracts' => 'Contratos',
        'invoices' => 'Facturas',
        'additionalCharges' => 'Cargos adicionales',
        'payments' => 'Pagos',
        'cashRegisters' => 'Cajas',
        'cash_register' => 'Cajas',
        'transactions' => 'Movimientos de caja',
        'warehouses' => 'Almacenes',
        'warehouse' => 'Almacenes',
        'materials' => 'Materiales',
        'categories' => 'Categorías de materiales',
        'movements' => 'Movimientos de material',
        'technicals_orders' => 'Órdenes técnicas',
        'technical_order' => 'Órdenes técnicas',
        'technical_orders' => 'Órdenes técnicas',
        'routers' => 'Routers',
        'olts' => 'OLTs',
        'onts' => 'ONTs',
        'pppoe' => 'Cuentas PPPoE',
        'users' => 'Usuarios',
        'roles' => 'Roles',
    ];

    /**
     * Verbo por acción del permiso (lo que va después del punto).
     */
    private const ACTIONS = [
        'index' => 'Ver',
        'show' => 'Ver detalle de',
        'create' => 'Crear',
        'store' => 'Crear',
        'edit' => 'Editar',
        'update' => 'Editar',
        'destroy' => 'Eliminar',
        'export' => 'Exportar',
        'export-excel' => 'Exportar a Excel',
        'excel' => 'Exportar a Excel',
        'pdf' => 'Exportar a PDF',
        'history' => 'Ver historial de',
        'summary' => 'Ver resumen de',
        'search' => 'Buscar en',
        'searchView' => 'Buscar en',
    ];

    /**
     * Nombre del módulo al que pertenece un permiso.
     */
    public static function module(string $permission): string
    {
        $prefix = explode('.', $permission)[0];

        return self::MODULES[$prefix] ?? ucfirst($prefix);
    }

    /**
     * Descripción legible de un permiso, para mostrarla en el
     * formulario de roles: "Ver almacenes", "Eliminar facturas".
     */
    public static function describe(string $permission): string
    {
        $parts = explode('.', $permission, 2);
        $module = self::module($permission);
        $action = $parts[1] ?? '';

        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action] . ' ' . self::lowerFirst($module);
        }

        // Acción sin traducción conocida: se muestra tal cual
        return $module . ': ' . $action;
    }

    /**
     * Pasa a minúscula la primera letra respetando UTF-8
     * (lcfirst() opera por bytes y deja intactas Ó, Á, Ñ...).
     */
    private static function lowerFirst(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
