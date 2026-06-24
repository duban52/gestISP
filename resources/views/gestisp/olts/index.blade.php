@extends('adminlte::page')
@section('title', 'OLTs')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR OLT´S</h2>
    </div>
@endsection

@section('content')
    <div class="card p-3 text-right">
        <a href="{{ route('olts.create') }}">
            <button class="btn btn-primary">Agregar OLT</button>
        </a>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <div class="table-responsive">
                <table id="oltsTable" class="table table-striped table-bordered" style="width:100%">
                    <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Dirección IP</th>
                        <th>Estado</th>
                        <th>Temperatura</th>
                        <th>Uptime</th>
                        <th>ONUs</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('css')
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
@endsection

@section('js')
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function () {
            const dt = $('#oltsTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json',
                    emptyTable: 'Cargando OLTs...'
                },
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: [5, 6] }
                ]
            });

            fetch("{{ route('api.olts') }}")
                .then(r => r.json())
                .then(data => {
                    data.forEach(olt => {
                        const statusBadge = olt.status_text === 'Conectado'
                            ? `<span class="badge badge-success">${olt.status_text}</span>`
                            : `<span class="badge badge-danger">${olt.status_text}</span>`;

                        const temperature = olt.temperature !== null
                            ? `<i class="fas fa-thermometer-half"></i> ${olt.temperature}`
                            : 'N/A';

                        dt.row.add([
                            olt.name,
                            olt.ip_address,
                            statusBadge,
                            temperature,
                            olt.uptime ?? 'N/A',
                            `<a href="#">ONUs</a>`,
                            `<a href="olts/${olt.id}/edit" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> Editar
                            </a>`,
                        ]);
                    });

                    dt.draw();
                })
                .catch(error => {
                    console.error('Error al cargar las OLTs:', error);
                    dt.clear();
                    dt.row.add([
                        '<span class="text-danger">Error al cargar las OLTs</span>',
                        '', '', '', '', '', ''
                    ]).draw();
                });
        });
    </script>
@endsection
