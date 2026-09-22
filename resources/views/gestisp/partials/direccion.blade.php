{{--
    Dirección por partes: tipo de vía, números, complemento y referencia.

    Sale siempre con la misma forma —«Calle 20 # 19-30, Apto 201, Ref:
    frente al parque»— y se guarda en la columna de siempre. El servidor
    la vuelve a armar con las partes (middleware ComponerDirecciones);
    lo que se ve aquí en «Así queda» es solo la vista previa.

    Parámetros:
      $campo          nombre del campo que se guarda (por defecto 'address')
      $valor          la dirección guardada (null al crear)
      $etiqueta       'Dirección' por defecto
      $requerido      si es obligatoria (por defecto, no)
      $conReferencia  si lleva «punto de referencia» (por defecto, sí). Las
                      cajas NAP y las muflas tienen su propia columna.
      $ayuda          texto bajo el campo
      $mapa           id del mapa del MISMO formulario, si lo hay: habilita
                      «Ubicar en el mapa» y «Sugerir desde el mapa»
      $latitud,
      $longitud       ids de los campos de coordenadas de ese mapa (por
                      defecto, los del selector de ubicación: {mapa}Latitud)
      $coordenadas    [lat, lng] ya guardados, cuando no hay mapa en el
                      formulario: habilita «Sugerir desde la ubicación guardada»
      $zona           «Municipio, Departamento» para buscar en el mapa cuando
                      el formulario no tiene esos campos

    Una dirección vieja que no se reconoce se conserva tal cual mientras
    no se escriba con los campos: guardar el formulario por otro motivo
    no la borra.
--}}
@php
    $campo = $campo ?? 'address';
    $valorActual = old($campo, $valor ?? null);
    $partes = old("{$campo}_partes") ?: (\App\Support\Direccion::partes($valorActual) ?? []);
    $interpretada = $valorActual && \App\Support\Direccion::partes($valorActual) && \App\Support\Direccion::componer(\App\Support\Direccion::partes($valorActual)) !== $valorActual;
    $sinReconocer = $valorActual && !\App\Support\Direccion::partes($valorActual) && !old("{$campo}_partes");
    $esVia = !in_array($partes['tipo'] ?? null, \App\Support\Direccion::DESCRIPTIVAS, true);
    $conReferencia = $conReferencia ?? true;
    $mapa = $mapa ?? null;
    $coordenadas = $coordenadas ?? null;
    // Sin municipio en el formulario (cajas NAP, muflas), el mapa busca
    // en el de la sucursal activa: casi siempre es ahí.
    if (!isset($zona) && ($mapa || $coordenadas)) {
        $sucursalActiva = \App\Models\Branch::find(session('branch_id'));
        $zona = $sucursalActiva ? collect([$sucursalActiva->municipality, $sucursalActiva->department])->filter()->implode(', ') : '';
    }
    $uid = 'dir' . \Illuminate\Support\Str::random(6);
    $errores = collect($errors->get("{$campo}_partes.*"))->flatten()->merge($errors->get($campo));
    // Obligatoria y sin nada guardado: hay que escribirla. Con algo
    // guardado, dejar las partes vacías conserva lo que había.
    $exigir = ($requerido ?? false) && !$valorActual;
@endphp

