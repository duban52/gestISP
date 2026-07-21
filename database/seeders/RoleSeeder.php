<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Roles
        $super_admin = Role::create(['name' => 'superadministrador']);
        $admin = Role::create(['name' => 'administrador']);
        $aux_admin = Role::create(['name' => 'auxiliar administrativo']);
        $technical = Role::create(['name' => 'tecnico']);

        // Permisos
        Permission::create(['name' => 'gestisp.index', 'description' => 'Ver el dashboard']);

        // Sucursales
        Permission::create(['name' => 'branches.index', 'description' => 'Ver sucursales']);
        Permission::create(['name' => 'branches.create', 'description' => 'Crear sucursales']);
        Permission::create(['name' => 'branches.edit', 'description' => 'Editar sucursales']);
        Permission::create(['name' => 'branches.destroy', 'description' => 'Eliminar sucursales']);

        // Servicios
        Permission::create(['name' => 'services.index', 'description' => 'Ver servicios']);
        Permission::create(['name' => 'services.create', 'description' => 'Crear servicios']);
        Permission::create(['name' => 'services.edit', 'description' => 'Editar servicios']);
        Permission::create(['name' => 'services.destroy', 'description' => 'Eliminar servicios']);

        // Planes de servicio
        Permission::create(['name' => 'plans.index', 'description' => 'Ver planes']);
        Permission::create(['name' => 'plans.create', 'description' => 'Crear planes']);
        Permission::create(['name' => 'plans.edit', 'description' => 'Editar planes']);
        Permission::create(['name' => 'plans.destroy', 'description' => 'Eliminar planes']);

        // Clientes
        Permission::create(['name' => 'clients.index', 'description' => 'Ver clientes']);
        Permission::create(['name' => 'clients.create', 'description' => 'Crear clientes']);
        Permission::create(['name' => 'clients.edit', 'description' => 'Editar clientes']);
        Permission::create(['name' => 'clients.destroy', 'description' => 'Eliminar clientes']);
        Permission::create(['name' => 'clients.search', 'description' => 'Buscar clientes']);
        Permission::create(['name' => 'clients.searchView', 'description' => 'Ver resultados de búsqueda de clientes']);
        Permission::create(['name' => 'clients.export', 'description' => 'Exportar clientes']);

        // Contratos
        Permission::create(['name' => 'contracts.index', 'description' => 'Ver contratos']);
        Permission::create(['name' => 'contracts.show', 'description' => 'Ver contrato']);
        Permission::create(['name' => 'contracts.create', 'description' => 'Crear contratos']);
        Permission::create(['name' => 'contracts.edit', 'description' => 'Editar contratos']);
        Permission::create(['name' => 'contracts.destroy', 'description' => 'Eliminar contratos']);
        Permission::create(['name' => 'contracts.export', 'description' => 'Exportar contratos']);

        // Facturas
        Permission::create(['name' => 'invoices.index', 'description' => 'Ver facturas']);
        Permission::create(['name' => 'invoices.show', 'description' => 'Ver factura individual']);
        Permission::create(['name' => 'invoices.create', 'description' => 'Crear facturas']);
        Permission::create(['name' => 'invoices.edit', 'description' => 'Editar facturas']);
        Permission::create(['name' => 'invoices.destroy', 'description' => 'Eliminar facturas']);
        Permission::create(['name' => 'invoices.generate', 'description' => 'Generar facturas']);
        Permission::create(['name' => 'invoices.download-pdf', 'description' => 'Descargar factura en PDF']);
        Permission::create(['name' => 'invoices.generate_max_pdf', 'description' => 'Generar PDF masivo de facturas']);
        Permission::create(['name' => 'invoices.check-pdf-status', 'description' => 'Verificar estado de generación de PDF']);

        // Cargos adicionales
        Permission::create(['name' => 'additionalCharges.index', 'description' => 'Ver cargos adicionales']);
        Permission::create(['name' => 'additionalCharges.create', 'description' => 'Crear cargos adicionales']);
        Permission::create(['name' => 'additionalCharges.edit', 'description' => 'Editar cargos adicionales']);
        Permission::create(['name' => 'additionalCharges.destroy', 'description' => 'Eliminar cargos adicionales']);

        // Pagos
        Permission::create(['name' => 'payments.index', 'description' => 'Ver pagos']);
        Permission::create(['name' => 'payments.create', 'description' => 'Crear pagos']);
        Permission::create(['name' => 'payments.edit', 'description' => 'Editar pagos']);
        Permission::create(['name' => 'payments.destroy', 'description' => 'Eliminar pagos']);
        Permission::create(['name' => 'payments.search', 'description' => 'Buscar pagos']);
        Permission::create(['name' => 'payments.searchView', 'description' => 'Ver resultados de búsqueda de pagos']);
        Permission::create(['name' => 'payments.export', 'description' => 'Exportar pagos en PDF']);
        Permission::create(['name' => 'payments.export-excel', 'description' => 'Exportar pagos en Excel']);

        // Cajas
        Permission::create(['name' => 'cashRegisters.index', 'description' => 'Ver cajas']);
        Permission::create(['name' => 'cashRegisters.create', 'description' => 'Crear cajas']);
        Permission::create(['name' => 'cashRegisters.edit', 'description' => 'Editar cajas']);
        Permission::create(['name' => 'cashRegisters.destroy', 'description' => 'Eliminar cajas']);
        Permission::create(['name' => 'cash_register.status', 'description' => 'Ver estado de la caja']);
        Permission::create(['name' => 'cash_register.open', 'description' => 'Abrir caja']);
        Permission::create(['name' => 'cash_register.close', 'description' => 'Cerrar caja']);
        Permission::create(['name' => 'cash_register.summary', 'description' => 'Ver resumen de cajas por período']);

        // Movimientos de caja
        Permission::create(['name' => 'transactions.index', 'description' => 'Ver movimientos de caja']);
        Permission::create(['name' => 'transactions.store', 'description' => 'Crear movimientos de caja']);
        Permission::create(['name' => 'transactions.history', 'description' => 'Ver historial de movimientos de caja']);
        Permission::create(['name' => 'transactions.export', 'description' => 'Exportar historial de movimientos en PDF']);
        Permission::create(['name' => 'transactions.export-excel', 'description' => 'Exportar historial de movimientos en Excel']);

        // Almacenes
        Permission::create(['name' => 'warehouses.index', 'description' => 'Ver almacenes']);
        Permission::create(['name' => 'warehouses.create', 'description' => 'Crear almacenes']);
        Permission::create(['name' => 'warehouses.edit', 'description' => 'Editar almacenes']);
        Permission::create(['name' => 'warehouses.destroy', 'description' => 'Eliminar almacenes']);
        Permission::create(['name' => 'warehouse.pdf', 'description' => 'Generar PDF de inventario']);
        Permission::create(['name' => 'warehouses.show', 'description' => 'Ver inventarios']);

        // Materiales
        Permission::create(['name' => 'materials.index', 'description' => 'Ver materiales']);
        Permission::create(['name' => 'materials.create', 'description' => 'Crear materiales']);
        Permission::create(['name' => 'materials.edit', 'description' => 'Editar materiales']);
        Permission::create(['name' => 'materials.destroy', 'description' => 'Eliminar materiales']);

        // Categorías de materiales
        Permission::create(['name' => 'categories.index', 'description' => 'Ver categorías de materiales']);
        Permission::create(['name' => 'categories.create', 'description' => 'Crear categorías de materiales']);
        Permission::create(['name' => 'categories.edit', 'description' => 'Editar categorías de materiales']);
        Permission::create(['name' => 'categories.destroy', 'description' => 'Eliminar categorías de materiales']);

        // Movimientos de material
        Permission::create(['name' => 'movements.index', 'description' => 'Ver movimientos de material']);
        Permission::create(['name' => 'movements.create', 'description' => 'Crear movimientos de material']);
        Permission::create(['name' => 'movements.edit', 'description' => 'Editar movimientos de material']);
        Permission::create(['name' => 'movements.destroy', 'description' => 'Eliminar movimientos de material']);
        Permission::create(['name' => 'movements.query_sn', 'description' => 'Consultar números de serie']);
        Permission::create(['name' => 'movements.material_quantity', 'description' => 'Consultar cantidad de material']);
        Permission::create(['name' => 'movements.history', 'description' => 'Ver historial de movimientos de material']);
        Permission::create(['name' => 'movements.history_data', 'description' => 'Ver datos del historial de movimientos']);
        Permission::create(['name' => 'movements.pdf', 'description' => 'Exportar historial de movimientos en PDF']);
        Permission::create(['name' => 'movements.excel', 'description' => 'Exportar historial de movimientos en Excel']);

        // Órdenes técnicas
        Permission::create(['name' => 'technicals_orders.index', 'description' => 'Ver órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.create', 'description' => 'Crear órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.store', 'description' => 'Guardar órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.update', 'description' => 'Actualizar órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.my_technical_orders', 'description' => 'Ver mis órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.process', 'description' => 'Procesar órdenes técnicas']);
        Permission::create(['name' => 'technicals_orders.getSerialNumbers', 'description' => 'Obtener números de serie']);
        Permission::create(['name' => 'technicals_orders.verification', 'description' => 'Verificar órdenes técnicas']);
        Permission::create(['name' => 'technical_order.verification_process', 'description' => 'Procesar verificación de órdenes']);
        Permission::create(['name' => 'technical_orders.reject', 'description' => 'Rechazar órdenes técnicas']);

        // Routers (Mikrotik)
        Permission::create(['name' => 'routers.index', 'description' => 'Ver routers']);
        Permission::create(['name' => 'routers.create', 'description' => 'Crear routers']);
        Permission::create(['name' => 'routers.edit', 'description' => 'Editar routers']);
        Permission::create(['name' => 'routers.destroy', 'description' => 'Eliminar routers']);

        // OLTs
        Permission::create(['name' => 'olts.index', 'description' => 'Ver OLTs']);
        Permission::create(['name' => 'olts.create', 'description' => 'Crear OLTs']);
        Permission::create(['name' => 'olts.edit', 'description' => 'Editar OLTs']);
        Permission::create(['name' => 'olts.vlans', 'description' => 'Crear VLANs y perfiles en la OLT']);

        // ONTs
        Permission::create(['name' => 'onts.index', 'description' => 'Ver ONTs (autorizadas y por autorizar)']);
        Permission::create(['name' => 'onts.show', 'description' => 'Ver detalle y estado de ONT']);
        Permission::create(['name' => 'onts.activate', 'description' => 'Activar/autorizar ONTs']);
        Permission::create(['name' => 'onts.destroy', 'description' => 'Eliminar ONTs']);
        Permission::create(['name' => 'onts.relocate', 'description' => 'Mover ONTs de puerto']);
        Permission::create(['name' => 'onts.catv', 'description' => 'Activar/desactivar CATV en ONTs']);

        // Cuentas PPPoE
        Permission::create(['name' => 'pppoe.index', 'description' => 'Ver cuentas PPPoE']);
        Permission::create(['name' => 'pppoe.show', 'description' => 'Ver detalle de cuenta PPPoE']);
        Permission::create(['name' => 'pppoe.create', 'description' => 'Crear cuentas PPPoE']);
        Permission::create(['name' => 'pppoe.edit', 'description' => 'Editar cuentas PPPoE']);
        Permission::create(['name' => 'pppoe.destroy', 'description' => 'Eliminar cuentas PPPoE']);
        Permission::create(['name' => 'pppoe.import', 'description' => 'Importar cuentas PPPoE desde el router']);
        Permission::create(['name' => 'pppoe.restart', 'description' => 'Reiniciar sesiones PPPoE']);

        //Usuarios
        Permission::create(['name' => 'users.index', 'description' => 'Ver usuarios']);
        Permission::create(['name' => 'users.create', 'description' => 'Crear usuarios']);
        Permission::create(['name' => 'users.edit', 'description' => 'Editar usuarios']);
        Permission::create(['name' => 'users.destroy', 'description' => 'Eliminar usuarios']);
        Permission::create(['name' => 'users.trace', 'description' => 'Ver trazabilidad y sesiones de un usuario']);
        Permission::create(['name' => 'users.sessions.close', 'description' => 'Cerrar sesiones activas de un usuario de forma remota']);
        Permission::create(['name' => 'users.disable', 'description' => 'Habilitar o inhabilitar el acceso de un usuario']);

        // Informes gerenciales
        Permission::create(['name' => 'reports.index', 'description' => 'Ver el tablero de informes gerenciales']);
        Permission::create(['name' => 'reports.growth', 'description' => 'Ver el informe de crecimiento de contratos']);
        Permission::create(['name' => 'reports.technical', 'description' => 'Ver el informe de órdenes técnicas y técnicos']);
        Permission::create(['name' => 'reports.billing', 'description' => 'Ver el informe de facturación y recaudo']);
        Permission::create(['name' => 'reports.provisioning', 'description' => 'Ver el informe de aprovisionamiento de red']);

        //Roles
        Permission::create(['name' => 'roles.index', 'description' => 'Ver roles']);
        Permission::create(['name' => 'roles.create', 'description' => 'Crear roles']);
        Permission::create(['name' => 'roles.edit', 'description' => 'Editar roles']);
        Permission::create(['name' => 'roles.destroy', 'description' => 'Eliminar roles']);


        // Asignar permisos a roles
        $super_admin->givePermissionTo(Permission::all());
        $admin->givePermissionTo([
            'gestisp.index',
            'services.index', 'services.create', 'services.edit',
            'plans.index', 'plans.create', 'plans.edit',
            'clients.index', 'clients.create', 'clients.edit', 'clients.search', 'clients.searchView', 'clients.export',
            'contracts.index', 'contracts.show', 'contracts.create', 'contracts.edit', 'contracts.export',
            'invoices.index', 'invoices.show', 'invoices.create', 'invoices.edit', 'invoices.generate', 'invoices.download-pdf', 'invoices.generate_max_pdf', 'invoices.check-pdf-status',
            'additionalCharges.index', 'additionalCharges.create', 'additionalCharges.edit',
            'payments.index', 'payments.create', 'payments.edit', 'payments.search', 'payments.searchView', 'payments.export', 'payments.export-excel',
            'cashRegisters.index', 'cashRegisters.create', 'cashRegisters.edit', 'cash_register.status', 'cash_register.open', 'cash_register.close', 'cash_register.summary',
            'transactions.index', 'transactions.store', 'transactions.history', 'transactions.export', 'transactions.export-excel',
            'warehouses.index', 'warehouses.create', 'warehouses.edit', 'warehouse.pdf',
            'materials.index', 'materials.create', 'materials.edit',
            'categories.index', 'categories.create', 'categories.edit',
            'movements.index', 'movements.create', 'movements.edit', 'movements.query_sn', 'movements.material_quantity', 'movements.history', 'movements.history_data', 'movements.pdf', 'movements.excel',
            'technicals_orders.index', 'technicals_orders.create', 'technicals_orders.store', 'technicals_orders.update', 'technicals_orders.my_technical_orders', 'technicals_orders.process', 'technicals_orders.getSerialNumbers', 'technicals_orders.verification', 'technical_order.verification_process', 'technical_orders.reject',
            'routers.index', 'routers.create', 'routers.edit', 'routers.destroy',
            'olts.index', 'olts.create', 'olts.edit', 'olts.vlans',
            'onts.index', 'onts.show', 'onts.activate', 'onts.destroy', 'onts.relocate', 'onts.catv',
            'pppoe.index', 'pppoe.show', 'pppoe.create', 'pppoe.edit', 'pppoe.destroy', 'pppoe.import', 'pppoe.restart',
            'reports.index', 'reports.growth', 'reports.technical', 'reports.billing', 'reports.provisioning'
        ]);

        $aux_admin->givePermissionTo([
            'gestisp.index',
            'clients.index', 'clients.create', 'clients.edit', 'clients.search', 'clients.searchView',
            'contracts.index', 'contracts.show', 'contracts.create', 'contracts.edit',
            'invoices.index', 'invoices.create', 'invoices.show', 'invoices.edit', 'invoices.generate', 'invoices.download-pdf',
            'payments.index', 'payments.create', 'payments.edit', 'payments.search', 'payments.searchView',
            'cashRegisters.index', 'cash_register.status', 'cash_register.open', 'cash_register.close',
            'transactions.index', 'transactions.store', 'transactions.history',
            'warehouses.index',
            'materials.index',
            'categories.index',
            'movements.index', 'movements.create', 'movements.edit',
            'technicals_orders.index', 'technicals_orders.create', 'technicals_orders.store', 'technicals_orders.update', 'technicals_orders.my_technical_orders', 'technicals_orders.process', 'technicals_orders.getSerialNumbers', 'technicals_orders.verification'
        ]);

        $technical->givePermissionTo([
            'gestisp.index',
            'technicals_orders.my_technical_orders', 'technicals_orders.process', 'technicals_orders.getSerialNumbers', 'technical_orders.reject', 'movements.material_quantity', 'movements.query_sn'
        ]);
    }
}
