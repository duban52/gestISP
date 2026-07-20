<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\CashRegisterTransactionController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ManagementReportController;
use App\Http\Controllers\MaterialMovementController;
use App\Http\Controllers\OltController;
use App\Http\Controllers\OntController;
use App\Http\Controllers\OntImportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PppoeAccountController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\TechnicalOrderController;
use App\Http\Controllers\WarehouseController;
use App\Models\Olt;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aquí es donde puedes registrar las rutas web para tu aplicación. Estas
| rutas son cargadas por el RouteServiceProvider dentro de un grupo que
| contiene el grupo de middleware "web". ¡Haz algo grandioso!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Registro público deshabilitado: los usuarios se crean desde el
// módulo de usuarios (requiere permiso users.create).
Auth::routes(['register' => false]);



Route::get('/', [App\Http\Controllers\AdminController::class, 'index'])->name('gestisp.index');

// Rutas del dash con prefijo 'gestisp'
Route::namespace('App\Http\Controllers')->prefix('gestisp')->group(function () {

    // Sucursales
    Route::resource('branches', 'BranchController')->names('branches');

    // Clientes
    Route::resource('clients', 'ClientController')->names('clients');

    // Servicios
    Route::resource('services', 'ServiceController')->names('services');

    // Planes
    Route::resource('plans', 'PlanController')->names('plans');

    // Contratos
    Route::resource('contracts', 'ContractController')->names('contracts');

    // Facturas
    Route::resource('invoices', 'InvoiceController')->names('invoices');

    // Cargos adicionales
    Route::resource('additionalCharges', 'AdditionalChargeController')->names('additionalCharges');

    // Pagos
    Route::resource('payments', 'PaymentController')->names('payments');


    // Cajas
    Route::resource('cashRegisters', 'CashRegisterController')->names('cashRegisters');

    //Almacenes
    Route::resource('warehouses', 'WarehouseController')->names('warehouses');

    //Materiales
    Route::resource('materials', 'MaterialController')->names('materials');

    //Categorías de materiales
    Route::resource('categories', 'CategoryController')->names('categories');

    //Movimientos de material
    Route::resource('movements', 'MaterialMovementController')->names('movements');

    //Usuarios
    Route::resource('users', 'UserController')->names('users');

    //Roles
    Route::resource('roles', 'RoleController')->names('roles');

    //Olts
    Route::resource('olts', 'OltController')->names('olts');

});
//Importación de ONTs existentes en una OLT (equipos ya configurados
//a mano o provenientes de otro sistema).
//IMPORTANTE: estas rutas van ANTES que /onts/{ont}; si se declaran
//después, Laravel interpreta "import" como el identificador de una
//ONT y la pantalla responde 404.
Route::get('/onts/import',  [OntImportController::class, 'index'])->name('onts.import.index');
Route::post('/onts/import/preview', [OntImportController::class, 'preview'])->name('onts.import.preview');
Route::post('/onts/import', [OntImportController::class, 'store'])->name('onts.import.store');
Route::get('/onts/import/{run}/status', [OntImportController::class, 'status'])->name('onts.import.status');

Route::get('/onts/authorized', [OntController::class , 'authorized_ont_index'])->name('onts.authorized');
//Listar onts no autorizada
Route::get('/onts/no-authorized', [OntController::class , 'no_authorized_ont_index'])->name('onts.no-authorized');
Route::get('/olts/{olt}/onts-autofind', [OltController::class, 'ontsAutofind']);
// Ruta del buscador de clientes
Route::get('/clients/search', [ClientController::class, 'searchView'])->name('clients.searchView');
Route::post('/clients/search', [ClientController::class, 'search'])->name('clients.search');

// Ruta para exportar clientes a excel
Route::get('/clients/export', [ClientController::class, 'export'])->name('clients.export');

// Ruta para exportar ordenes
Route::get('/orders/export', [TechnicalOrderController::class, 'export'])->name('orders.export');
// Ruta para crear contrato a cliente
Route::get('contracts/create/{client}', [ContractController::class, 'create'])->name('contracts.create');

// Ruta para obtener las sucursales en el login
Route::get('/user/branches', [LoginController::class, 'getBranches'])->name('user.branches');

// Generación de facturas
Route::post('/invoices/generate', [InvoiceController::class, 'generateInvoices'])->name('invoices.generate');

// Anulación de facturas (nunca se eliminan: cambian a estado Anulada)
Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'voidInvoice'])->name('invoices.void');

// Reporte gerencial de corridas de facturación
Route::get('/invoices/billing-runs', [InvoiceController::class, 'billingRuns'])->name('invoices.billing_runs');

