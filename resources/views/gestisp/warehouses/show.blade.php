@extends('adminlte::page')

@section('title', 'Inventario de Almacén')

@section('content_header')
    <div class="card p-3 d-flex flex-row justify-content-between align-items-center">
        <h2 class="mb-0">INVENTARIO DE {{ strtoupper($warehouse->description) }}</h2>
        <a href="{{ route('warehouses.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Volver
        </a>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Resumen y acciones del almacén
         ============================================================ --}}
    @php
        // El índice de la columna «Acciones» se mueve según haya o no
        // columnas de costo. DataTables la marca como no ordenable por
        // POSICIÓN, así que el número tiene que salir de aquí y no
        // escrito a mano abajo.
        $verCostos = auth()->check() && \Illuminate\Support\Facades\Gate::allows('materials.costs');
        $columnaAcciones = $verCostos ? 5 : 3;
    @endphp

    <div class="card">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <strong>Materiales distintos:</strong>
                <span class="badge badge-info">{{ $inventoriesData->count() }}</span>

                @can('materials.costs')
                    {{-- EL TOTAL DICE SI ESTÁ COMPLETO.
                         Lo que no tiene costo registrado no se suma como
                         cero: se avisa. Un total que se traga el material
                         sin precio como si fuera gratis es peor que no
                         tener total, porque nadie duda de un número. --}}
                    <span class="ml-4"><strong>Valor del inventario:</strong></span>
                    <span class="badge badge-{{ $resumenValor['completo'] ? 'success' : 'warning' }}">
                        {{ $resumenValor['completo'] ? '' : 'desde ' }}${{ number_format($resumenValor['total'], 2) }}
                    </span>

                    @if(!$resumenValor['completo'])
                        <small class="text-muted ml-1">
                            ({{ $resumenValor['materiales_sin_valorar'] }}
                            {{ $resumenValor['materiales_sin_valorar'] === 1 ? 'material' : 'materiales' }}
                            sin valor de compra registrado)
                        </small>
                    @endif
                @endcan
            </div>

            {{-- Exportar el inventario completo a PDF --}}
            <a href="{{ route('warehouse.pdf', $warehouse->id) }}"
               class="btn btn-danger"
               title="Descargar PDF">
                <i class="fas fa-file-pdf"></i> Descargar PDF
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla principal de inventario (DataTables)

         La búsqueda, ordenamiento y paginación corren en el
         navegador. El controlador debe enviar la colección completa
         (get() en lugar de paginate), ver nota al final.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="inventoryTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Artículo</th>
                        <th>Cantidad</th>
                        <th>Unidad de medida</th>
                        @can('materials.costs')
                            <th class="text-right">Valor unitario</th>
                            <th class="text-right">Valor total</th>
                        @endcan
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($inventoriesData as $inventoryData)
                        <tr>
                            <td>{{ $inventoryData['material']->name }}</td>

                            {{-- Cantidad con alerta visual de stock bajo --}}
                            <td>
                                    <span class="badge {{ $inventoryData['quantity'] <= 5 ? 'badge-danger' : 'badge-success' }}">
                                        {{ $inventoryData['quantity'] }}
                                    </span>
                            </td>

                            <td>{{ $inventoryData['unit_of_measurement'] }}</td>

                            @can('materials.costs')
                                {{-- «—» y no «$0,00»: no saber lo que costó
                                     no es que fuera gratis, y en una cifra
                                     que se usa para decidir esa diferencia
                                     es la que importa. --}}
                                <td class="text-right">
                                    @if($inventoryData['valor']['unitario'] !== null)
                                        ${{ number_format($inventoryData['valor']['unitario'], 2) }}
                                    @else
                                        <span class="text-muted" title="Sin valor de compra registrado">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($inventoryData['valor']['total'] !== null)
                                        ${{ number_format($inventoryData['valor']['total'], 2) }}
                                        @if($inventoryData['valor']['unidades_sin_valorar'] > 0)
                                            <i class="fas fa-exclamation-triangle text-warning ml-1"
                                               title="{{ (int) $inventoryData['valor']['unidades_sin_valorar'] }} unidades sin valor de compra: el total es parcial."></i>
                                        @endif
                                    @else
                                        <span class="text-muted" title="Sin valor de compra registrado">—</span>
                                    @endif
                                </td>
                            @endcan

                            <td>
                                {{-- Solo los equipos (con número de serie) tienen
                                     el detalle de SNs en modal --}}
                                @if($inventoryData['material']->is_equipment)
                                    <button type="button"
                                            class="btn btn-primary btn-sm"
                                            data-toggle="modal"
                                            data-target="#modal-{{ $inventoryData['material']->id }}">
                                        <i class="fas fa-barcode"></i> Ver SNs
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modales de números de serie (uno por material tipo equipo)

         Cada modal contiene su propia DataTable con los SNs del
         equipo, con búsqueda propia — útil para localizar un
         serial específico entre cientos.
         ============================================================ --}}
    @foreach($inventoriesData as $inventoryData)
        @if($inventoryData['material']->is_equipment)
            <div class="modal fade"
                 id="modal-{{ $inventoryData['material']->id }}"
                 tabindex="-1" role="dialog"
                 aria-labelledby="modalLabel-{{ $inventoryData['material']->id }}"
                 aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="modalLabel-{{ $inventoryData['material']->id }}">
                                <i class="fas fa-barcode"></i>
                                SNs de {{ $inventoryData['material']->name }}
                                <span class="badge badge-light ml-2">
                                    {{ count($inventoryData['sns']) }} unidades
                                </span>
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-bordered sns-table"
                                       id="sns-table-{{ $inventoryData['material']->id }}"
                                       style="width:100%">
                                    <thead>
                                    <tr>
                                        <th>SN</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($inventoryData['sns'] as $sn)
                                        <tr>
                                            <td>{{ $sn }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endsection

{{-- ============================================================
     Estilos de DataTables

     Tema Bootstrap 4: AdminLTE 3 está construido sobre Bootstrap 4,
     el CSS de Bootstrap 5 que había antes causa desajustes visuales.
     ============================================================ --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

{{-- ============================================================
     Scripts de DataTables

     NOTA: no se carga jQuery aquí — AdminLTE ya lo incluye.
     Cargarlo dos veces reinicializa $ y rompe los plugins ya
     registrados (era un bug latente de la versión anterior).
     ============================================================ --}}
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            // ---- Tabla principal de inventario ----
            $('#inventoryTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Este almacén no tiene materiales en inventario.'
                },
                pageLength: 25,
                // Orden inicial: por artículo ascendente
                order: [[0, 'asc']],
                columnDefs: [
                    // La columna de acciones no es ordenable. El índice
                    // se calcula en Blade porque se desplaza cuando se
                    // pintan las columnas de costo.
                    { orderable: false, targets: [{{ $columnaAcciones }}] },
                    // Evita el warning cuando una celda llega vacía
                    { defaultContent: '', targets: '_all' }
                ]
            });

            // ---- Tablas de SNs dentro de los modales ----
            $('.sns-table').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay números de serie registrados.'
                },
                pageLength: 10
            });

            /**
             * Corrección del ancho de DataTables dentro de modales.
             *
             * Las tablas inicializadas mientras el modal está oculto
             * calculan mal el ancho de sus columnas (bug conocido).
             * Al mostrarse el modal se recalculan las columnas de
             * las tablas que contiene.
             */
            $('.modal').on('shown.bs.modal', function () {
                $(this).find('.sns-table').each(function () {
                    $(this).DataTable().columns.adjust();
                });
            });
        });
    </script>
@endsection
