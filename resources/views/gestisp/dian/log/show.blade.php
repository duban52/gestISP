@extends('adminlte::page')

@section('title', 'Documento ante la DIAN')

@section('content_header')
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="mb-0">
            <i class="fas fa-file-invoice mr-2"></i>
            {{ $documento->invoice?->full_number ?? $documento->note?->full_number ?? 'Documento ' . $documento->id }}
        </h1>
        <a href="{{ route('dian.log.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left mr-1"></i>Volver
        </a>
    </div>
@stop

@section('content')

    @if(session('success'))
        <div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i>{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i>{{ session('error') }}</div>
    @endif

    {{-- ============================================================
         LOS MOTIVOS DEL RECHAZO VAN PRIMERO.

         La DIAN los devuelve pegados con « | » en un solo texto, y así
         son ilegibles. Son exactamente lo que hay que corregir antes de
         volver a emitir, así que van arriba y uno por línea.

         Y hay que decirlo claro: un documento rechazado NO se reintenta.
         Reenviarlo da el mismo rechazo; lo que corresponde es corregir
         y emitir uno nuevo.
         ============================================================ --}}
    @if($documento->status === \App\Models\ElectronicDocument::RECHAZADO)
        <div class="card card-outline card-danger shadow-sm">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-times-circle mr-1"></i>
                    La DIAN rechazó este documento
                </h3>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Esta factura <strong>no tiene valor fiscal</strong>. Un documento rechazado no se
                    reintenta: reenviarlo da el mismo rechazo. Hay que corregir lo que se indica abajo
                    y emitir un documento nuevo.
                </p>
                <ul class="list-group">
                    @foreach($motivos as $motivo)
                        <li class="list-group-item">
                            <i class="fas fa-exclamation-circle text-danger mr-2"></i>{{ $motivo }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @elseif($documento->last_error)
        <div class="alert alert-warning">
            <strong>Último problema registrado:</strong><br>
            @foreach($motivos as $motivo)
                <div>{{ $motivo }}</div>
            @endforeach
        </div>
    @endif

    <div class="row">
        {{-- ==================== Qué es ==================== --}}
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header"><h3 class="card-title">El documento</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 40%">Estado</th>
                            <td>@include('gestisp.dian.log.partials.estado', ['documento' => $documento, 'estados' => $estados])</td>
                        </tr>
                        <tr>
                            <th>Tipo</th>
                            <td>{{ $documento->note ? 'Nota ' . $documento->note->type : 'Factura de venta' }}</td>
                        </tr>
                        <tr>
                            <th>Cliente</th>
                            <td>{{ $documento->invoice?->contract?->client?->fullName() ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Ambiente</th>
                            <td>
                                @if((string) $documento->environment_code === '2')
                                    <span class="badge badge-warning">Pruebas — sin validez fiscal</span>
                                @else
                                    <span class="badge badge-success">Producción</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>CUFE / CUDE</th>
                            <td><small style="word-break: break-all;">{{ $documento->cufe ?: '—' }}</small></td>
                        </tr>
                        <tr>
                            <th>Emitido</th>
                            <td>{{ $documento->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- ==================== Qué pasó con él ==================== --}}
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header"><h3 class="card-title">Ante la DIAN y ante el cliente</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th style="width: 40%">Intentos de envío</th>
                            <td>{{ $documento->attempts }}</td>
                        </tr>
                        <tr>
                            <th>Validado por la DIAN</th>
                            <td>
                                {{ $documento->accepted_at?->format('Y-m-d H:i:s') ?? 'Todavía no' }}
                            </td>
                        </tr>
                        <tr>
                            <th>Acuse de la DIAN</th>
                            <td>
                                @if($documento->dian_response_xml)
                                    <span class="text-success"><i class="fas fa-check mr-1"></i>Guardado</span>
                                @else
                                    <span class="text-muted">No</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            {{-- Que la DIAN la valide y que el cliente la
                                 tenga son dos obligaciones distintas. --}}
                            <th>Entregado al cliente</th>
                            <td>
                                @if($documento->delivered_at)
                                    <span class="text-success">
                                        <i class="fas fa-check mr-1"></i>{{ $documento->delivered_at->format('Y-m-d H:i') }}
                                    </span>
                                @elseif($documento->status === \App\Models\ElectronicDocument::ACEPTADO)
                                    <span class="text-warning">Validada, pero todavía no entregada</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif

                                {{-- El reenvío existe porque el correo falla
                                     por cosas ajenas al sistema: un buzón
                                     lleno, una dirección mal escrita que
                                     luego se corrige, el servidor de correo
                                     caído un rato. --}}
                                @if($documento->status === \App\Models\ElectronicDocument::ACEPTADO && $puedeReenviar)
                                    <form method="POST"
                                          action="{{ route('dian.log.reenviar', $documento) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Se le volverá a enviar la factura al cliente por correo y WhatsApp. ¿Continuar?');">
                                        @csrf
                                        <button type="submit" class="btn btn-xs btn-outline-primary ml-2">
                                            <i class="fas fa-paper-plane mr-1"></i>{{ $documento->delivered_at ? 'Volver a enviar' : 'Enviar ahora' }}
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Seguimiento DIAN</th>
                            <td><small style="word-break: break-all;">{{ $documento->dian_track_id ?: '—' }}</small></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         El historial de intentos.

         Distingue un problema de CONTENIDO de uno de COMUNICACIÓN: si
         hay varios intentos con error y ningún rechazo, la DIAN no
         llegó a ver el documento — es otra cosa muy distinta.
         ============================================================ --}}
    <div class="card shadow-sm">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-history mr-1"></i>Intentos de envío</h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th class="text-center">#</th>
                        <th>Cuándo</th>
                        <th>Resultado</th>
                        <th class="text-center">HTTP</th>
                        <th class="text-center">Tardó</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($documento->transmissions as $intento)
                    <tr>
                        <td class="text-center">{{ $intento->attempt }}</td>
                        <td><small>{{ $intento->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        <td>
                            @php
                                $color = match ($intento->outcome) {
                                    'accepted' => 'success',
                                    'rejected' => 'danger',
                                    'timeout' => 'warning',
                                    default => 'secondary',
                                };
                                $texto = match ($intento->outcome) {
                                    'accepted' => 'Aceptado',
                                    'rejected' => 'Rechazado',
                                    'timeout' => 'Sin respuesta a tiempo',
                                    default => 'Error de comunicación',
                                };
                            @endphp
                            <span class="badge badge-{{ $color }}">{{ $texto }}</span>
                        </td>
                        <td class="text-center">{{ $intento->http_status ?: '—' }}</td>
                        <td class="text-center">
                            <small>{{ $intento->duration_ms ? number_format($intento->duration_ms) . ' ms' : '—' }}</small>
                        </td>
                        <td>
                            @if($intento->errors)
                                @foreach((array) $intento->errors as $error)
                                    <small class="d-block text-muted">{{ $error }}</small>
                                @endforeach
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">
                            Nunca se ha intentado enviar este documento a la DIAN.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@stop