// Resumen de cajas por período (cuadre entre puntos de cobro)
Route::get('/cash-register/summary', [CashRegisterController::class, 'summary'])->name('cash_register.summary');

// Descarga de PDF
Route::get('/invoices/{id}/download-pdf', [InvoiceController::class, 'downloadInvoicePdf'])->name('invoices.download-pdf');
//generacion de PDF másivo
Route::get('invoices/generate_pdf', [InvoiceController::class, 'generatePendingInvoicesPdf'])->name('invoices.generate_max_pdf');
//Consultar estado de generación de facturas pdf
Route::get('check-pdf-status', [InvoiceController::class, 'checkPdfStatus'])->name('invoices.check-pdf-status');

// Ruta para exportar contratos a excel
Route::get('/contracts/export', [ContractController::class, 'export'])->name('contracts.export');

// Gestión de la caja
Route::get('/cash-register/status', [CashRegisterController::class, 'status'])->name('cash_register.status');
Route::post('/cash-register/open', [CashRegisterController::class, 'open'])->name('cash_register.open');
Route::post('/cash-register/close', [CashRegisterController::class, 'close'])->name('cash_register.close');

//pagos
Route::POST('/payments/search', [PaymentController::class, 'search'])->name('payments.search');
Route::get('/payments/search', [PaymentController::class, 'searchView'])->name('payments.searchView');
//Exportar pdf con reporte de pagos
Route::get('/payments/export-pdf', [PaymentController::class, 'exportPaymentsPDF'])->name('payments.export');
//Exportar pagos en excel
Route::get('payments/export-excel', [PaymentController::class, 'export'])->name('payments.export-excel');
//Ruta para movimientos de caja
Route::get('cashRegisters/trasactions', [CashRegisterTransactionController::class, 'index'])->name('transactions.index');
Route::post('cashRegisters/trasactions', [CashRegisterTransactionController::class, 'store'])->name('transactions.store');
Route::get('cashRegisters/transactions/history', [CashRegisterTransactionController::class, 'history'])->name('transactions.history');
Route::get('cashRegisters/transactions/report-pdf', [CashRegisterTransactionController::class, 'exportHistoryTransactionsPDF'])->name('transactions.export');
Route::get('cashRegisters/transactions/export-excel', [CashRegisterTransactionController::class, 'export'])->name('transactions.export-excel');
//Movimiento de material (consulta SN)
Route::get('inventories/{warehouse}/materials/{material}/serial-numbers', [MaterialMovementController::class, 'getAvailableSerialNumbers'])->name('movements.query_sn');
Route::get('inventories/{warehouse}/materials/{material}/quantity', [MaterialMovementController::class, 'getAvailableQuantity'])->name('movements.material_quantity');;
//Pdf de inventarios
Route::get('/warehouse/{warehouse}/pdf', [WarehouseController::class, 'generatePdf'])->name('warehouse.pdf');
//Historial de movimientos de almacen
Route::get('materials/movements/history', [MaterialMovementController::class, 'history'])->name('movements.history');
Route::get('movements/history', [MaterialMovementController::class, 'history'])->name('movements.history_data');
//Exportar historial de movimientos en pdf y excel
Route::get('materials/movements/history/pdf', [MaterialMovementController::class, 'exportMovementsPDF'])->name('movements.pdf');
Route::get('materials/movements/history/excel', [MaterialMovementController::class, 'export'])->name('movements.excel');
//Ruta para la creación de una orden tecnica desde el contrato
Route::get('technicals_orders/create/{contract}', [TechnicalOrderController::class, 'create'])->name('technicals_orders.create');
Route::post('technicals_orders/store', [TechnicalOrderController::class, 'store'])->name('technicals_orders.store');
Route::get('technicals_orders/index', [TechnicalOrderController::class, 'index'])->name('technicals_orders.index');
Route::put('technicals_orders/{technicalOrder}', [TechnicalOrderController::class, 'update'])->name('technicals_orders.update');
//órdenes exclusivasd del usuario asignado
Route::get('technicals_orders/my_technical_orders', [TechnicalOrderController::class, 'myTechnicalOrders'])->name('technicals_orders.my_technical_orders');
//Ver orden
Route::get('technicals_orders/show/{technical_order}', [TechnicalOrderController::class, 'show'])->name('technicals_orders.show');
//PRocesar orden
Route::post('/technicals_orders/process/{id}', [TechnicalOrderController::class, 'processOrder'])->name('technicals_orders.process');
Route::get('technicals_orders/get-serial-numbers/{materialId}', [TechnicalOrderController::class, 'getSerialNumbers']);
//Vista de verificación de ordenes
Route::get('technicals_orders/verification', [TechnicalOrderController::class, 'orderVerification'])->name('technicals_orders.verification');
//Actualizar la orden si se cancela o rechaza
Route::put('technicals_orders/vertification/{technical_order}', [TechnicalOrderController::class, 'verificationOrderProcess'])->name('technical_order.verification_process');
//Rechazar orden por parte del técnico
Route::put('technicals_orders/reject/{technical_order}', [TechnicalOrderController::class, 'orderReject'])->name('technical_orders.reject');

