@extends('adminlte::page')

@section('title', 'Clientes')

{{-- Agregar CSS de DataTables --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
    <link rel="stylesheet" href="{{asset('/css/gestisp/styles.css')}}">
@endsection

@section('content')
    <div class="card mt-3">
        <div class="card-head pt-3">
            <div class="row d-flex justify-content-between mb-4 pr-3">
                <div class="col-md-8">
                    <h2 class="ml-2 P3">LISTADO DE CLIENTES</h2>
                    <div class="ml-2">
                        <span class="badge badge-info badge-lg">
                            Total Contratos: {{ $contracts->count() }}
                        </span>
                    </div>
                </div>
                <div class="col-md-4 text-center text-md-right">
                    <a href="{{ route('contracts.export') }}" class="btn btn-success" title="Exportar contratos a Excel">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </a>
                </div>
            </div>

            @if(session('success-delete'))
                <div class="alert alert-danger">
                    {{ session('success-delete') }}
                </div>
            @elseif(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table id="contractsTable" class="table table-hover table-striped">
                    <thead>
                    <tr>
                        <th>Número de contrato</th>
                        <th>Número de documento</th>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Teléfono</th>
                        <th>Correo electrónico</th>
                        <th>Dirección</th>
                        <th>Usuario PPPoE</th>
                        <th>Estado</th>
                        <th>Fecha de activación</th>
                        <th>Plan</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($contracts as $contract)
                        <tr>
                            <td>{{ $contract->id }}</td>
                            <td>{{ $contract->client->identity_number }}</td>
                            <td>{{ $contract->client->name }}</td>
                            <td>{{ $contract->client->last_name }}</td>
                            <td>{{ $contract->client->number_phone }}</td>
                            <td>{{ $contract->client->email }}</td>
                            <td>{{ $contract->address }}</td>
                            <td>{{ $contract->user_pppoe }}</td>
                            <td>
                                @switch($contract->status)
                                    @case('Activo')
                                        <span class="badge badge-success">{{ ucfirst($contract->status) }}</span>
                                        @break
                                    @case('Cortadp')
                                        <span class="badge badge-danger">{{ ucfirst($contract->status) }}</span>
                                        @break
                                    @case('Suspendido')
                                        <span class="badge badge-warning">{{ ucfirst($contract->status) }}</span>
                                        @break
                                    @case('Por Instalar')
                                        <span class="badge badge-secondary">{{ ucfirst($contract->status) }}</span>
                                        @break
                                    @default
                                        <span class="badge badge-info">{{ $contract->status }}</span>
                                @endswitch
                            </td>
                            <td>{{ $contract->activation_date ? \Carbon\Carbon::parse($contract->activation_date)->format('d/m/Y') : 'N/A' }}</td>
                            <td>{{ $contract->plan->name ?? 'N/A' }}</td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="{{ route('contracts.show', $contract) }}"
                                       class="btn btn-info btn-sm"
                                       title="Ver contrato">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('contracts.edit', $contract) }}"
                                       class="btn btn-warning btn-sm"
                                       title="Editar contrato">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal para configurar columnas -->
    <div class="modal fade" id="columnModal" tabindex="-1" role="dialog" aria-labelledby="columnModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="columnModalLabel">Configurar Columnas Visibles</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_contract" data-column="0" checked>
                        <label class="form-check-label" for="col_contract">Número de contrato</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_document" data-column="1" checked>
                        <label class="form-check-label" for="col_document">Número de documento</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_name" data-column="2" checked>
                        <label class="form-check-label" for="col_name">Nombre</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_lastname" data-column="3" checked>
                        <label class="form-check-label" for="col_lastname">Apellido</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_phone" data-column="4" checked>
                        <label class="form-check-label" for="col_phone">Teléfono</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_email" data-column="5" checked>
                        <label class="form-check-label" for="col_email">Correo electrónico</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_address" data-column="6" checked>
                        <label class="form-check-label" for="col_address">Dirección</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_pppoe" data-column="7" checked>
                        <label class="form-check-label" for="col_pppoe">Usuario PPPoE</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_status" data-column="8" checked>
                        <label class="form-check-label" for="col_status">Estado</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_activation" data-column="9" checked>
                        <label class="form-check-label" for="col_activation">Fecha de activación</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input toggle-column" id="col_plan" data-column="10" checked>
                        <label class="form-check-label" for="col_plan">Plan</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" data-dismiss="modal">Aplicar</button>
                </div>
            </div>
        </div>
    </div>
@endsection

{{-- Agregar JavaScript de DataTables --}}
@section('js')
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            // Inicializar DataTable
            var table = $('#contractsTable').DataTable({
                // Configuración básica
                "processing": true,
                "responsive": true,
                "autoWidth": false,

                // Configuración de idioma en español
                "language": {
                    "url": "https://cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json"
                },

                // Configuración de paginación
                "pageLength": 25,
                "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Todos"]],

                // Configuración de ordenamiento
                "order": [[0, "desc"]], // Ordenar por número de contrato descendente por defecto

                // Configuración de columnas
                "columnDefs": [
                    {
                        "targets": [0, 1], // Columnas ID contrato y documento
                        "className": "text-center"
                    },
                    {
                        "targets": [4], // Columna teléfono
                        "className": "text-center"
                    },
                    {
                        "targets": [8], // Columna Estado
                        "className": "text-center"
                    },
                    {
                        "targets": [9], // Columna Fecha de activación
                        "type": "date",
                        "className": "text-center"
                    },
                    {
                        "targets": [11], // Columna Acciones
                        "orderable": false,
                        "searchable": false,
                        "className": "text-center"
                    }
                ],

                // Botones de exportación
                "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"<"float-right"B>>>frtip',
                "buttons": [
                    {
                        text: '<i class="fas fa-columns"></i> Columnas',
                        className: 'btn btn-secondary btn-sm',
                        action: function(e, dt, node, config) {
                            $('#columnModal').modal('show');
                        }
                    },
                    {
                        extend: 'copy',
                        text: '<i class="fas fa-copy"></i> Copiar',
                        className: 'btn btn-secondary btn-sm'
                    },
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file-csv"></i> CSV',
                        className: 'btn btn-success btn-sm'
                    },
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm'
                    },
                    {
                        extend: 'pdf',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        orientation: 'landscape',
                        pageSize: 'A4'
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Imprimir',
                        className: 'btn btn-info btn-sm'
                    }
                ]
            });

            // Funcionalidad para mostrar/ocultar columnas
            $('.toggle-column').on('change', function() {
                var column = table.column($(this).data('column'));
                column.visible($(this).is(':checked'));
            });

            // Funcionalidad para filtros personalizados
            $('#contractsTable_filter input').attr('placeholder', 'Buscar en todos los campos...');
        });
    </script>
@endsection
