{{--
    De la empresa, o solo de una sucursal.

    QUÉ DECIDE
    ----------
    Dónde vive un plan o un servicio:

      · **De la empresa** (`branch_id` nulo) — disponible en todas sus
        sedes. Es el caso normal y el valor por defecto.
      · **Solo de esta sucursal** — exclusivo de ella.

    POR QUÉ POR DEFECTO ES DE LA EMPRESA
    ------------------------------------
    Porque un ISP con varias sedes normalmente vende el mismo catálogo,
    y el precio es igual en todas. Tener el mismo servicio repetido por
    sucursal no aporta nada y sí permite que diverja — ya pasó, con
    «Servicio de TV» al 19% conviviendo con «Television» al 0%.

    En un servicio importa todavía más que en un plan: lleva UNSPSC,
    unidad de medida y clasificación de IVA, y eso es del contribuyente,
    no de la sede.

    EL CAMPO OCULTO NO SOBRA
    ------------------------
    Un radio sin seleccionar no viaja en el POST. Sin el `hidden`, dejar
    el formulario a medias haría que `de_la_empresa` llegara ausente y
    el controlador aplicara su valor por defecto en vez de lo que se vea
    en pantalla.

    USO
    ---
        <x-ambito-catalogo que="plan" />
        <x-ambito-catalogo que="servicio" :actual="$service" />
--}}
@props(['que' => 'elemento', 'actual' => null])

@php
    // Al editar manda lo que ya es; al crear, de la empresa.
    $deLaEmpresa = old('de_la_empresa', $actual === null ? '1' : ($actual->branch_id === null ? '1' : '0'));
@endphp

<div class="form-group border rounded p-2 mb-3">
    <label class="mb-1"><strong>¿Dónde vive este {{ $que }}?</strong></label>

    <input type="hidden" name="de_la_empresa" value="{{ $deLaEmpresa }}">

    <div class="form-check">
        <input class="form-check-input" type="radio" id="ambito-empresa"
               name="de_la_empresa" value="1" {{ (string) $deLaEmpresa === '1' ? 'checked' : '' }}>
        <label class="form-check-label" for="ambito-empresa">
            De la empresa — disponible en todas sus sucursales
        </label>
    </div>

    <div class="form-check">
        <input class="form-check-input" type="radio" id="ambito-sucursal"
               name="de_la_empresa" value="0" {{ (string) $deLaEmpresa === '0' ? 'checked' : '' }}>
        <label class="form-check-label" for="ambito-sucursal">
            Solo de esta sucursal
        </label>
    </div>

    @if($actual && $actual->branch_id !== null)
        <small class="text-muted d-block mt-1">
            Ahora es exclusivo de {{ $actual->branch?->name ?? 'una sucursal' }}.
            Pasarlo a la empresa lo hará visible en todas las sedes.
        </small>
    @endif
</div>
