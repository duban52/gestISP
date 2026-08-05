{{-- ============================================================
     Redes ópticas de la sucursal

     Una red es la planta externa de una sede: sus OLTs, sus puertos
     PON, sus zonas y sus cajas. Una sucursal puede tener varias —por
     ejemplo una por municipio atendido—.
     ============================================================ --}}
@extends('adminlte::page')

@section('title', 'Redes ópticas')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center flex-wrap">
        <h1 class="mb-0"><i class="fas fa-sitemap mr-2"></i>Redes ópticas</h1>
        @can('networks.create')
            <a href="{{ route('networks.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Nueva red
            </a>
        @endcan
    </div>
@endsection

@section('content')

    @include('gestisp.networks.partials.alertas')

    @if($networks->isEmpty())
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="fas fa-project-diagram text-muted" style="font-size: 3rem;"></i>
                <h4 class="mt-3">Todavía no hay ninguna red documentada</h4>
                <p class="text-muted mb-4">
                    Una red agrupa las OLTs, los puertos PON y las cajas NAP de esta sucursal.<br>
                    Es el primer paso para tener el mapa de la planta externa.
                </p>
                @can('networks.create')
                    <a href="{{ route('networks.create') }}" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus"></i> Crear la primera red
                    </a>
                @endcan
            </div>
        </div>
    @else
        <div class="row">
            @foreach($networks as $red)
                <div class="col-12 col-lg-6">
                    <div class="card card-outline card-primary shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title">
                                <a href="{{ route('networks.show', $red) }}">
                                    <strong>{{ $red->name }}</strong>
                                </a>
                                @unless($red->active)
                                    <span class="badge badge-secondary ml-1">Inactiva</span>
                                @endunless
                            </h3>
                            <div class="card-tools">
                                <a href="{{ route('networks.show', $red) }}" class="btn btn-tool" title="Ver">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            @if($red->description)
                                <p class="text-muted">{{ $red->description }}</p>
                            @endif

                            <div class="row text-center">
                                <div class="col-3">
                                    <div class="h4 mb-0">{{ $red->olts_count }}</div>
                                    <small class="text-muted text-uppercase">OLTs</small>
                                </div>
                                <div class="col-3">
                                    <div class="h4 mb-0">{{ $red->pon_ports_count }}</div>
                                    <small class="text-muted text-uppercase">Puertos PON</small>
                                </div>
                                <div class="col-3">
                                    <div class="h4 mb-0">{{ $red->zones_count }}</div>
                                    <small class="text-muted text-uppercase">Zonas</small>
                                </div>
                                <div class="col-3">
                                    <div class="h4 mb-0 text-primary">{{ $red->nap_boxes_count }}</div>
                                    <small class="text-muted text-uppercase">Cajas</small>
                                </div>
                            </div>

                            <div class="mt-3 text-muted small">
                                Las cajas se numeran como
                                <code>{{ $red->nap_prefix }}001</code>, <code>{{ $red->nap_prefix }}002</code>…
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between">
                            <a href="{{ route('naps.index', ['network_id' => $red->id]) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-box"></i> Ver sus cajas
                            </a>
                            @can('networks.edit')
                                <a href="{{ route('networks.edit', $red) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-cog"></i> Configurar
                                </a>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
