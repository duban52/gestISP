@extends('adminlte::page')

@section('title', 'Categoría de materiales')

@section('content_header')
    <div class="card p-3">
        <h2>ADMINISTRAR CATEGORÍAS</h2>
    </div>

@endsection

@section('content')
    @if(session('success-create'))
        <div class="alert alert-info">
            {{ session('success-create') }}
        </div>
    @elseif(session('success-update'))
        <div class="alert alert-info">
            {{ session('success-update') }}
        </div>
    @elseif(session('success-delete'))
        <div class="alert alert-info">
            {{ session('success-delete') }}
        </div>
    @endif

    <div class="card p-3">
        <div class="d-flex justify-content-end">
            <a href="{{ route('categories.create') }}" class="btn btn-primary">Nueva Categoría</a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover">
                <table-body>
                    <tr>
                        @if($mostrarSucursal)<th>Sucursal</th>@endif
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th></th>
                    </tr>
                    @foreach($categories as $category)
                        <tr>
                            {{-- Tabla simple paginada en servidor, sin DataTables:
                                 la columna se condiciona aqui. --}}
                            @if($mostrarSucursal)
                                <td>{{ $category->branch?->name ?: '—' }}</td>
                            @endif
                            <td>{{ $category->name }}</td>
                            <td>{{ $category->description }}</td>
                            <td class="text-right"><a href="{{route('categories.edit', $category)}}" class="btn btn-warning">Editar</a></td>
                        </tr>

                    @endforeach
                </table-body>
            </table>
        </div>

        <div class="text-center">
            {{ $categories->links() }}
        </div>
    </div>
@endsection
