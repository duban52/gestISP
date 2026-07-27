{{-- ============================================================
     Plantilla base de los correos de GestISP

     Está escrita con tablas y estilos EN LÍNEA a propósito: los
     gestores de correo (sobre todo Outlook y Gmail) descartan las
     hojas de estilo y el maquetado moderno. Lo que aquí parece
     anticuado es lo único que se ve igual en todos lados.

     Variables (todas opcionales salvo $titulo y $parrafos):
       $sucursal   Branch    — marca del encabezado y pie
       $titulo     string    — título de la banda superior
       $preheader  string    — texto de vista previa en la bandeja
       $saludo     string    — "Hola Ana,"
       $parrafos   array     — cuerpo del mensaje
       $destacado  array     — ['etiqueta','valor','nota'] (importe)
       $datos      array     — ['Etiqueta' => 'valor', ...] (ficha)
       $aviso      array     — ['tipo' => info|alerta|exito, 'texto']
       $accion     array     — ['texto','url'] (botón)
       $cierre     string    — línea final
       $color      string    — color de acento en hexadecimal
     ============================================================ --}}
@php
    $color = $color ?? '#1F4E79';
    $sucursalNombre = $sucursal->name ?? config('app.name');

    // El logo se enlaza por URL: si el gestor de correo bloquea las
    // imágenes, el encabezado sigue leyéndose porque el nombre va en
    // texto. Requiere que APP_URL apunte al dominio público.
    $logo = ($sucursal->image ?? null) ? asset('storage/' . $sucursal->image) : null;

    $contacto = collect([
        $sucursal->address ?? null,
        $sucursal->municipality ?? null,
        $sucursal->department ?? null,
    ])->filter()->implode(', ');

    $telefonos = collect([
        $sucursal->number_phone ?? null,
        $sucursal->additional_number ?? null,
    ])->filter()->implode(' · ');
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $titulo }}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, span { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body style="margin:0; padding:0; width:100%; background-color:#f2f4f7; -webkit-font-smoothing:antialiased;">

{{-- Texto de vista previa: lo que se lee en la bandeja de entrada
     junto al asunto, antes de abrir el correo. --}}
@isset($preheader)
    <div style="display:none; font-size:1px; color:#f2f4f7; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">
        {{ $preheader }}
    </div>
@endisset

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
       style="background-color:#f2f4f7; padding:24px 12px;">
    <tr>
        <td align="center">

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
                   style="width:600px; max-width:600px; background-color:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #e3e8ee;">

                {{-- ==================== Encabezado ==================== --}}
                <tr>
                    <td align="center" style="background-color:{{ $color }}; padding:26px 24px;">
                        @if($logo)
                            {{-- El estilo del propio <img> define cómo se ve
                                 el texto alternativo: si el gestor bloquea
                                 las imágenes (Gmail y Outlook lo hacen por
                                 defecto), se lee en blanco sobre el color de
                                 la marca en lugar de en negro. --}}
                            <img src="{{ $logo }}" alt="{{ $sucursalNombre }}" width="132"
                                 style="display:block; margin:0 auto 10px auto; max-width:132px; height:auto; border:0;
                                        font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff;">
                        @endif
                        <div style="font-family:Arial,Helvetica,sans-serif; font-size:19px; font-weight:bold; color:#ffffff; letter-spacing:.3px;">
                            {{ $sucursalNombre }}
                        </div>
                    </td>
                </tr>

                {{-- Título del mensaje --}}
                <tr>
                    <td style="padding:26px 32px 0 32px; font-family:Arial,Helvetica,sans-serif;">
                        <h1 style="margin:0 0 4px 0; font-size:21px; line-height:28px; color:#1a2733; font-weight:bold;">
                            {{ $titulo }}
                        </h1>
                        <div style="height:3px; width:46px; background-color:{{ $color }}; margin-top:10px;"></div>
                    </td>
                </tr>

                {{-- ==================== Cuerpo ==================== --}}
                <tr>
                    <td style="padding:20px 32px 8px 32px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:23px; color:#3d4852;">

                        @isset($saludo)
                            <p style="margin:0 0 14px 0; font-size:16px; color:#1a2733;"><strong>{{ $saludo }}</strong></p>
                        @endisset

                        @foreach(($parrafos ?? []) as $parrafo)
                            <p style="margin:0 0 14px 0;">{{ $parrafo }}</p>
                        @endforeach

                        {{-- Importe destacado --}}
                        @isset($destacado)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="margin:6px 0 18px 0; background-color:#f7f9fc; border:1px solid #e3e8ee; border-left:4px solid {{ $color }}; border-radius:6px;">
                                <tr>
                                    <td style="padding:16px 20px; font-family:Arial,Helvetica,sans-serif;">
                                        <div style="font-size:12px; color:#6b7785; text-transform:uppercase; letter-spacing:.6px;">
                                            {{ $destacado['etiqueta'] }}
                                        </div>
                                        <div style="font-size:29px; font-weight:bold; color:{{ $color }}; padding-top:3px;">
                                            {{ $destacado['valor'] }}
                                        </div>
                                        @if(!empty($destacado['nota']))
                                            <div style="font-size:13px; color:#6b7785; padding-top:4px;">
                                                {{ $destacado['nota'] }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @endisset

                        {{-- Ficha de datos --}}
                        @if(!empty($datos))
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="margin:4px 0 18px 0; border:1px solid #e3e8ee; border-radius:6px; border-collapse:separate;">
                                @foreach($datos as $etiqueta => $valor)
                                    <tr>
                                        <td style="padding:10px 16px; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#6b7785; width:42%; border-bottom:{{ $loop->last ? 'none' : '1px solid #eef1f5' }};">
                                            {{ $etiqueta }}
                                        </td>
                                        <td style="padding:10px 16px; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#1a2733; font-weight:bold; border-bottom:{{ $loop->last ? 'none' : '1px solid #eef1f5' }};">
                                            {{ $valor }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        {{-- Aviso --}}
                        @isset($aviso)
                            @php
                                $estilos = [
                                    'info' => ['#eef4fb', '#c9dcf0', '#1F4E79'],
                                    'alerta' => ['#fdf3f3', '#f3cfcf', '#B32020'],
                                    'exito' => ['#f0f9f4', '#cce7d8', '#1E7B34'],
                                ][$aviso['tipo'] ?? 'info'];
                            @endphp
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="margin:4px 0 18px 0; background-color:{{ $estilos[0] }}; border:1px solid {{ $estilos[1] }}; border-radius:6px;">
                                <tr>
                                    <td style="padding:13px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px; line-height:21px; color:{{ $estilos[2] }};">
                                        {{ $aviso['texto'] }}
                                    </td>
                                </tr>
                            </table>
                        @endisset

                        {{-- Botón: se arma con tabla para que también
                             funcione en Outlook, que ignora los
                             enlaces con estilo de botón. --}}
                        @isset($accion)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 20px 0;">
                                <tr>
                                    <td align="center" style="border-radius:6px; background-color:{{ $color }};">
                                        <a href="{{ $accion['url'] }}" target="_blank"
                                           style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:6px;">
                                            {{ $accion['texto'] }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        @endisset

                        @isset($cierre)
                            <p style="margin:0 0 6px 0;">{{ $cierre }}</p>
                        @endisset

                        <p style="margin:16px 0 0 0; color:#6b7785;">
                            Un saludo,<br>
                            <strong style="color:#1a2733;">{{ $sucursalNombre }}</strong>
                        </p>
                    </td>
                </tr>

                {{-- Enlace de respaldo del botón: si no se puede pulsar,
                     la dirección queda visible para copiarla. --}}
                @isset($accion)
                    <tr>
                        <td style="padding:0 32px 18px 32px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:18px; color:#8894a0;">
                            Si el botón no funciona, copie y pegue esta dirección en su navegador:<br>
                            <span style="color:{{ $color }}; word-break:break-all;">{{ $accion['url'] }}</span>
                        </td>
                    </tr>
                @endisset

                {{-- ==================== Pie ==================== --}}
                <tr>
                    <td style="padding:20px 32px 26px 32px; background-color:#fafbfc; border-top:1px solid #eef1f5;
                               font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:19px; color:#8894a0;">
                        <strong style="color:#5a6672;">{{ $sucursalNombre }}</strong><br>
                        @if($contacto){{ $contacto }}<br>@endif
                        @if($telefonos)Tel: {{ $telefonos }}<br>@endif
                        @if($sucursal->nit ?? null)NIT {{ $sucursal->nit }}@endif
                        <div style="margin-top:12px; padding-top:12px; border-top:1px solid #eef1f5;">
                            Este mensaje se generó automáticamente. Por favor, no responda a este correo:
                            para cualquier consulta comuníquese con nosotros por los medios de contacto indicados arriba.
                        </div>
                    </td>
                </tr>
            </table>

            <div style="font-family:Arial,Helvetica,sans-serif; font-size:11px; color:#a3adb8; padding-top:14px;">
                Enviado con GestISP
            </div>

        </td>
    </tr>
</table>
</body>
</html>
