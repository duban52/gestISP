{{--
    Copias de seguridad de la base de datos.

    La pantalla responde, de arriba abajo, a las tres preguntas que se
    hace quien entra aquí:

      1. ¿Están funcionando las copias automáticas?  (tarjeta de estado)
      2. Necesito una copia AHORA.                   (botón de generar)
      3. ¿Dónde está la que hice el martes?          (listado)
--}}
@extends('adminlte::page')

@section('title', 'Copias de seguridad')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0"><i class="fas fa-database mr-2"></i>Copias de seguridad</h1>
        <span class="badge badge-danger">Solo superadministrador</span>
    </div>
@stop

@section('content')

    @foreach(['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $clave => $color)
        @if(session($clave))
            <div class="alert alert-{{ $color }} alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                {{ session($clave) }}
            </div>
        @endif
    @endforeach

    {{-- La copia recién generada, ofrecida para descarga inmediata:
         quien pulsó el botón la quiere en su equipo, no en el listado --}}
    @if(session('copia_generada'))
        <div class="callout callout-success">
            <h5 class="mb-2"><i class="fas fa-check-circle mr-1"></i> La copia está lista</h5>
            <p class="mb-2">
                Descárguela ahora y guárdela fuera del servidor. Mientras esté
                únicamente aquí, no protege de nada.
            </p>
            <a href="{{ route('backups.download', session('copia_generada')) }}"
               class="btn btn-success">
                <i class="fas fa-download mr-1"></i> Descargar {{ session('copia_generada') }}
            </a>
        </div>
    @endif

    <div class="row">

        {{-- ============================================================
             Estado del respaldo automático

             Es lo primero de la pantalla porque es lo que de verdad
             protege el sistema. El botón de abajo hace copias sueltas;
             esto es lo que se ejecuta todos los días sin que nadie se
             acuerde.
             ============================================================ --}}
        <div class="col-lg-7">
            <div class="card card-outline {{ $automaticoAtrasado ? 'card-danger' : 'card-success' }} shadow-sm">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clock mr-1"></i> Respaldo automático
                    </h3>
                </div>
                <div class="card-body">
                    @if($ultimaAutomatica === null)
                        <p class="mb-2">
                            <span class="badge badge-danger">Sin copias automáticas</span>
                        </p>
                        <p class="mb-0 text-muted">
                            No hay ninguna copia generada por el servidor. Lo más probable
                            es que la tarea programada aún no esté instalada. El
                            procedimiento está en el manual
                            <em>Manual_Copias_Seguridad_GestISP.pdf</em>.
                        </p>
                    @else
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Última copia automática</dt>
                            <dd class="col-sm-7">
                                {{ $ultimaAutomatica->fecha->format('d/m/Y H:i') }}
                                <small class="text-muted">
                                    (hace {{ number_format($ultimaAutomatica->horasDeAntiguedad(), 1, ',', '.') }} horas)
                                </small>
                            </dd>

                            <dt class="col-sm-5">Tamaño</dt>
                            <dd class="col-sm-7">{{ $ultimaAutomatica->tamanoLegible() }}</dd>

                            <dt class="col-sm-5">Estado</dt>
                            <dd class="col-sm-7">
                                @if($automaticoAtrasado)
                                    <span class="badge badge-danger">Atrasado</span>
                                @else
                                    <span class="badge badge-success">Al día</span>
                                @endif
                            </dd>
                        </dl>
                    @endif

                    @if($automaticoAtrasado && $ultimaAutomatica !== null)
                        <div class="alert alert-danger mt-3 mb-0">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Han pasado más de {{ $horasDeAviso }} horas desde la última copia
                            automática. Avise al responsable técnico: la tarea programada del
                            servidor puede estar fallando.
                        </div>
                    @endif
                </div>
                <div class="card-footer text-muted small">
                    Se programan dos copias diarias (02:30 y 14:30) que se envían a la NAS.
                    En este servidor solo se conservan las de los últimos
                    {{ $diasQueSeConservan }} días.
                </div>
            </div>
        </div>

        {{-- ============================================================
             Copia bajo demanda
             ============================================================ --}}
        <div class="col-lg-5">
            <div class="card card-outline card-primary shadow-sm">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-hand-pointer mr-1"></i> Copia inmediata</h3>
                </div>
                <div class="card-body">
                    <p>
                        Genera en este momento un volcado completo y comprimido de la base
                        de datos, y lo deja listo para descargar desde este mismo equipo.
                    </p>
                    <p class="text-muted small mb-3">
                        Úsela antes de cualquier cambio delicado: una carga masiva de
                        clientes, un cambio de tarifas o una actualización del sistema.
                    </p>

                    <form method="POST" action="{{ route('backups.store') }}" id="form-generar-copia">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-block btn-lg" id="btn-generar-copia">
                            <i class="fas fa-database mr-1"></i> Generar copia ahora
                        </button>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    <i class="fas fa-lock mr-1"></i>
                    El archivo contiene los datos de todos los clientes. Guárdelo en un
                    lugar seguro y bórrelo de su equipo cuando ya no lo necesite.
                </div>
            </div>

            <div class="callout callout-info">
                <p class="mb-1"><strong>Ocupado por las copias:</strong> {{ $espacioOcupado }}</p>
                @if($espacioLibre !== null)
                    <p class="mb-0"><strong>Espacio libre en disco:</strong> {{ $espacioLibre }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- ============================================================
         Copias disponibles en el servidor
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">
                <i class="fas fa-list mr-1"></i> {{ $copias->count() }} copia(s) en el servidor
            </h3>
            <span class="text-muted small">De la más reciente a la más antigua</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th style="width: 150px;">Fecha y hora</th>
                        <th>Archivo</th>
                        <th style="width: 110px;">Origen</th>
                        <th style="width: 110px;">Tamaño</th>
                        <th style="width: 150px;" class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($copias as $copia)
                        <tr @class(['table-success' => session('copia_generada') === $copia->nombre])>
                            <td class="text-nowrap">
                                {{ $copia->fecha->format('d/m/Y') }}
                                <span class="text-muted">{{ $copia->fecha->format('H:i') }}</span>
                            </td>
                            <td class="text-monospace small align-middle">{{ $copia->nombre }}</td>
                            <td>
                                @if($copia->esManual())
                                    <span class="badge badge-primary">Manual</span>
                                @else
                                    <span class="badge badge-secondary">Automática</span>
                                @endif
                            </td>
                            <td>{{ $copia->tamanoLegible() }}</td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route('backups.download', $copia->nombre) }}"
                                   class="btn btn-outline-primary btn-sm" title="Descargar">
                                    <i class="fas fa-download"></i>
                                </a>
                                <form method="POST" action="{{ route('backups.destroy', $copia->nombre) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('¿Eliminar esta copia del servidor?\n\nLa copia enviada a la NAS no se ve afectada.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <i class="fas fa-inbox mr-1"></i>
                                Todavía no hay ninguna copia en este servidor.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@stop

@section('js')
    <script>
        // El volcado puede tardar varios minutos en una base grande y la
        // página se queda cargando sin dar señales. Sin este aviso, la
        // reacción natural es volver a pulsar el botón.
        document.getElementById('form-generar-copia').addEventListener('submit', function () {
            const boton = document.getElementById('btn-generar-copia');

            boton.disabled = true;
            boton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Generando la copia, no cierre esta página...';
        });
    </script>
@stop
