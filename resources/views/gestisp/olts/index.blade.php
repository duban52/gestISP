@extends('adminlte::page')
@section('title', 'OLTs')
@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR OLT´S</h2>
    </div>
@endsection
@section('content')

    <!-- Loader -->
    <div id="loader" class="text-center my-5">
        <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
        <p class="mt-3">Cargando OLTs...</p>
    </div>

    <!-- Contenedor para las OLTs -->
    <div id="olts-container" style="display:none;">
        <table class="table table-striped">
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
            <tbody id="olts-table-body">
            <!-- Filas de OLTs se llenarán con JavaScript -->
            </tbody>
        </table>
    </div>

@endsection

@push('js')
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            fetch("{{ route('api.olts') }}")
                .then(response => response.json())
                .then(data => {
                    const tableBody = document.getElementById("olts-table-body");

                    data.forEach(olt => {
                        const statusBadge = olt.status_text === 'Conectado'
                            ? `<span class="badge bg-success">${olt.status_text}</span>`
                            : `<span class="badge bg-danger">${olt.status_text}</span>`;

                        const temperature = olt.temperature !== null
                            ? `<i class="fas fa-thermometer-half"></i> ${olt.temperature}`
                            : 'N/A';

                        const row = `
                        <tr>
                            <td>${olt.name}</td>
                            <td>${olt.ip_address}</td>
                            <td>${statusBadge}</td>
                            <td>${temperature}</td>
                            <td>${olt.uptime ?? 'N/A'}</td>
                            <td><a href="#">ONUs</a></td>
                            <td>
                                <a href="#" class="btn btn-sm btn-primary">Editar</a>
                            </td>
                        </tr>
                    `;
                        tableBody.insertAdjacentHTML('beforeend', row);
                    });

                    document.getElementById("loader").style.display = "none";
                    document.getElementById("olts-container").style.display = "block";
                })
                .catch(error => {
                    console.error("Error al cargar las OLTs:", error);
                    document.getElementById("loader").innerHTML = '<p class="text-danger">Error al cargar las OLTs</p>';
                });
        });
    </script>
@endpush
