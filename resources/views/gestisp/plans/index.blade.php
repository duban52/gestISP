@extends('adminlte::page')

@section('title', 'Planes')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR PLANES DE SERVICIO</h2>
    </div>
@endsection

@section('content')
    {{-- ============================================================
         Alertas de sesión (resultado de crear/editar/eliminar)
         ============================================================ --}}
    @if(session('success-create'))
        <div class="alert alert-success">{{ session('success-create') }}</div>
    @elseif(session('success-update'))
        <div class="alert alert-warning">{{ session('success-update') }}</div>
    @elseif(session('success-delete'))
        <div class="alert alert-danger">{{ session('success-delete') }}</div>
    @elseif(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         Botón de creación
         ============================================================ --}}
    <div class="card">
        <div class="card-header d-flex justify-content-end">
            <a class="btn btn-primary" href="{{ route('plans.create') }}">
                Crear Plan <i class="fas fa-plus-circle"></i>
            </a>
        </div>
    </div>

    {{-- ============================================================
         Tabla de planes (DataTables)

         Cada plan muestra sus servicios asociados como badges y el
         precio total calculado (suma de base + impuesto de cada
         servicio). Los servicios vienen precargados con with()
         desde el controlador para evitar consultas N+1.
         ============================================================ --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="plansTable" class="table table-hover table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Servicios incluidos</th>
                        <th>Precio Total</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($plans as $plan)
                        @php
                            /**
                             * Precio total del plan: suma del precio final
                             * (base + impuesto) de cada servicio asociado.
                             */
                            $total = $plan->services->sum(
                                fn($s) => $s->base_price * (1 + $s->tax_percentage / 100)
                            );
                        @endphp
                        <tr>
                            <td>{{ $plan->name }}</td>

                            {{-- Servicios del plan como badges --}}
                            <td>
                                @forelse($plan->services as $service)
                                    <span class="badge badge-info">
                                            {{ $service->name }}
                                        </span>
                                @empty
                                    <span class="text-muted">Sin servicios asociados</span>
                                @endforelse
                            </td>

                            {{-- Precio total con formato de moneda --}}
                            <td>${{ number_format($total, 0, ',', '.') }}</td>

                            <td>
                                {{-- Editar plan --}}
                                <a class="btn btn-warning btn-sm"
                                   href="{{ route('plans.edit', $plan) }}"
                                   title="Editar Plan">
                                    <i class="fas fa-pencil-alt"></i> Modificar
                                </a>

                                {{-- Eliminar plan (abre modal de confirmación) --}}
                                <button
                                    class="btn btn-danger btn-sm btn-eliminar-plan"
                                    data-id="{{ $plan->id }}"
                                    data-nombre="{{ $plan->name }}"
                                    title="Eliminar">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ============================================================
         Modal de confirmación de eliminación

         Un único modal reutilizable: el botón de cada fila carga
         el nombre y arma la URL del formulario dinámicamente.
         ============================================================ --}}
    <div class="modal fade" id="modalEliminarPlan" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Eliminar Plan</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>¿Está seguro que desea eliminar el plan
                        <strong id="eliminarPlanNombre"></strong>?</p>
                    <p class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        Esta acción no se puede deshacer. Si el plan tiene
                        contratos asociados, la eliminación será bloqueada.
                    </p>
                </div>
                <div class="modal-footer">
                    <form id="formEliminarPlan" method="POST">
                        @csrf
                        @method('DELETE')
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger">Sí, eliminar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

{{-- ============================================================
     Estilos de DataTables (tema Bootstrap 4, compatible con AdminLTE)
     ============================================================ --}}
@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

{{-- ============================================================
     Scripts de DataTables e inicialización
     ============================================================ --}}
@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            $('#plansTable').DataTable({
                // Traducción al español desde el CDN oficial de plugins
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'No hay planes registrados.'
                },

                // Registros visibles por página
                pageLength: 25,

                // Orden inicial: por nombre (columna 0) ascendente
                order: [[0, 'asc']],

                columnDefs: [
                    // Servicios (badges) y acciones no son ordenables
                    { orderable: false, targets: [1, 3] },

                    // Evita el warning de DataTables cuando una celda llega vacía
                    { defaultContent: '—', targets: '_all' }
                ]
            });
        });

        /**
         * Abre el modal de confirmación de eliminación.
         * Toma el id y nombre del botón pulsado, arma la URL
         * del formulario DELETE y muestra el modal.
         */
        document.addEventListener('click', function (e) {
            if (e.target.closest('.btn-eliminar-plan')) {
                const btn = e.target.closest('.btn-eliminar-plan');

                document.getElementById('eliminarPlanNombre').textContent =
                    btn.getAttribute('data-nombre');
                document.getElementById('formEliminarPlan').action =
                    `/plans/${btn.getAttribute('data-id')}`;

                $('#modalEliminarPlan').modal('show');
            }
        });
    </script>
@endsection
