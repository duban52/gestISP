@extends('adminlte::page')
    @section('title', 'Agregar olt')
@section('content_header')
    <div class="card p-3">
        <h2>AGREGAR OLT</h2>
    </div>
@endsection
@section('content')
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('olts.store') }}" enctype="multipart/form-data">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal de la OLT"
                                     ayuda="El equipo queda en esta sucursal, igual que las ONTs que se conecten a el." />

                <div class="row">

                    <div class="form-group col-12 col-md-6">
                        <label for="name">Nombre de la OLT</label>
                        <input type="text" class="form-control" id="name" name="name"
                               placeholder="Ingrese un nombre para la OLT" minlength="5" maxlength="255"
                               value="{{ old('name') }}">
                        @error('name')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ip_address">Dirección IP</label>
                        <input type="text" class="form-control" id="ip_address" name="ip_address"
                               placeholder="Ingrese la dirección IP de la OLT" minlength="5" maxlength="255"
                               value="{{ old('ip_address') }}">
                        @error('ip_address')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="ssh_port">Puerto SSH</label>
                        <input type="text" class="form-control" id="ssh_port" name="ssh_port"
                               placeholder="Ingrese el puerto SSH de la OLT"
                               value="{{ old('ssh_port') }}">
                        @error('ssh_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="telnet_port">Puerto Telnet</label>
                        <input type="text" class="form-control" id="telnet_port" name="telnet_port"
                               placeholder="Ingrese el puerto Telnet de la OLT"
                               value="{{ old('telnet_port') }}">
                        @error('telnet_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="snmp_port">Puerto SNMP</label>
                        <input type="text" class="form-control" id="snmp_port" name="snmp_port"
                               placeholder="Ingrese el puerto SNMP de la OLT"
                               value="{{ old('snmp_port') }}">
                        @error('snmp_port')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="read_snmp_comunity">Comunidad SNMP Lectura</label>
                        <input type="text" class="form-control" id="read_snmp_comunity" name="read_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de lectura"
                               value="{{ old('read_snmp_comunity') }}">
                        @error('read_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="write_snmp_comunity">Comunidad SNMP Escritura</label>
                        <input type="text" class="form-control" id="write_snmp_comunity" name="write_snmp_comunity"
                               placeholder="Ingrese la comunidad SNMP de escritura"
                               value="{{ old('write_snmp_comunity') }}">
                        @error('write_snmp_comunity')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="username">Usuario de acceso</label>
                        <input type="text" class="form-control" id="username" name="username"
                               placeholder="Ingrese el nombre de usuario"
                               value="{{ old('username') }}">
                        @error('username')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="password">Contraseña de acceso</label>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Ingrese la contraseña de acceso">
                        @error('password')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="brand">Marca</label>
                        <input type="text" class="form-control" id="brand" name="brand"
                               placeholder="Ingrese la marca de la OLT"
                               value="{{ old('brand') }}">
                        @error('brand')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-group col-12 col-md-6">
                        <label for="model">Modelo</label>
                        <input type="text" class="form-control" id="model" name="model"
                               placeholder="Ingrese el modelo de la OLT"
                               value="{{ old('model') }}">
                        @error('model')
                        <span class="text-danger">* {{ $message }}</span>
                        @enderror
                    </div>

                </div>

                <div class="col-12 text-center">
                    <input type="submit" value="Agregar OLT" class="btn btn-primary col-md-3">
                </div>

            </form>
        </div>
    </div>
@endsection


