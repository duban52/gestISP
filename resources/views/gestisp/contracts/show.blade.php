@extends('adminlte::page')

@section('title', 'Detalles de contrato')

@section('content_header')
    <h4 class="text-center">DETALLES DEL CONTRATO</h4>
@endsection

@section('content')

    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if(session('success'))
        <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="successModalLabel">Éxito</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        {{ session('success') }}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-success" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
        <div class="row d-flex justify-content-center">

            <div class="card col-md-10">
                <div class="card-head d-flex justify-content-between p-3">
                    <p><strong>Número de contrato:</strong> {{$contract->id}}</p>
                    <p><strong>Estado:</strong>
                        <strong
                            @if($contract->status == 'Activo') class="text-success"
                            @else class="text-danger"
                            @endif
                        >
                            {{ $contract->status }}
                        </strong>
                    </p>
                </div>
            </div>
            <div class="card col-md-5 ml-md-1 mr-md-1">
                <div class="card-header row">
                    <div class="col-md-9 col-8">
                        <h3><i class="far fa-user"></i> Datos personales</h3>
                    </div>

                    <div class="col-4 col-md-3 text-right">
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#editPersonalData">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>
                    <!-- Modal -->
                    <div class="modal fade" id="editPersonalData" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="exampleModalLabel">Modificar datos personales</h5>
                                    <button type="button" class="btn-danger" data-bs-dismiss="modal" aria-label="Close"><i class="far fa-window-close"></i></button>
                                </div>
                                <div class="modal-body">
                                    <form action="{{ route('clients.update', $contract->client->id) }}" method="post">
                                        @csrf
                                        @method('put')
                                        <div class="form-group">
                                            <label for="">Número de teléfono principal:</label>
                                            <input type="text" name="number_phone" class="form-control" value="{{ $contract->client->number_phone }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Número de teléfono adicional:</label>
                                            <input type="text" name="aditional_phone" class="form-control" value="{{ $contract->client->aditional_phone }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Correo electrónico:</label>
                                            <input type="text" name="email" class="form-control" value="{{ $contract->client->email }}">
                                        </div>
                                        <div class="text-center">
                                            <hr>
                                            <input type="submit" class="btn btn-success" value="Guardar">
                                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>


                </div>
                <div class="card-body row">
                    <p class="col-6"><strong>Número de Documento:</strong> {{ $contract->client->identity_number }}</p>
                    <p class="col-6"><strong>Nombre completo:</strong> {{ $contract->client->name }} {{ $contract->client->last_name }}</p>
                    <p class="col-6"><strong>Tipo de cliente:</strong> {{ $contract->client->type_client }}</p>
                    <p class="col-6"><strong>Teléfono:</strong> {{ $contract->client->number_phone }}</p>
                    <p class="col-6"><strong>Teléfono adicional:</strong> {{ $contract->client->aditional_phone }}</p>
                    <p class="col-6"><strong>Email:</strong> {{ $contract->client->email }}</p>
                    <p class="col-6"><strong>Fecha de nacimiento:</strong> {{ $contract->client->birthday }}</p>
                    <p class="col-6"><strong>Creado por:</strong> {{ $contract->client->user->name }}</p>
                </div>
            </div>

            <div class="card col-md-5 ml-md-1 mr-md-1">
                <div class="card-header row">

                    <div class="col-8 col-md-9">
                        <h3><i class="fas fa-map-marked-alt"></i> Datos de residencia</h3>
                    </div>
                    <div class="col-4 col-md-3 text-right">
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#editAddressData">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>

                    <!-- Modal -->
                    <div class="modal fade" id="editAddressData" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="exampleModalLabel">Modificar datos de residencia</h5>
                                    <button type="button" class="btn-danger" data-bs-dismiss="modal" aria-label="Close"><i class="far fa-window-close"></i></button>
                                </div>
                                <div class="modal-body">
                                    <form action="{{ route('contracts.update', $contract->id) }}" method="post">
                                        @csrf
                                        @method('put')
                                        <div class="form-group">
                                            <label for="">Departamento:</label>
                                            <input type="text" name="department" class="form-control" value="{{ $contract->department ?? 'N/A' }}">
                                        </div>

                                        <div class="form-group">
                                            <label for="">Municipio:</label>
                                            <input type="text" name="municipality" class="form-control" value="{{ $contract->municipality ?? 'N/A' }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Barrio:</label>
                                            <input type="text" name="neighborhood" class="form-control" value="{{ $contract->neighborhood }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Dirección:</label>
                                            <input type="text" name="address" class="form-control" value="{{ $contract->address }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Tipo de vivienda:</label>
                                            <select name="home_type" id="" class="form-control">
                                                <option value="{{ $contract->home_type }}">{{ $contract->home_type }}</option>
                                                <option value="Propia">Propia</option>
                                                <option value="En Arriendo">En Arriendo</option>
                                                <option value="Otra">Otra</option>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="">Estrato social:</label>
                                            <select name="social_stratum" id="" class="form-control">
                                                <option value="{{ $contract->social_stratum }}">{{ $contract->social_stratum }}</option>
                                                <option value="1">1</option>
                                                <option value="2">2</option>
                                                <option value="3">3</option>
                                                <option value="4">4</option>
                                                <option value="5">5</option>
                                                <option value="6">6</option>
                                            </select>
                                        </div>
                                        <div class="text-center">
                                            <hr>
                                            <input type="submit" class="btn btn-success" value="Guardar">
                                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <div class="card-body row">
                    <p class="col-6"><strong>Departamento:</strong> {{ $contract->department ?? 'N/A' }}</p>
                    <p class="col-6"><strong>Municipio:</strong> {{ $contract->municipality ?? 'N/A' }}</p>
                    <p class="col-6"><strong>Barrio:</strong> {{ $contract->neighborhood }}</p>
                    <p class="col-6"><strong>Dirección:</strong> {{ $contract->address }}</p>
                    <p class="col-6"><strong>Tipo de vivienda:</strong> {{ $contract->home_type }}</p>
                    <p class="col-6"><strong>Estrato social:</strong> {{ $contract->social_stratum }}</p>
                </div>
            </div>
            <div class="card col-md-5 ml-md-1 mr-md-1">
                <div class="card-header row">
                    <div class="col-8 col-md-9">
                        <h3><i class="fas fa-network-wired"></i> Datos del servicio</h3>
                    </div>

                    <div class="col-4 col-md-3 text-right">
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#editServiceData">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>

                    <!-- Modal -->
                    <div class="modal fade" id="editServiceData" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="exampleModalLabel">Modificar datos del servicio</h5>
                                    <button type="button" class="btn-danger" data-bs-dismiss="modal" aria-label="Close"><i class="far fa-window-close"></i></button>
                                </div>
                                <div class="modal-body">
                                    <form action="{{ route('contracts.update', $contract->id) }}" method="post">
                                        @csrf
                                        @method('put')
                                        <div class="form-group">
                                            <label for="">Plan:</label>
                                            <select name="plan_id" id="" class="form-control">
                                                @foreach($plans as $plan)
                                                    <option  value="{{ $plan->id }}" {{ $contract->plan_id == $plan->id ? 'selected' : ''}}>
                                                        {{ $plan->name }}
                                                    </option>
                                                @endforeach

                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="">Cláusula de permanencia:</label>
                                            <input type="number" name="permanence_clause" class="form-control" value="{{ $contract->permanence_clause }}">
                                        </div>

                                        <div class="text-center">
                                            <hr>
                                            <input type="submit" class="btn btn-success" value="Guardar">
                                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-body row">
                    <p class="col-6"><strong>Plan de servicio:</strong> {{ $contract->plan->name }}</p>
                    <p class="col-6"><strong>Clausula de permanencia:</strong> {{ $contract->permanence_clause }} Meses</p>
                </div>
            </div>
            <div class="card col-md-5 ml-md-1 mr-md-1">
                <div class="card-header row">
                    <div class="col-8 col-md-9">
                        <h3><i class="fas fa-cogs"></i> Datos técnicos</h3>
                    </div>

                    <div class="col-4 col-md-3 text-right">
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#editTechnicalData">
                            <i class="fas fa-edit"></i>
                        </button>
                    </div>

                    <!-- Modal -->
                    <div class="modal fade" id="editTechnicalData" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="exampleModalLabel">Modificar datos técnicos</h5>
                                    <button type="button" class="btn-danger" data-bs-dismiss="modal" aria-label="Close"><i class="far fa-window-close"></i></button>
                                </div>
                                <div class="modal-body">
                                    <form action="{{ route('contracts.update', $contract->id) }}" method="post">
                                        @csrf
                                        @method('put')
                                        <div class="form-group">
                                            <label for="">NAP y Puerto:</label>
                                            <input type="text" class="form-control" name="nap_port" value="{{ $contract->nap_port }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Serial del CPE:</label>
                                            <input type="text" class="form-control" name="cpe_sn" value="{{ $contract->cpe_sn }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Usuario pppoe:</label>
                                            <input type="text" class="form-control" name="user_pppoe" value="{{ $contract->user_pppoe }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Contraseña pppoe:</label>
                                            <input type="text" class="form-control" name="password_pppoe" value="{{ $contract->password_pppoe }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">SSID del Wifi:</label>
                                            <input type="text" class="form-control" name="ssid_wifi" value="{{ $contract->ssid_wifi }}">
                                        </div>
                                        <div class="form-group">
                                            <label for="">Contraseña del Wifi:</label>
                                            <input type="text" class="form-control" name="password_wifi" value="{{ $contract->password_wifi }}">
                                        </div>

                                        <div class="text-center">
                                            <hr>
                                            <input type="submit" class="btn btn-success" value="Guardar">
                                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body row">
                    <p class="col-6"><strong>NAP y puerto:</strong> {{ $contract->nap_port }}</p>
                    <p class="col-6"><strong>Serial del CPE:</strong> {{ $contract->cpe_sn }}</p>
                    <p class="col-6"><strong>Usuario PPPoE:</strong> {{ $contract->user_pppoe }}</p>
                    <p class="col-6"><strong>Contraseña PPPoE:</strong> {{ $contract->password_pppoe }}</p>
                    <p class="col-6"><strong>SSID del Wifi:</strong> {{ $contract->ssid_wifi }}</p>
                    <p class="col-6"><strong>Contraseña del Wifi:</strong> {{ $contract->password_wifi }}</p>
                    <p class="col-6"><strong>Contrato realizado por:</strong> {{ $contract->user->name }} {{ $contract->user->last_name }} </p>
                    <p class="col-6"><strong>Fecha de creación:</strong> {{ $contract->created_at }} </p>
                    <p class="col-6"><strong>Fecha de activación:</strong> {{ $contract->activation_date ?? 'N/A'}} </p>
                    <p class="col-6"><strong>Última actualización:</strong> {{ $contract->updated_at }} </p>

                </div>
            </div>

            <div class="card col-md-10">
                <div class="card-header">
                    <h3><i class="fas fa-location-arrow"></i> Acciones con el contrato</h3>
                </div>
                <div class="card-body text-center">
                    <a href="{{ route('technicals_orders.create', $contract) }}" class="btn btn-info mb-1 mt-1 col-8 col-md-3" title="Crear incidencia a contrato">Crear orden técnica</a>
                    <!-- Button trigger modal -->
                    <button type="button" class="btn btn-success mb-1 mt-1 col-8 col-md-3" data-bs-toggle="modal" data-bs-target="#staticBackdrop">
                        Agregar Cargo Adicional
                    </button>

                    <!-- Modal -->
                    <div class="modal fade" id="staticBackdrop" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="staticBackdropLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="">Agregar cargo adicional a contrato</h5>
                                    <button type="button" class="btn-danger" data-bs-dismiss="modal" aria-label="Close"><i class="far fa-window-close"></i></button>
                                </div>
                                <div class="modal-body">
                                    <form method="POST" action="{{ route('additionalCharges.store') }}">
                                        @csrf

                                        <input type="text" value="{{$contract->id}}" name="contract_id" hidden="hidden">

                                        <!-- Description -->
                                        <div class="form-group mb-3 text-left">
                                            <label for="description">Descripción:</label>
                                            <input type="text" class="form-control @error('description') is-invalid @enderror" id="description" name="description" value="{{ old('description') }}" required>
                                            @error('description')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        <!-- Amount -->
                                        <div class="form-group mb-3 text-left">
                                            <label for="amount">Monto:</label>
                                            <input type="number" step="0.01" class="form-control @error('amount') is-invalid @enderror" id="amount" name="amount" value="{{ old('amount') }}" required>
                                            @error('amount')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>

                                        {{-- Diferir a cuotas: cada factura mensual
                                             incluirá una cuota hasta completar el cargo --}}
                                        <div class="form-group mb-2 text-left">
                                            <div class="form-check">
                                                <input type="checkbox" class="form-check-input" id="defer_charge"
                                                       {{ old('installments_total') ? 'checked' : '' }}>
                                                <label class="form-check-label" for="defer_charge">
                                                    Diferir a cuotas
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group mb-3 text-left" id="installments_group"
                                             style="{{ old('installments_total') ? '' : 'display:none;' }}">
                                            <label for="installments_total">Número de cuotas (2 a 36):</label>
                                            <input type="number" min="2" max="36"
                                                   class="form-control @error('installments_total') is-invalid @enderror"
                                                   id="installments_total" name="installments_total"
                                                   value="{{ old('installments_total') }}">
                                            <small class="form-text text-muted">
                                                Cada factura mensual incluirá una cuota
                                                (la última ajusta el redondeo) hasta completar el valor.
                                            </small>
                                            @error('installments_total')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <script>
                                            document.getElementById('defer_charge').addEventListener('change', function () {
                                                const group = document.getElementById('installments_group');
                                                const input = document.getElementById('installments_total');
                                                group.style.display = this.checked ? '' : 'none';
                                                if (!this.checked) input.value = '';
                                            });
                                        </script>
                                        <hr>
                                        <div class="form-group">
                                            <button type="submit" class="btn btn-success">Agregar cargo</button>
                                            <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cerrar</button>
                                        </div>

                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>



            <div class="col-md-10 mt-2">

                <div class="card">
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="contractTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="account-status-tab" data-toggle="tab" href="#account-status" role="tab" aria-controls="account-status" aria-selected="true">
                                    Estado de Cuenta
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="additional-charges-tab" data-toggle="tab" href="#additional-charges" role="tab" aria-controls="additional-charges" aria-selected="false">
                                    Cargos adicionales
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="operation-history-tab" data-toggle="tab" href="#operation-history" role="tab" aria-controls="operation-history" aria-selected="false">
                                    Historial de Operaciones
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="contract-comments-tab" data-toggle="tab" href="#contract-comments" role="tab" aria-controls="contract-comments" aria-selected="false">
                                    Comentarios sobre el Contrato
                                </a>
                            </li>
                        </ul>

                        <div class="tab-content" id="contractTabsContent">
                            <!-- Estado de Cuenta -->
                            <div class="tab-pane fade show active" id="account-status" role="tabpanel" aria-labelledby="account-status-tab">
                                <div class="mt-3">
                                    <h4>Estado de Cuenta</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <tbody>
                                                <tr>
                                                    <th>Factura</th>
                                                    <th>Mes</th>
                                                    <th>Saldo</th>
                                                    <th>Estado</th>
                                                </tr>

                                                    @foreach($invoices as $invoice)
                                                        <tr>
                                                        <td>{{ $invoice->id }}</td>
                                                        <td>{{ $invoice->billed_month_name }}</td>
                                                        <td>{{ $invoice->total }}</td>
                                                        <td>{{ $invoice->status }}</td>
                                                        </tr>
                                                    @endforeach

                                            </tbody>
                                        </table>
                                        <div>
                                            {{ $invoices->links() }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Cargos adicionales -->
                            <div class="tab-pane fade" id="additional-charges" role="tabpanel" aria-labelledby="additional-charges-tab">
                                <div class="mt-3">
                                    <h4>Cargos adicionales</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <tbody>
                                                <tr>
                                                    <th>Concepto</th>
                                                    <th>Valor</th>
                                                    <th>Cuotas</th>
                                                    <th>Estado</th>
                                                    <th>Fecha de creación</th>
                                                    <th>Creado por</th>
                                                </tr>
                                                @foreach($additionalCharges as $addionalChrage)
                                                    <tr>
                                                        <td>{{ $addionalChrage->description }}</td>
                                                        <td>${{ number_format($addionalChrage->amount, 2) }}</td>

                                                        {{-- Progreso de cuotas de cargos diferidos --}}
                                                        <td>
                                                            @if($addionalChrage->isDeferred())
                                                                <span class="badge badge-info">
                                                                    {{ $addionalChrage->installments_billed }}/{{ $addionalChrage->installments_total }}
                                                                    (${{ number_format($addionalChrage->installmentAmount(), 2) }} c/u)
                                                                </span>
                                                            @else
                                                                Contado
                                                            @endif
                                                        </td>

                                                        <td>{{ $addionalChrage->status }}</td>
                                                        <td>{{ $addionalChrage->created_at }}</td>
                                                        <td>{{ $addionalChrage->user->name }} {{ $addionalChrage->user->last_name }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Historial de Operaciones -->
                            <div class="tab-pane fade" id="operation-history" role="tabpanel" aria-labelledby="operation-history-tab">
                                <div class="mt-3">
                                    <h4>Historial de Operaciones</h4>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <tr>
                                                <th>Número de orden</th>
                                                <th>Tipo de orden</th>
                                                <th>Detalle de orden</th>
                                                <th>Comentario inicial</th>
                                                <th>Técnico asignado</th>
                                                <th>Fecha de creación</th>
                                                <th>Creada por</th>
                                                <th>Estado</th>
                                                <th></th>
                                            </tr>
                                            @foreach($technicalOrders as $technicalOrder)
                                                <tr>
                                                    <td>{{ $technicalOrder->id }}</td>
                                                    <td>{{ $technicalOrder->type }}</td>
                                                    <td>{{ $technicalOrder->detail }}</td>
                                                    <td>{{ $technicalOrder->initial_comment }}</td>
                                                    <td>{{ $technicalOrder->assignedUser->name ?? 'N/A'}} {{ $technicalOrder->assignedUser->last_name ?? 'N/A'}}</td>
                                                    <td>{{ $technicalOrder->created_at }}</td>
                                                    <td>{{ $technicalOrder->createdBy->name ?? 'Sistema'}} {{ $technicalOrder->createdBy->last_name ?? 'Sistema' }}</td>
                                                    <td>{{ $technicalOrder->status }}</td>
                                                    <td>
                                                        <!-- Button trigger modal -->
                                                        <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#detailModal{{ $technicalOrder->id }}">
                                                            Ver detalles
                                                        </button>

                                                        <div class="modal fade" id="detailModal{{ $technicalOrder->id }}" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                                                            <div class="modal-dialog" role="document">
                                                                <div class="modal-content">
                                                                    <div class="modal-header">
                                                                        <h5 class="modal-title" id="exampleModalLabel">Detalles de orden {{ $technicalOrder->id }}</h5>
                                                                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                                            <span aria-hidden="true">&times;</span>
                                                                        </button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="">
                                                                            <div class="mt-2">
                                                                                <p><strong>Datos del cliente</strong></p>
                                                                            </div>
                                                                            <div>Cliente: {{ $technicalOrder->contract->client->name }} {{ $technicalOrder->contract->client->last_name }}</div>
                                                                            <div>Barrio y dirección: {{ $technicalOrder->contract->neighborhood }} {{ $technicalOrder->contract->address }}</div>
                                                                            <div>Detalles de plan: {{ $technicalOrder->contract->plan->name }}</div>
                                                                            <div class="mt-2">
                                                                                <p><strong>Datos de orden</strong></p>
                                                                            </div>
                                                                            <div>Tipo de orden: {{ $technicalOrder->type }}</div>
                                                                            <div>Detalle: {{ $technicalOrder->detail }}</div>
                                                                            <div>Detalle: {{ $technicalOrder->detail }}</div>
                                                                            <div>Comentario inicial: {{ $technicalOrder->initial_comment }}</div>
                                                                            <div class="mt-2">
                                                                                <p><strong>Datos de solución</strong></p>
                                                                            </div>
                                                                            <div>Observaciones técnicas: {{ $technicalOrder->observations_technical }}</div>
                                                                            <div>Observaciones del cliente: {{ $technicalOrder->client_observation }}</div>
                                                                            <div>Solución: {{ $technicalOrder->solution }}</div>
                                                                            <div>Fecha de creación: {{ $technicalOrder->created_at }}</div>
                                                                            <div>Motivo de rechazo por el técnico: {{ $technicalOrder->rejection_reason ?? 'N/A' }}</div>
                                                                            <div>Última acción: {{ $technicalOrder->updated_at }}</div>
                                                                            <div class="mt-2">
                                                                                <p><strong>Fotos</strong></p>
                                                                                <div class="card">
                                                                                    <div id="carouselExample" class="carousel slide">
                                                                                        <div class="carousel-inner">
                                                                                            @php
                                                                                                $images = is_string($technicalOrder->images) ? json_decode($technicalOrder->images) : [];
                                                                                            @endphp

                                                                                            @forelse($images as $index => $image)
                                                                                                <div class="carousel-item {{ $index == 0 ? 'active' : '' }}">
                                                                                                    <img src="{{ asset($image) }}" class="d-block w-100" alt="Imagen técnica {{ $index + 1 }}">
                                                                                                </div>
                                                                                            @empty
                                                                                                <div class="carousel-item active">
                                                                                                    <img src="{{ asset('path/to/default-image.jpg') }}" class="d-block w-100" alt="No hay imágenes disponibles">
                                                                                                </div>
                                                                                            @endforelse
                                                                                        </div>
                                                                                        <button class="carousel-control-prev" type="button" data-bs-target="#carouselExample" data-bs-slide="prev">
                                                                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                                                                            <span class="visually-hidden">Anterior</span>
                                                                                        </button>
                                                                                        <button class="carousel-control-next" type="button" data-bs-target="#carouselExample" data-bs-slide="next">
                                                                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                                                                            <span class="visually-hidden">Siguiente</span>
                                                                                        </button>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                        <div class="table-responsive">
                                                                            <table class="table table-hover">
                                                                                <tr>
                                                                                    <th>Material</th>
                                                                                    <th>Cantidad</th>
                                                                                    <th>SN</th>
                                                                                </tr>
                                                                                @foreach($technicalOrder->materials as $material_to_order)
                                                                                    <tr>
                                                                                        <td>{{ $material_to_order->material->name }}</td>
                                                                                        <td>{{ $material_to_order->quantity }}</td>
                                                                                        <td>{{ $material_to_order->serial_number }}</td>
                                                                                    </tr>
                                                                                @endforeach
                                                                            </table>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- Comentarios sobre el Contrato -->
                            <div class="tab-pane fade" id="contract-comments" role="tabpanel" aria-labelledby="contract-comments-tab">
                                <div class="mt-3">
                                    <h4>Comentarios sobre el Contrato</h4>
                                    <p>Aquí se pueden agregar o visualizar comentarios sobre el contrato.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

@endsection
@section('js')
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mostrar automáticamente el modal si existe un mensaje de éxito o error
        @if(session('success'))
        var successModal = new bootstrap.Modal(document.getElementById('successModal'));
        successModal.show();
        @endif

        @if(session('error'))
        var errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
        errorModal.show();
        @endif
    </script>
@endsection