Route::middleware('auth')->get('/api/olts', [OltController::class, 'apiOlts'])->name('api.olts');
Route::middleware('auth')->get('/api/vlansolt/{olt}', [OltController::class, 'viewVlans'])->name('api.vlansolt');
Route::middleware('auth')->get('/api/lineprofiles/{olt}', [OltController::class, 'viewLineProfiles'])->name('api.lineProfile');
Route::middleware('auth')->get('/api/srvprofiles/{olt}', [OltController::class, 'viewSrvProfiles'])->name('api.srvProfile');

//Configuración de la OLT: VLANs y perfiles que ya existen en el
//equipo y se registran aquí para ofrecerlos al autorizar una ONT.
Route::post('/vlans', [OltController::class, 'storeVlan'])->name('olt.vlans.store');
Route::put('/vlans/{vlan}', [OltController::class, 'updateVlan'])->name('olt.vlans.update');
Route::delete('/vlans/{vlan}', [OltController::class, 'destroyVlan'])->name('olt.vlans.destroy');

Route::post('/lineprofiles', [OltController::class, 'storeLineProfile'])->name('olt.lineprofiles.store');
Route::put('/lineprofiles/{lineProfile}', [OltController::class, 'updateLineProfile'])->name('olt.lineprofiles.update');
Route::delete('/lineprofiles/{lineProfile}', [OltController::class, 'destroyLineProfile'])->name('olt.lineprofiles.destroy');

Route::post('/srvprofiles', [OltController::class, 'storeSrvProfile'])->name('olt.srvprofiles.store');
Route::put('/srvprofiles/{srvProfile}', [OltController::class, 'updateSrvProfile'])->name('olt.srvprofiles.update');
Route::delete('/srvprofiles/{srvProfile}', [OltController::class, 'destroySrvProfile'])->name('olt.srvprofiles.destroy');

//Activar ONT
Route::post('/onts/activate', [OntController::class, 'activate'])->name('onts.activate');
//Vincular la ONT con un contrato (para las importadas desde la OLT,
//que llegan sin cliente asignado). Solo escribe en base de datos.
Route::post('/onts/{ont}/contrato',   [OntController::class, 'linkContract'])->name('onts.link_contract');
Route::delete('/onts/{ont}/contrato', [OntController::class, 'unlinkContract'])->name('onts.unlink_contract');
//eliminar ONT
Route::delete('/onts/{ont}', [OntController::class, 'destroy'])->name('onts.destroy');
//Refrescar potencia de la ont
Route::post('/onts/{ont}/sync-power', [OntController::class, 'syncPower'])->name('onts.sync-power');
Route::post('/onts/{ont}/sync-power', [OntController::class, 'syncPower'])->name('onts.sync-power');
//Buscar contratos para activar ONT
Route::get('/api/contratos/buscar', [OntController::class, 'buscarContrato'])->name('contratos.buscar');
//Verificar si una ont ya exixte
Route::get('/api/onts/check-sn/{sn}', [OntController::class, 'checkSn'])->name('onts.check-sn');
//Mover la ONT
Route::post('/onts/{ont}/relocate', [OntController::class, 'relocate'])->name('onts.relocate');
//Mostrar la ONT individual
Route::get('/onts/{ont}', [OntController::class, 'show'])->name('onts.show');
//Activar y desactivar CATV en ONTS
Route::post('/onts/{ont}/catv/enable',  [OntController::class, 'enableCatv'])->name('onts.catv.enable');
Route::post('/onts/{ont}/catv/disable', [OntController::class, 'disableCatv'])->name('onts.catv.disable');
//Verificar contra la OLT el estado real del puerto CATV (consulta lenta, bajo demanda)
Route::get('/onts/{ont}/catv/state', [OntController::class, 'checkCatvState'])->name('onts.catv.state');
//Habilitar y deshabilitar la ONT completa (corta o restablece el servicio)
Route::post('/onts/{ont}/enable',  [OntController::class, 'enableOnt'])->name('onts.enable');
Route::post('/onts/{ont}/disable', [OntController::class, 'disableOnt'])->name('onts.disable');

