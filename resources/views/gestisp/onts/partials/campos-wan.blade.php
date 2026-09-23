{{--
    Los campos de la configuración WAN de una ONT.

    Se usan en DOS sitios: el modal de la ficha de la ONT y el de
    autorización desde no autorizadas. Son el mismo formulario, así que
    viven una sola vez: si mañana la OLT admite otro modo, se añade
    aquí y aparece en los dos.

    Parámetros:
      $prefijo  Prefijo de los nombres de campo. Vacío en la ficha;
                "wan_" al activar, donde el formulario ya tiene su
                propia vlan y descripción y los nombres chocarían.
      $cuenta   Cuenta PPPoE que se propone (la del contrato), o null.
      $ayuda    Si se explica de dónde sale la cuenta. Al activar NO:
                ahí el contrato se elige después de pintar el
                formulario, y un «no tiene cuenta» fijo mentiría en
                cuanto se eligiera uno que sí la tiene.
      $vlan     VLAN que se propone. Al activar la elige el formulario
                de arriba, así que allí va oculta.
--}}
@php
    $prefijo = $prefijo ?? '';
    $cuenta = $cuenta ?? null;
    $conVlan = $conVlan ?? true;
    $ayuda = $ayuda ?? true;
@endphp

<div class="wan-campos">
    <div class="form-group">
        <label>Tipo de conexión</label>
        <select name="{{ $prefijo }}modo" class="form-control wan-modo">
            <option value="pppoe" @selected(old($prefijo . 'modo', 'pppoe') === 'pppoe')>
                PPPoE (cuenta del cliente)
            </option>
            <option value="dhcp" @selected(old($prefijo . 'modo') === 'dhcp')>DHCP</option>
            <option value="static" @selected(old($prefijo . 'modo') === 'static')>IP estática</option>
        </select>
    </div>

    @if($conVlan)
        <div class="form-row">
            <div class="form-group col-7">
                <label>VLAN</label>
                <input type="number" name="{{ $prefijo }}vlan" class="form-control"
                       value="{{ old($prefijo . 'vlan', $vlan ?? '') }}" min="1" max="4094" required>
            </div>
            <div class="form-group col-5">
                <label>Prioridad</label>
                <input type="number" name="{{ $prefijo }}priority" class="form-control"
                       value="{{ old($prefijo . 'priority', 0) }}" min="0" max="7" required>
            </div>
        </div>
    @endif

    <div class="form-group">
        <label>Perfil WAN <small class="text-muted">(profile-id)</small></label>
        <input type="number" name="{{ $prefijo }}profile_id" class="form-control"
               value="{{ old($prefijo . 'profile_id', \App\Services\OltSshService::PERFIL_WAN) }}"
               min="1" max="1024">
        <small class="form-text text-muted">
            El perfil WAN de la OLT al que se ata la conexión.
            Déjelo vacío si su OLT no usa perfiles WAN.
        </small>
    </div>

    <div class="wan-campos-pppoe">
        <div class="form-group">
            <label>Usuario PPPoE</label>
            <input type="text" name="{{ $prefijo }}username" class="form-control"
                   value="{{ old($prefijo . 'username', $cuenta->username ?? '') }}" maxlength="64">
            @if($ayuda)
                @if($cuenta)
                    <small class="form-text text-muted">Cuenta registrada del contrato.</small>
                @else
                    <small class="form-text text-danger">
                        El contrato no tiene cuenta PPPoE registrada: escríbala o elija otro tipo de conexión.
                    </small>
                @endif
            @endif
        </div>
        <div class="form-group mb-0">
            <label>Contraseña PPPoE</label>
            <input type="text" name="{{ $prefijo }}password" class="form-control"
                   value="{{ old($prefijo . 'password', $cuenta->password ?? '') }}" maxlength="64">
        </div>
    </div>

    {{-- Direccionamiento fijo. Un dato mal puesto aquí deja la ONT
         incomunicada y obliga a ir hasta el sitio, así que el servidor
         comprueba que sean IPs de verdad antes de mandar nada. --}}
    <div class="wan-campos-estatica d-none">
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Dirección IP</label>
                <input type="text" name="{{ $prefijo }}ip_address" class="form-control"
                       value="{{ old($prefijo . 'ip_address') }}" placeholder="192.168.150.50">
            </div>
            <div class="form-group col-md-6">
                <label>Máscara</label>
                <input type="text" name="{{ $prefijo }}mask" class="form-control"
                       value="{{ old($prefijo . 'mask', '255.255.255.0') }}" placeholder="255.255.255.0">
            </div>
        </div>
        <div class="form-group">
            <label>Puerta de enlace</label>
            <input type="text" name="{{ $prefijo }}gateway" class="form-control"
                   value="{{ old($prefijo . 'gateway') }}" placeholder="192.168.150.1">
        </div>
        <div class="form-row mb-0">
            <div class="form-group col-md-6 mb-0">
                <label>DNS primario</label>
                <input type="text" name="{{ $prefijo }}pri_dns" class="form-control"
                       value="{{ old($prefijo . 'pri_dns', '8.8.8.8') }}">
            </div>
            <div class="form-group col-md-6 mb-0">
                <label>DNS secundario <small class="text-muted">(opcional)</small></label>
                <input type="text" name="{{ $prefijo }}slave_dns" class="form-control"
                       value="{{ old($prefijo . 'slave_dns', '1.1.1.1') }}">
            </div>
        </div>
    </div>
</div>

@once
    @push('js')
        <script>
            /* ============================================================
               Cada modo pide lo suyo

               Dejar visibles los campos del otro modo invita a llenarlos
               para nada: el comando que se manda a la OLT ni siquiera los
               admite. Y `required` solo se pone en los que se van a
               enviar, o el navegador bloquearía el formulario por un
               campo escondido que nadie puede ver.
               ============================================================ */
            $(document).on('change', '.wan-modo', function () {
                const $campos = $(this).closest('.wan-campos');
                const esPppoe = this.value === 'pppoe';
                const esEstatica = this.value === 'static';

                $campos.find('.wan-campos-pppoe').toggleClass('d-none', !esPppoe);
                $campos.find('.wan-campos-pppoe input').prop('required', esPppoe);

                $campos.find('.wan-campos-estatica').toggleClass('d-none', !esEstatica);
                // El DNS secundario es lo único opcional de la estática.
                $campos.find('.wan-campos-estatica input')
                    .not('[name$="slave_dns"]')
                    .prop('required', esEstatica);
            });

            $(function () { $('.wan-modo').trigger('change'); });
        </script>
    @endpush
@endonce