<div class="gestisp-direccion" data-direccion
     data-mapa="{{ $mapa }}"
     data-latitud="{{ $mapa ? ($latitud ?? $mapa . 'Latitud') : '' }}"
     data-longitud="{{ $mapa ? ($longitud ?? $mapa . 'Longitud') : '' }}"
     data-coordenadas="{{ $coordenadas ? implode(',', $coordenadas) : '' }}"
     data-zona="{{ $zona ?? '' }}">

    <label for="{{ $uid }}Tipo" class="mb-1">
        {{ $etiqueta ?? 'Dirección' }} @if($requerido ?? false)<span class="text-danger">*</span>@endif
    </label>

    <input type="hidden" name="_direcciones[]" value="{{ $campo }}">
    <input type="hidden" name="{{ $campo }}" value="{{ $valorActual }}">

    <div class="form-row">
        <div class="col-md-4 mb-2">
            <select class="form-control" id="{{ $uid }}Tipo" name="{{ $campo }}_partes[tipo]" data-parte="tipo"
                    @if($exigir) required @endif>
                <option value="">Tipo de vía…</option>
                <optgroup label="Vías con nomenclatura">
                    @foreach(\App\Support\Direccion::VIAS as $via)
                        <option value="{{ $via }}" @selected(($partes['tipo'] ?? null) === $via)>{{ $via }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Sin nomenclatura (rural, manzanas)">
                    @foreach(\App\Support\Direccion::DESCRIPTIVAS as $tipo)
                        <option value="{{ $tipo }}" @selected(($partes['tipo'] ?? null) === $tipo)>{{ $tipo }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>

        <div class="col-md-8 mb-2 @unless($esVia) d-none @endunless" data-grupo="via">
            <div class="input-group">
                <input type="text" class="form-control" name="{{ $campo }}_partes[numero]" data-parte="numero"
                       value="{{ $partes['numero'] ?? '' }}" placeholder="20A" maxlength="12"
                       title="Número de la vía: 20, 20A, 20 Bis, 20A Bis B" aria-label="Número de la vía"
                       @unless($esVia) disabled @endunless>
                <select class="form-control" name="{{ $campo }}_partes[cuadrante]" data-parte="cuadrante"
                        aria-label="Cuadrante" style="max-width: 6.5rem;" @unless($esVia) disabled @endunless>
                    <option value="">—</option>
                    @foreach(\App\Support\Direccion::CUADRANTES as $cuadrante)
                        <option value="{{ $cuadrante }}" @selected(($partes['cuadrante'] ?? null) === $cuadrante)>{{ $cuadrante }}</option>
                    @endforeach
                </select>
                <div class="input-group-append input-group-prepend"><span class="input-group-text">#</span></div>
                <input type="text" class="form-control" name="{{ $campo }}_partes[placa]" data-parte="placa"
                       value="{{ $partes['placa'] ?? '' }}" placeholder="19" maxlength="12"
                       aria-label="Número de placa" @unless($esVia) disabled @endunless>
                <div class="input-group-append input-group-prepend"><span class="input-group-text">-</span></div>
                <input type="text" class="form-control" name="{{ $campo }}_partes[placa2]" data-parte="placa2"
                       value="{{ $partes['placa2'] ?? '' }}" placeholder="30" maxlength="4"
                       aria-label="Segundo número de la placa" @unless($esVia) disabled @endunless>
            </div>
        </div>

        <div class="col-md-8 mb-2 @if($esVia) d-none @endif" data-grupo="descripcion">
            <input type="text" class="form-control" name="{{ $campo }}_partes[descripcion]" data-parte="descripcion"
                   value="{{ $partes['descripcion'] ?? '' }}" maxlength="80"
                   placeholder="La Esperanza · 5 vía Rionegro · 4 Casa 12" aria-label="Nombre o descripción"
                   @if($esVia) disabled @endif>
        </div>
    </div>

    <div class="form-row">
        <div class="{{ $conReferencia ? 'col-md-6' : 'col-12' }} mb-2">
            <input type="text" class="form-control" name="{{ $campo }}_partes[complemento]" data-parte="complemento"
                   value="{{ $partes['complemento'] ?? '' }}" maxlength="80"
                   placeholder="Complemento: Apto 201, Torre 2, Casa 5, Local 3" aria-label="Complemento">
        </div>
        @if($conReferencia)
            <div class="col-md-6 mb-2">
                <input type="text" class="form-control" name="{{ $campo }}_partes[referencia]" data-parte="referencia"
                       value="{{ $partes['referencia'] ?? '' }}" maxlength="100"
                       placeholder="Punto de referencia: frente al parque" aria-label="Punto de referencia">
            </div>
        @endif
    </div>

    <div class="small">
        <span class="text-muted">Así queda:</span>
        <strong data-vista>{{ $valorActual ?: '—' }}</strong>
    </div>

    @if($sinReconocer)
        <div class="small text-warning mt-1">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            La dirección registrada no tiene la forma de una nomenclatura. Se conserva tal cual mientras no la
            vuelva a escribir con los campos.
        </div>
    @elseif($interpretada)
        <div class="small text-info mt-1">
            <i class="fas fa-info-circle mr-1"></i>
            Se interpretó la dirección registrada «{{ $valorActual }}». Revise que esté bien antes de guardar.
        </div>
    @endif

    @foreach($errores as $mensaje)
        <div class="small text-danger mt-1">* {{ $mensaje }}</div>
    @endforeach

    @if(!empty($ayuda))
        <small class="form-text text-muted">{{ $ayuda }}</small>
    @endif

    @if($mapa || $coordenadas)
        <div class="mt-2">
            @if($mapa)
                <button type="button" class="btn btn-sm btn-outline-primary mb-1" data-accion="ubicar">
                    <i class="fas fa-map-marker-alt"></i> Ubicar en el mapa según la dirección
                </button>
            @endif
            <button type="button" class="btn btn-sm btn-outline-secondary mb-1" data-accion="sugerir">
                <i class="fas fa-magic"></i>
                {{ $mapa ? 'Sugerir la dirección desde el punto del mapa' : 'Sugerir desde la ubicación guardada' }}
            </button>
        </div>
        <div class="small text-muted mt-1" data-nota></div>
        <div class="alert alert-light border small py-2 mt-1 mb-0 d-none" data-sugerencia></div>
    @endif
</div>

@push('js')
    @include('gestisp.partials.geocodificador')
    @once
    <script>
        (function () {
            'use strict';

            const VIAS = @json(\App\Support\Direccion::VIAS, JSON_UNESCAPED_UNICODE);
            const REFERENCIA = ', Ref: ';

            function plano(texto) {
                return (texto || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
            }

            // La misma que Direccion::normalizarNumero(): «20 a bis» → «20A Bis».
            function normalizarNumero(numero) {
                const texto = (numero || '').trim().replace(/\s+/g, ' ').toUpperCase();
                const m = texto.match(/^(\d{1,3}) ?([A-Z])? ?(BIS)? ?([A-Z])?$/);

                if (!m) {
                    return texto;
                }

                return m[1] + (m[2] || '') + (m[3] ? ' Bis' : '') + (m[4] ? ' ' + m[4] : '');
            }

            function iniciar(bloque) {
                const parte = function (nombre) { return bloque.querySelector('[data-parte="' + nombre + '"]'); };
                const valor = function (nombre) { const el = parte(nombre); return el && !el.disabled ? el.value.trim() : ''; };
                const formulario = bloque.closest('form') || document;
                const nota = bloque.querySelector('[data-nota]');
                const cajaSugerencia = bloque.querySelector('[data-sugerencia]');
                const oculto = bloque.querySelector('input[type="hidden"]:not([name="_direcciones[]"])');
                const exigido = parte('tipo').required;

                function esVia() {
                    return VIAS.indexOf(parte('tipo').value) !== -1;
                }

                function base(paraBuscar) {
                    const tipo = parte('tipo').value;

                    if (!tipo) {
                        return '';
                    }

                    if (!esVia()) {
                        return tipo + ' ' + valor('descripcion');
                    }

                    const cuadrante = valor('cuadrante') ? ' ' + valor('cuadrante') : '';
                    const placa = valor('placa') || valor('placa2')
                        ? (paraBuscar ? ' ' : ' # ') + normalizarNumero(valor('placa')) + '-' + normalizarNumero(valor('placa2'))
                        : '';

                    return tipo + ' ' + normalizarNumero(valor('numero')) + cuadrante + placa;
                }

                function actualizar() {
                    const via = esVia() || !parte('tipo').value;

                    bloque.querySelector('[data-grupo="via"]').classList.toggle('d-none', !via);
                    bloque.querySelector('[data-grupo="descripcion"]').classList.toggle('d-none', via);

                    // Lo que no se ve no viaja: un campo deshabilitado no
                    // se envía, y así el servidor no valida lo que el
                    // usuario ya descartó al cambiar de tipo.
                    ['numero', 'cuadrante', 'placa', 'placa2'].forEach(function (n) { parte(n).disabled = !via; });
                    parte('descripcion').disabled = via;

                    // Con un tipo elegido, lo que pide ese tipo es obligatorio.
                    const conTipo = parte('tipo').value !== '';
                    ['numero', 'placa', 'placa2'].forEach(function (n) { parte(n).required = conTipo && via; });
                    parte('descripcion').required = conTipo && !via;

                    const principal = base(false);
                    let texto = principal;

                    if (texto && valor('complemento')) {
                        texto += ', ' + valor('complemento');
                    }

                    if (texto && valor('referencia')) {
                        texto += REFERENCIA + valor('referencia');
                    }

                    bloque.querySelector('[data-vista]').textContent = texto || oculto.value || '—';

                    // Si se empezó a escribir, el tipo pasa a ser
                    // obligatorio: sin él no se puede armar nada.
                    const escrito = ['numero', 'placa', 'placa2', 'descripcion', 'complemento', 'referencia']
                        .some(function (n) { return valor(n) !== ''; });
                    parte('tipo').required = exigido || escrito;
                }

                bloque.addEventListener('input', actualizar);
                bloque.addEventListener('change', actualizar);
                actualizar();

                function avisar(texto) {
                    if (nota) {
                        nota.textContent = texto;
                    }
                }

                function campoDelFormulario(nombre) {
                    return formulario.querySelector('[name="' + nombre + '"]');
                }

                function zona() {
                    const municipio = campoDelFormulario('municipality');
                    const departamento = campoDelFormulario('department');
                    const partes = [municipio && municipio.value, departamento && departamento.value].filter(Boolean);

                    return partes.length ? partes.join(', ') : bloque.dataset.zona;
                }

                function ocupado(boton, si) {
                    boton.disabled = si;
                    boton.classList.toggle('disabled', si);
                }

                // ---- De la dirección al mapa ----
                const botonUbicar = bloque.querySelector('[data-accion="ubicar"]');

                if (botonUbicar) {
                    botonUbicar.addEventListener('click', async function () {
                        const barrio = campoDelFormulario('neighborhood');
                        const texto = [base(true), barrio && barrio.value, zona(), 'Colombia']
                            .filter(Boolean).join(', ');

                        if (!base(true) && !zona()) {
                            avisar('Escriba primero la dirección.');
                            return;
                        }

                        ocupado(botonUbicar, true);
                        avisar('Buscando en el mapa…');

                        try {
                            const r = await window.GestispGeo.buscar(texto);

                            if (!r) {
                                avisar('El mapa no encontró esa dirección. Marque el punto a mano.');
                                return;
                            }

                            const mapa = document.getElementById(bloque.dataset.mapa);

                            if (mapa) {
                                mapa.dispatchEvent(new CustomEvent('gestisp:fijar-punto', { detail: { lat: r.lat, lng: r.lng } }));
                            }

                            avisar(r.aproximado
                                ? 'No se encontró la dirección exacta: el punto quedó en ' + r.nivel + '. Llévelo hasta la puerta.'
                                : 'Punto ubicado según la dirección. Es una ayuda: confirme que quedó sobre el sitio correcto.');
                        } catch (error) {
                            console.warn('GestISP · geocodificación:', error);
                            avisar('No se pudo consultar el mapa. Marque el punto a mano.');
                        } finally {
                            ocupado(botonUbicar, false);
                        }
                    });
                }

                // ---- Del mapa a la dirección ----
                const botonSugerir = bloque.querySelector('[data-accion="sugerir"]');

                function coordenadas() {
                    if (bloque.dataset.mapa) {
                        const lat = document.getElementById(bloque.dataset.latitud);
                        const lng = document.getElementById(bloque.dataset.longitud);

                        return lat && lat.value && lng && lng.value ? [lat.value, lng.value] : null;
                    }

                    return bloque.dataset.coordenadas ? bloque.dataset.coordenadas.split(',') : null;
                }

                /** «Calle 20A Sur» → {tipo: 'Calle', numero: '20A', cuadrante: 'Sur'} */
                function leerCalle(calle) {
                    const texto = plano(calle);
                    const tipo = VIAS.slice().sort(function (a, b) { return b.length - a.length; })
                        .find(function (v) { return texto.indexOf(plano(v) + ' ') === 0; });

                    if (!tipo) {
                        return null;
                    }

                    const resto = calle.trim().slice(tipo.length).trim();
                    const m = resto.match(/^(\d{1,3}\s?[A-Za-z]?(?:\s?bis)?(?:\s[A-Za-z](?![A-Za-z]))?)(?:\s+(sur|este|norte|oeste))?\b/i);

                    return m ? {
                        tipo: tipo,
                        numero: normalizarNumero(m[1]),
                        cuadrante: m[2] ? m[2].charAt(0).toUpperCase() + m[2].slice(1).toLowerCase() : '',
                    } : null;
                }

                function elegir(select, texto) {
                    if (!select || !texto) {
                        return false;
                    }

                    const buscado = plano(texto);
                    const opciones = Array.prototype.slice.call(select.options).filter(function (o) { return o.value; });
                    const opcion = opciones.find(function (o) { return plano(o.value) === buscado; })
                        || opciones.find(function (o) { return plano(o.value).indexOf(buscado) === 0 || buscado.indexOf(plano(o.value)) === 0; });

                    if (!opcion) {
                        return false;
                    }

                    select.value = opcion.value;

                    if (window.jQuery) {
                        window.jQuery(select).trigger('change');
                    } else {
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    return true;
                }

                function escapar(texto) {
                    const div = document.createElement('div');
                    div.textContent = texto || '';
                    return div.innerHTML;
                }

                if (botonSugerir) {
                    botonSugerir.addEventListener('click', async function () {
                        const punto = coordenadas();

                        if (!punto) {
                            avisar('Primero marque el punto en el mapa.');
                            return;
                        }

                        ocupado(botonSugerir, true);
                        avisar('Consultando el mapa…');
                        cajaSugerencia.classList.add('d-none');

                        try {
                            const d = await window.GestispGeo.inverso(punto[0], punto[1]);
                            const calle = leerCalle(d.calle);
                            const placa = (d.placa || '').match(/^(\d{1,3}\s?[A-Za-z]?)\s*-\s*(\d{1,3}[A-Za-z]?)$/);
                            const lugar = [d.barrio && 'barrio ' + d.barrio, d.municipio, d.departamento].filter(Boolean).join(' · ');

                            avisar('');

                            if (!calle && !lugar) {
                                avisar('El mapa no tiene datos de ese punto. Escriba la dirección con los campos.');
                                return;
                            }

                            const principal = calle
                                ? calle.tipo + ' ' + calle.numero + (calle.cuadrante ? ' ' + calle.cuadrante : '')
                                    + (placa ? ' # ' + normalizarNumero(placa[1]) + '-' + placa[2].toUpperCase() : ' # …')
                                : null;

                            cajaSugerencia.innerHTML =
                                '<div class="mb-1"><i class="fas fa-map-marked-alt text-primary mr-1"></i> El mapa sugiere: ' +
                                (principal ? '<strong>' + escapar(principal) + '</strong>' : '<em>sin nomenclatura en ese punto</em>') +
                                (lugar ? ' · ' + escapar(lugar) : '') + '</div>' +
                                (principal && !placa ? '<div class="text-muted mb-1">El mapa casi nunca conoce la placa: complétela usted.</div>' : '') +
                                '<button type="button" class="btn btn-xs btn-primary mr-1" data-usar>Usar la sugerencia</button>' +
                                '<button type="button" class="btn btn-xs btn-link" data-descartar>Descartar</button>';
                            cajaSugerencia.classList.remove('d-none');

                            cajaSugerencia.querySelector('[data-descartar]').onclick = function () {
                                cajaSugerencia.classList.add('d-none');
                            };

                            cajaSugerencia.querySelector('[data-usar]').onclick = function () {
                                if (calle) {
                                    parte('tipo').value = calle.tipo;
                                    actualizar();
                                    parte('numero').value = calle.numero;
                                    parte('cuadrante').value = calle.cuadrante;
                                    parte('placa').value = placa ? normalizarNumero(placa[1]) : '';
                                    parte('placa2').value = placa ? placa[2].toUpperCase() : '';
                                }

                                const barrio = campoDelFormulario('neighborhood');

                                if (barrio && d.barrio) {
                                    barrio.value = d.barrio;
                                }

                                // El municipio se elige después del
                                // departamento: elegir el departamento
                                // rehace la lista de municipios.
                                if (elegir(campoDelFormulario('department'), d.departamento)) {
                                    elegir(campoDelFormulario('municipality'), d.municipio);
                                }

                                actualizar();
                                cajaSugerencia.classList.add('d-none');
                                avisar('Sugerencia aplicada. Revísela y complete lo que falte antes de guardar.');

                                if (calle && !placa) {
                                    parte('placa').focus();
                                }
                            };
                        } catch (error) {
                            console.warn('GestISP · geocodificación inversa:', error);
                            avisar('No se pudo consultar el mapa.');
                        } finally {
                            ocupado(botonSugerir, false);
                        }
                    });
                }
            }

            document.querySelectorAll('[data-direccion]').forEach(iniciar);
        })();
    </script>
    @endonce
@endpush
