@extends('adminlte::page')

@section('title', 'Nueva red óptica')

@section('content_header')
    <h1 class="mb-0"><i class="fas fa-sitemap mr-2"></i>Nueva red óptica</h1>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="callout callout-info">
                Una <strong>red</strong> agrupa las OLTs, los puertos PON, las zonas y las cajas NAP
                de esta sucursal. Si atiende varios municipios con plantas independientes, conviene
                una red por cada uno.
            </div>

            <form method="POST" action="{{ route('networks.store') }}">
                @csrf
                {{-- En panel consolidado no hay una sucursal activa: hay que
                     decir en cual se guarda. Con una sola alcanzable no se
                     pinta nada y el controlador la asume. --}}
                <x-selector-sucursal titulo="Sucursal de la red"
                                     ayuda="La red y todo lo que cuelgue de ella —OLTs, muflas, cajas— queda en esta sucursal." />

                @include('gestisp.networks.partials.form', ['network' => null])
            </form>
        </div>
    </div>
@endsection
