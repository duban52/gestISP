{{--
    De un vistazo: ¿de la empresa, o de una sola sucursal?

    POR QUÉ NO BASTABA LA COLUMNA QUE HABÍA
    ---------------------------------------
    Los dos listados ya traían una columna «Sucursal» que pintaba el
    nombre de la sede, o un guion. El guion es ambiguo: lo mismo puede
    leerse como «de la empresa» que como «sin asignar», y para salir de
    dudas había que abrir la ficha. Además DataTables la escondía
    cuando no se trabajaba en consolidado — justo cuando el usuario
    tampoco podía deducirlo.

    El ámbito no es un dato de contexto: decide dónde se puede vender
    ese plan y qué servicios puede llevar. Se ve siempre.

    USO
    ---
        <x-ambito-badge :de="$plan" />
        <x-ambito-badge :de="$service" />
--}}
@props(['de'])

@if($de->branch_id === null)
    <span class="badge badge-primary"
          title="De la empresa: disponible en todas sus sucursales">
        <i class="fas fa-building"></i> Empresa
    </span>
@else
    <span class="badge badge-secondary"
          title="Exclusivo de esta sucursal: no se ve desde las demás">
        <i class="fas fa-map-marker-alt"></i> {{ $de->branch?->name ?? 'Una sucursal' }}
    </span>
@endif
