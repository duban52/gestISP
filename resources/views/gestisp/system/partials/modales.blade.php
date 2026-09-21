{{--
    Los tres formularios del catálogo.

    Cada uno sirve para crear y para editar: el JavaScript de la página
    le cambia la acción y el método. Tener uno para cada cosa acabaría
    con los dos diciendo cosas distintas el día que se añada un campo.
--}}

{{-- ============================ Estado de contrato ============================ --}}
<div class="modal fade" id="modalEstado" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('system.status.store') }}"
                  data-crear="{{ route('system.status.store') }}">
                @csrf
                <input type="hidden" name="_method" value="POST">

                <div class="modal-header">
                    <h5 class="modal-title">Estado de contrato</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="estado_name">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="estado_name" class="form-control" maxlength="40" required>
                        <small class="form-text text-muted">
                            Es lo que se guarda en el contrato. En los del sistema no se puede cambiar.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="estado_description">Descripción</label>
                        <input type="text" name="description" id="estado_description" class="form-control" maxlength="255"
                               placeholder="Ej.: tiene servicio pero no se le cobra.">
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="estado_bills" name="bills" value="1">
                            <label class="custom-control-label" for="estado_bills">
                                Se le puede facturar
                                <small class="d-block text-muted">
                                    Desmárquelo para un estado exonerado: tiene servicio, pero no se le cobra.
                                </small>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="estado_auto" name="auto_bills" value="1">
                            <label class="custom-control-label" for="estado_auto">
                                Entra en la corrida mensual
                                <small class="d-block text-muted">
                                    Sin esto solo se le factura a mano desde su ficha.
                                </small>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="estado_servicio" name="has_service" value="1">
                            <label class="custom-control-label" for="estado_servicio">
                                Tiene servicio
                                <small class="d-block text-muted">
                                    Los equipos del cliente deben estar habilitados en este estado.
                                </small>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="estado_final" name="is_final" value="1">
                            <label class="custom-control-label" for="estado_final">
                                Es una baja definitiva
                                <small class="d-block text-muted">
                                    Al llegar aquí se libera el puerto, se corta la cuenta y se retira la ONT.
                                </small>
                            </label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="estado_activo" name="active" value="1" checked>
                            <label class="custom-control-label" for="estado_activo">
                                Activo
                                <small class="d-block text-muted">
                                    Desactivado deja de ofrecerse, pero los contratos que lo tengan se siguen entendiendo.
                                </small>
                            </label>
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group col-6">
                            <label for="estado_color">Color</label>
                            <select name="color" id="estado_color" class="form-control">
                                @foreach(['success' => 'Verde', 'info' => 'Azul claro', 'primary' => 'Azul',
                                          'warning' => 'Amarillo', 'danger' => 'Rojo', 'secondary' => 'Gris'] as $valor => $etiqueta)
                                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-6">
                            <label for="estado_orden">Orden</label>
                            <input type="number" name="sort_order" id="estado_orden" class="form-control" min="0" max="999" value="0">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ============================ Tipo de orden ============================ --}}
<div class="modal fade" id="modalTipo" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('system.order_type.store') }}"
                  data-crear="{{ route('system.order_type.store') }}">
                @csrf
                <input type="hidden" name="_method" value="POST">

                <div class="modal-header">
                    <h5 class="modal-title">Tipo de orden</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="tipo_name">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="tipo_name" class="form-control" maxlength="40" required>
                    </div>
                    <div class="form-group">
                        <label for="tipo_description">Descripción</label>
                        <input type="text" name="description" id="tipo_description" class="form-control" maxlength="255">
                    </div>
                    <div class="row">
                        <div class="form-group col-6">
                            <label for="tipo_orden">Orden</label>
                            <input type="number" name="sort_order" id="tipo_orden" class="form-control" min="0" max="999" value="0">
                        </div>
                        <div class="form-group col-6 d-flex align-items-end">
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input" id="tipo_activo" name="active" value="1" checked>
                                <label class="custom-control-label" for="tipo_activo">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ============================ Detalle de orden ============================ --}}
<div class="modal fade" id="modalDetalle" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" action="{{ route('system.order_detail.store') }}"
                  data-crear="{{ route('system.order_detail.store') }}">
                @csrf
                <input type="hidden" name="_method" value="POST">

                <div class="modal-header">
                    <h5 class="modal-title">Detalle de orden</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="detalle_tipo">Tipo <span class="text-danger">*</span></label>
                        <select name="technical_order_type_id" id="detalle_tipo" class="form-control" required>
                            @foreach($tipos as $tipo)
                                <option value="{{ $tipo->id }}">{{ $tipo->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="detalle_name">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="detalle_name" class="form-control" maxlength="80" required
                               placeholder="Ej.: Corte por fraude">
                        <small class="form-text text-muted">
                            Es lo que verá el técnico. En los del sistema no se puede cambiar: miles de
                            órdenes cerradas se agrupan por él en los informes.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="detalle_estado">Al cerrar la orden, el contrato queda en</label>
                        <select name="target_contract_status" id="detalle_estado" class="form-control">
                            <option value="">No cambia el estado</option>
                            @foreach($estados as $estado)
                                <option value="{{ $estado->name }}">{{ $estado->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="detalle_pppoe">Cuenta PPPoE</label>
                            <select name="pppoe_action" id="detalle_pppoe" class="form-control" required>
                                <option value="ninguna">No se toca</option>
                                <option value="deshabilitar">Deshabilitar</option>
                                <option value="habilitar">Habilitar</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="detalle_ont">ONT</label>
                            <select name="ont_action" id="detalle_ont" class="form-control" required>
                                <option value="ninguna">No se toca</option>
                                <option value="deshabilitar">Deshabilitar</option>
                                <option value="habilitar">Habilitar</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small">
                        Deshabilitar corta el servicio pero <strong>conserva</strong> la cuenta y la ONT
                        del cliente: es lo que hace un corte por mora, y se revierte con una reconexión.
                        La baja definitiva es otra cosa y la decide el estado.
                    </div>

                    <div class="row">
                        <div class="form-group col-4">
                            <label for="detalle_color">Color</label>
                            <input type="color" name="color" id="detalle_color" class="form-control" value="#0d6efd">
                        </div>
                        <div class="form-group col-4">
                            <label for="detalle_orden">Orden</label>
                            <input type="number" name="sort_order" id="detalle_orden" class="form-control" min="0" max="999" value="0">
                        </div>
                        <div class="form-group col-4 d-flex align-items-end">
                            <div class="custom-control custom-checkbox mb-2">
                                <input type="checkbox" class="custom-control-input" id="detalle_activo" name="active" value="1" checked>
                                <label class="custom-control-label" for="detalle_activo">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
