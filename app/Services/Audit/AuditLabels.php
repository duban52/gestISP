<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Traduce lo técnico a lo que entiende una persona.
 *
 * La auditoría la leen administradores, no programadores: en vez de
 * "App\Models\TechnicalOrder #45" debe decir "Orden técnica N.º 45".
 */
class AuditLabels
{
    /**
     * Nombre en español de cada modelo y el módulo al que pertenece.
     *
     * [clase => [nombre singular, módulo]]
     */
    private const MODELOS = [
        \App\Models\Client::class => ['el cliente', 'clientes'],
        \App\Models\Contract::class => ['el contrato', 'contratos'],
        \App\Models\ContractComment::class => ['un comentario del contrato', 'contratos'],
        \App\Models\Plan::class => ['el plan', 'planes'],
        \App\Models\Service::class => ['el servicio', 'servicios'],
        \App\Models\Invoice::class => ['la factura', 'facturacion'],
        \App\Models\InvoiceItem::class => ['un ítem de factura', 'facturacion'],
        \App\Models\AditionalCharge::class => ['el cargo adicional', 'facturacion'],
        \App\Models\BillingRun::class => ['la corrida de facturación', 'facturacion'],
        \App\Models\Payment::class => ['el pago', 'pagos'],
        \App\Models\CashRegister::class => ['la caja', 'cajas'],
        \App\Models\CashRegisterTransaction::class => ['el movimiento de caja', 'cajas'],
        \App\Models\TechnicalOrder::class => ['la orden técnica', 'ordenes'],
        \App\Models\TechnicalOrderMaterial::class => ['el material de la orden', 'ordenes'],
        \App\Models\TechnicalOrderVerification::class => ['la verificación de la orden', 'ordenes'],
        \App\Models\Material::class => ['el material', 'inventario'],
        \App\Models\MaterialMovement::class => ['el movimiento de material', 'inventario'],
        \App\Models\Inventory::class => ['la existencia de inventario', 'inventario'],
        \App\Models\Warehouse::class => ['el almacén', 'inventario'],
        \App\Models\Category::class => ['la categoría', 'inventario'],
        \App\Models\Olt::class => ['la OLT', 'red'],
        \App\Models\Ont::class => ['la ONT', 'red'],
        \App\Models\Router::class => ['el router', 'red'],
        \App\Models\PppoeAccount::class => ['la cuenta PPPoE', 'red'],
        \App\Models\VlanOlt::class => ['la VLAN', 'red'],
        \App\Models\LineProfile::class => ['el perfil de línea', 'red'],
        \App\Models\SrvProfile::class => ['el perfil de servicio', 'red'],
        \App\Models\User::class => ['el usuario', 'usuarios'],
        \App\Models\Branch::class => ['la sucursal', 'sucursales'],
        \Spatie\Permission\Models\Role::class => ['el rol', 'roles'],
        \Spatie\Permission\Models\Permission::class => ['el permiso', 'roles'],
    ];

    /** Nombre legible del modelo. */
    public static function modelo(string $clase): string
    {
        return self::MODELOS[$clase][0] ?? Str::of(class_basename($clase))->snake(' ')->lower()->prepend('');
    }

    /** Módulo al que pertenece el modelo. */
    public static function categoriaDeModelo(string $clase): string
    {
        return self::MODELOS[$clase][1] ?? Str::snake(class_basename($clase));
    }

    /**
     * Cómo identificar el registro concreto: se busca el campo más
     * reconocible (nombre, serial, número) y si no hay, el id.
     */
    public static function identificar(Model $model): string
    {
        foreach (['name', 'sn', 'full_number', 'description', 'user_pppoe', 'email'] as $campo) {
            $valor = $model->getAttribute($campo);

            if (is_string($valor) && $valor !== '') {
                return '«' . Str::limit($valor, 60) . '»';
            }
        }

        // Cliente y usuario: nombre y apellido
        if ($model->getAttribute('last_name')) {
            return '«' . trim($model->getAttribute('name') . ' ' . $model->getAttribute('last_name')) . '»';
        }

        return 'N.º ' . $model->getKey();
    }

    /**
     * Nombre legible de los módulos, para los filtros de la pantalla.
     *
     * @return array<string, string>
     */
    public static function categorias(): array
    {
        return [
            'clientes' => 'Clientes',
            'contratos' => 'Contratos',
            'planes' => 'Planes',
            'servicios' => 'Servicios',
            'facturacion' => 'Facturación',
            'pagos' => 'Pagos',
            'cajas' => 'Cajas',
            'ordenes' => 'Órdenes técnicas',
            'inventario' => 'Inventario',
            'red' => 'Red (OLT/ONT/PPPoE)',
            'usuarios' => 'Usuarios',
            'roles' => 'Roles y permisos',
            'sucursales' => 'Sucursales',
            'auth' => 'Accesos al sistema',
            'sistema' => 'Sistema',
        ];
    }

    /**
     * Nombre legible de las acciones.
     */
    public static function accion(string $action): string
    {
        $conocidas = [
            'created' => 'Creación',
            'updated' => 'Modificación',
            'deleted' => 'Eliminación',
            'restored' => 'Restauración',
            'auth.login' => 'Inicio de sesión',
            'auth.logout' => 'Cierre de sesión',
            'auth.failed' => 'Intento fallido',
        ];

        return $conocidas[$action] ?? $action;
    }
}