//Cargar información de onts (SNMP: respuesta en milisegundos)
Route::get('/onts/{ont}/realtime', [OntController::class, 'realtimeInfo'])->name('onts.realtime');
//Historial de métricas para las gráficas de la vista de detalle
Route::get('/onts/{ont}/metrics-history', [OntController::class, 'metricsHistory'])->name('onts.metrics_history');

// Routers
Route::get('/routers',                 [RouterController::class, 'index'])->name('routers.index');
Route::get('/routers/create',          [RouterController::class, 'create'])->name('routers.create');
Route::post('/routers',                [RouterController::class, 'store'])->name('routers.store');
Route::get('/routers/{router}/edit',   [RouterController::class, 'edit'])->name('routers.edit');
Route::put('/routers/{router}',        [RouterController::class, 'update'])->name('routers.update');
Route::delete('/routers/{router}',     [RouterController::class, 'destroy'])->name('routers.destroy');
Route::get('/api/routers',                     [RouterController::class, 'apiRouters'])->name('api.routers');
Route::get('/api/routers/{router}/profiles',   [RouterController::class, 'apiProfiles'])->name('api.routers.profiles');

//Ver estados de sesiones pppoe
Route::get('/pppoe/{pppoe}',                  [PppoeAccountController::class, 'show'])->name('pppoe.show');
Route::get('/pppoe/{pppoe}/realtime-session', [PppoeAccountController::class, 'realtimeSession'])->name('pppoe.realtime');
//Historial de tráfico para la gráfica de ancho de banda
Route::get('/pppoe/{pppoe}/metrics-history', [PppoeAccountController::class, 'metricsHistory'])->name('pppoe.metrics_history');
Route::post('/pppoe/{pppoe}/restart-session', [PppoeAccountController::class, 'restartSession'])->name('pppoe.restart');
// PPPoE
Route::get('/pppoe',                       [PppoeAccountController::class, 'index'])->name('pppoe.index');
Route::post('/pppoe',                      [PppoeAccountController::class, 'store'])->name('pppoe.store');
Route::put('/pppoe/{pppoe}',               [PppoeAccountController::class, 'update'])->name('pppoe.update');
Route::post('/pppoe/{pppoe}/toggle',       [PppoeAccountController::class, 'toggleState'])->name('pppoe.toggle');
//Vincular la cuenta con un contrato. Va aparte de la edición: esta
//no toca el Mikrotik ni reinicia la sesión del cliente.
Route::post('/pppoe/{pppoe}/contrato',   [PppoeAccountController::class, 'linkContract'])->name('pppoe.link_contract');
Route::delete('/pppoe/{pppoe}/contrato', [PppoeAccountController::class, 'unlinkContract'])->name('pppoe.unlink_contract');
Route::delete('/pppoe/{pppoe}',            [PppoeAccountController::class, 'destroy'])->name('pppoe.destroy');
Route::post('/pppoe/import/{router}',      [PppoeAccountController::class, 'importFromRouter'])->name('pppoe.import');
Route::get('/api/routers/{router}/active-sessions', [PppoeAccountController::class, 'apiActiveSessions'])->name('api.routers.sessions');

/*
|--------------------------------------------------------------------------
| Informes gerenciales
|--------------------------------------------------------------------------
| Lectura estadística de la operación. Todas las pantallas comparten
| los filtros (desde, hasta, granularidad, sucursal) y cada una tiene
| su descarga en PDF con esos mismos parámetros.
*/
Route::get('/informes',                    [ManagementReportController::class, 'index'])->name('reports.index');
Route::get('/informes/crecimiento',        [ManagementReportController::class, 'crecimiento'])->name('reports.growth');
Route::get('/informes/tecnicas',           [ManagementReportController::class, 'tecnicas'])->name('reports.technical');
Route::get('/informes/facturacion',        [ManagementReportController::class, 'facturacion'])->name('reports.billing');
Route::get('/informes/aprovisionamiento',  [ManagementReportController::class, 'aprovisionamiento'])->name('reports.provisioning');

Route::get('/informes/pdf',                [ManagementReportController::class, 'resumenPdf'])->name('reports.summary.pdf');
Route::get('/informes/crecimiento/pdf',    [ManagementReportController::class, 'crecimientoPdf'])->name('reports.growth.pdf');
Route::get('/informes/tecnicas/pdf',       [ManagementReportController::class, 'tecnicasPdf'])->name('reports.technical.pdf');
Route::get('/informes/facturacion/pdf',    [ManagementReportController::class, 'facturacionPdf'])->name('reports.billing.pdf');
Route::get('/informes/aprovisionamiento/pdf', [ManagementReportController::class, 'aprovisionamientoPdf'])->name('reports.provisioning.pdf');
