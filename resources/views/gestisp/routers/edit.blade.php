@extends('adminlte::page')
@section('title', 'Editar Router')

@section('content_header')
    <div class="card p-3">
        <h2>EDITAR ROUTER — {{ $router->name }}</h2>
    </div>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('routers.update', $router) }}">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="{{ old('name', $router->name) }}" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Dirección IP <span class="text-danger">*</span></label>
                            <input type="text" name="ip_address" class="form-control"
                                   value="{{ old('ip_address', $router->ip_address) }}" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Puerto API <span class="text-danger">*</span></label>
                            <input type="number" name="api_port" class="form-control"
                                   value="{{ old('api_port', $router->api_port) }}" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Usuario <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control"
                                   value="{{ old('username', $router->username) }}" required>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Contraseña</label>
                            <input type="password" name="password" class="form-control"
                                   placeholder="Dejar vacío para conservar la actual">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Modelo</label>
                            <input type="text" name="model" class="form-control"
                                   value="{{ old('model', $router->model) }}">
                        </div>
                    </div>
                </div>

                <div class="text-right">
                    <a href="{{ route('routers.index') }}" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Actualizar Router
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
