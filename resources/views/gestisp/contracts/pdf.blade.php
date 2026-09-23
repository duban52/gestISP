{{-- ============================================================
     Contrato único de prestación de servicios fijos

     El formato es el que exige la CRC: dos columnas, el mismo
     articulado y los anexos legales. Lo que cambia de un contrato a
     otro son los datos del suscriptor, el plan y la permanencia; el
     resto es el texto de ley con el nombre de la empresa dentro.

     Lo que el sistema NO sabe —el cargo por conexión, lo que se le
     descontó, los valores de terminación anticipada mes a mes— se
     imprime como línea en blanco, igual que en el formato impreso:
     se diligencia a mano al firmar. Inventarlo sería peor.
     ============================================================ --}}
@php
    $cliente = $contract->client;
    $plan = $contract->plan;
    $servicios = $plan?->services ?? collect();

    // La mensualidad: los servicios del plan con su IVA, que es como
    // se factura después. Si el plan no tiene servicios, no hay precio
    // que declarar y va en blanco.
    $mensualidad = $servicios->sum(fn ($s) => (float) $s->base_price * (1 + (float) $s->tax_percentage / 100));

    $meses = (int) $contract->permanence_clause;
    $inicio = $contract->activation_date ? \Illuminate\Support\Carbon::parse($contract->activation_date) : null;
    $fin = $inicio && $meses > 0 ? $inicio->copy()->addMonthsNoOverflow($meses) : null;

    $linea = '<span class="linea"></span>';
    $empresaNombre = $company?->legal_name ?: ($branch?->name ?: 'la empresa');
    $nit = $company?->document_number
        ? $company->document_number . ($company->verification_digit ? '-' . $company->verification_digit : '')
        : $branch?->nit;
    $web = $company?->website;
@endphp
        <!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Contrato {{ $contract->numero_visible }}</title>
    <style>
        @page { margin: 16px 18px 16px 18px; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 6.3pt;
            line-height: 1.22;
            color: #000;
            margin: 0;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        /* Las dos columnas del formato. En dompdf una tabla es más
           fiable que las columnas CSS, que parten los bloques. */
        .col-izq { width: 49%; padding-right: 7px; }
        .col-der { width: 49%; padding-left: 7px; border-left: 1px solid #bbb; }

        h1 { font-size: 9.5pt; margin: 0 0 2px 0; line-height: 1.1; }
        h2 {
            font-size: 6.8pt;
            margin: 5px 0 2px 0;
            padding: 1px 3px;
            background: #e8e8e8;
            border-left: 3px solid #555;
            text-transform: uppercase;
        }
        p { margin: 0 0 3px 0; text-align: justify; }

        .cabecera { border-bottom: 1.5px solid #333; padding-bottom: 2px; margin-bottom: 3px; }
        .empresa { font-size: 9.5pt; font-weight: bold; }
        .empresa-datos { font-size: 6.2pt; color: #333; }
        .numero { text-align: right; font-size: 8pt; }
        .numero strong { font-size: 12pt; }

        .datos td { padding: 1px 2px; border-bottom: 1px solid #ddd; font-size: 6.4pt; }
        .datos .etiqueta { color: #555; width: 34%; }
        .datos .valor { font-weight: bold; }

        .precios td { padding: 1.5px 2px; border-bottom: 1px solid #ddd; }
        .precios .total td { border-top: 1px solid #333; border-bottom: none; font-weight: bold; }

        .caja { border: 1px solid #999; padding: 4px; margin: 4px 0; }
        .linea { display: inline-block; border-bottom: 1px solid #333; min-width: 90px; }
        .linea-corta { display: inline-block; border-bottom: 1px solid #333; min-width: 40px; }
        .firma { border-top: 1px solid #333; padding-top: 2px; text-align: center; font-size: 6pt; }
        .mini { font-size: 5.9pt; color: #333; text-align: justify; }
        .meses td { border: 1px solid #999; padding: 3px 2px; text-align: center; font-size: 6pt; }
        /* El peso pegado a la izquierda y la celda alta: lo que queda a
           la derecha es el espacio para escribir el valor a mano. */
        .meses .valor td { text-align: left; padding: 3px 3px 9px 3px; }
        .nota { font-size: 5.9pt; color: #444; }
        .salto { page-break-after: always; }
    </style>
</head>
<body>

{{-- ==================== Cabecera ==================== --}}
<table class="cabecera">
    <tr>
        @if($logoPath)
            <td style="width: 56px;"><img src="{{ $logoPath }}" style="max-width: 52px; max-height: 34px;"></td>
        @endif
        <td>
            <span class="empresa">{{ $empresaNombre }}</span>
            <div class="empresa-datos">
                @if($nit)NIT {{ $nit }}@endif
                @if($company?->tic_registry) · Registro TIC: {{ $company->tic_registry }}@endif
                <br>
                {{ $branch?->address }}@if($branch?->municipality) — {{ $branch->municipality }}@endif
                @if($branch?->department) ({{ $branch->department }})@endif
                @if($branch?->number_phone) · Tel: {{ $branch->number_phone }}@endif
                @if($company?->email) · {{ $company->email }}@endif
                @if($web) · {{ $web }}@endif
            </div>
        </td>
        <td class="numero" style="width: 110px;">
            CONTRATO No.<br>
            <strong>{{ $contract->numero_visible }}</strong><br>
            <span class="nota">{{ now()->format('d/m/Y') }}</span>
        </td>
    </tr>
</table>

<h1 style="text-align: center; margin-bottom: 3px;">CONTRATO ÚNICO DE PRESTACIÓN DE SERVICIOS FIJOS</h1>

<table>
    <tr>
        {{-- ==================== Columna izquierda ==================== --}}
        <td class="col-izq">
            <p>
                Este contrato explica las condiciones para la prestación de los servicios entre usted y la empresa
                <strong>{{ $empresaNombre }}</strong>, por el que pagará mínimo mensualmente:
                <strong>{{ $mensualidad > 0 ? '$' . number_format($mensualidad, 0, ',', '.') : '$__________' }}</strong>.
                Este contrato tendrá vigencia de
                <strong>{{ $meses > 0 ? $meses : '____' }}</strong> meses, contados a partir del
                <strong>{{ $inicio ? $inicio->format('d/m/Y') : '___/___/______' }}</strong>.
                El plazo máximo de instalación es de 15 días hábiles. Acepto que mi contrato se renueve sucesiva
                y automáticamente por un plazo igual al inicial.
            </p>

            <h2>El servicio</h2>
            <p>
                Con este contrato nos comprometemos a prestarle los servicios que usted eligió:
                <strong>{{ $servicios->pluck('name')->implode(', ') ?: '—' }}</strong>.
                Usted se compromete a pagar oportunamente el precio acordado.
                El servicio se activará a más tardar el día: {!! $linea !!}
            </p>

            <h2>Información del suscriptor</h2>
            <table class="datos">
                <tr>
                    <td class="etiqueta">Contrato No.</td>
                    <td class="valor">{{ $contract->numero_visible }}</td>
                    <td class="etiqueta">Estrato</td>
                    <td class="valor">{{ $contract->social_stratum ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Nombre / Razón social</td>
                    <td class="valor" colspan="3">{{ $cliente?->fullName() ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Identificación</td>
                    <td class="valor">{{ trim(($cliente?->type_document ?: '') . ' ' . ($cliente?->identity_number ?: '')) ?: '—' }}</td>
                    <td class="etiqueta">Municipio</td>
                    <td class="valor">{{ $contract->municipality ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Correo electrónico</td>
                    <td class="valor">{{ $cliente?->email ?: '—' }}</td>
                    <td class="etiqueta">Departamento</td>
                    <td class="valor">{{ $contract->department ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Teléfono de contacto</td>
                    <td class="valor">{{ $cliente?->number_phone ?: '—' }}</td>
                    <td class="etiqueta">Teléfono adicional</td>
                    <td class="valor">{{ $cliente?->aditional_phone ?: '—' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Dirección del servicio</td>
                    <td class="valor" colspan="3">
                        {{ $contract->address ?: '—' }}@if($contract->neighborhood), {{ $contract->neighborhood }}@endif
                    </td>
                </tr>
            </table>

            <h2>Condiciones comerciales · características del plan</h2>
            <table class="precios">
                @forelse($servicios as $servicio)
                    <tr>
                        <td>{{ $servicio->name }}</td>
                        <td style="text-align: right;">
                            ${{ number_format((float) $servicio->base_price * (1 + (float) $servicio->tax_percentage / 100), 0, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2">{{ $plan?->name ?: 'Sin plan' }}</td></tr>
                @endforelse
                <tr class="total">
                    <td>Total mensual{{ $plan ? ' · plan ' . $plan->name : '' }}</td>
                    <td style="text-align: right;">
                        {{ $mensualidad > 0 ? '$' . number_format($mensualidad, 0, ',', '.') : '$__________' }}
                    </td>
                </tr>
            </table>
            <p class="nota">Cable módem / equipos entregados en comodato: {!! $linea !!}</p>

            <h2>Principales obligaciones del usuario</h2>
            <p>
                1) Pagar oportunamente los servicios prestados, incluyendo los intereses de mora cuando haya
                incumplimiento. 2) Suministrar información verdadera. 3) Hacer uso adecuado de los equipos y los
                servicios. 4) No divulgar ni acceder a pornografía infantil (consultar anexo). 5) Avisar a las
                autoridades cualquier evento de robo o hurto de elementos de la red, como el cable. 6) No cometer
                ni ser partícipe de actividades de fraude. 7) Hacer uso adecuado de su derecho a presentar PQR.
                8) Actuar de buena fe. El operador podrá terminar el contrato ante incumplimiento de estas
                obligaciones.
            </p>

            <h2>Pago y facturación</h2>
            <p>
                La factura le debe llegar como mínimo 5 días hábiles antes de la fecha de pago. Si no llega, puede
                solicitarla a través de nuestros Medios de Atención y debe pagarla oportunamente.
            </p>
            <p>
                Si no paga a tiempo, previo aviso, suspenderemos su servicio hasta que pague sus saldos pendientes.
                Contamos con 3 días hábiles luego de su pago para reconectarle el servicio. Si no paga a tiempo,
                también podemos reportar su deuda a las centrales de riesgo. Para esto tenemos que avisarle por lo
                menos con 20 días calendario de anticipación. Si paga luego de este reporte tenemos la obligación,
                dentro del mes siguiente, de informar su pago para que ya no aparezca reportado.
            </p>
            <p>
                Si tiene un reclamo sobre su factura, puede presentarlo antes de la fecha de pago y en ese caso no
                debe pagar las sumas reclamadas hasta que resolvamos su solicitud. Si ya pagó, tiene 6 meses para
                presentar la reclamación.
            </p>
            <p>Firma de aceptación de facturación electrónica: {!! $linea !!}</p>
        </td>

        {{-- ==================== Columna derecha ==================== --}}
        <td class="col-der">
            <h2>Calidad y compensación</h2>
            <p>
                Cuando se presente indisponibilidad del servicio o este se suspenda a pesar de su pago oportuno, lo
                compensaremos en su próxima factura. Debemos cumplir con las condiciones de calidad definidas por
                la CRC.
            </p>

            <h2>Cesión</h2>
            <p>
                Si quiere ceder este contrato a otra persona, debe presentar una solicitud por escrito a través de
                nuestros Medios de Atención, acompañada de la aceptación por escrito de la persona a la que se hará
                la cesión. Dentro de los 15 días hábiles siguientes analizaremos su solicitud y le daremos una
                respuesta. Si se acepta la cesión queda liberado de cualquier responsabilidad con nosotros.
            </p>

            <h2>Modificación</h2>
            <p>
                Nosotros no podemos modificar el contrato sin su autorización. Esto incluye que no podemos cobrarle
                servicios que no haya aceptado expresamente. Si esto ocurre tiene derecho a terminar el contrato,
                incluso estando vigente la cláusula de permanencia mínima, sin la obligación de pagar suma alguna
                por este concepto. No obstante, usted puede en cualquier momento modificar los servicios
                contratados. Dicha modificación se hará efectiva en el período de facturación siguiente, para lo
                cual deberá presentar la solicitud por lo menos con 3 días hábiles de anterioridad al corte de
                facturación.
            </p>

            <h2>Suspensión</h2>
            <p>
                Usted tiene derecho a solicitar la suspensión del servicio por un máximo de 2 meses al año. Para
                esto debe presentar la solicitud antes del inicio del ciclo de facturación que desea suspender. Si
                existe una cláusula de permanencia mínima, su vigencia se prorrogará por el tiempo que dure la
                suspensión.
            </p>

            <h2>Terminación</h2>
            <p>
                Usted puede terminar el contrato en cualquier momento sin penalidades. Para esto debe realizar una
                solicitud a través de cualquiera de nuestros Medios de Atención mínimo 3 días hábiles antes del
                corte de facturación (su corte de facturación es el día {!! $linea !!} de cada mes). Si presenta la
                solicitud con una anticipación menor, la terminación del servicio se dará en el siguiente periodo
                de facturación.
            </p>
            <p>
                Así mismo, usted puede cancelar cualquiera de los servicios contratados, para lo que le
                informaremos las condiciones en las que serán prestados los servicios no cancelados y actualizaremos
                el contrato. Si el operador no inicia la prestación del servicio en el plazo acordado, usted puede
                pedir la restitución de su dinero y la terminación del contrato.
            </p>

            <div class="caja">
                <strong>Valor a pagar si termina el contrato anticipadamente, según el mes</strong>
                <table class="meses" style="margin-top: 3px;">
                    @foreach(array_chunk(range(1, 12), 6) as $fila)
                        <tr>
                            @foreach($fila as $mes)<td>Mes {{ $mes }}</td>@endforeach
                        </tr>
                        <tr class="valor">
                            @foreach($fila as $mes)<td>$</td>@endforeach
                        </tr>
                    @endforeach
                </table>
            </div>

            <h2>Cambio de domicilio</h2>
            <p>
                Usted puede cambiar de domicilio y continuar con el servicio siempre que sea técnicamente posible.
                Si desde el punto de vista técnico no es viable el traslado del servicio, usted puede ceder su
                contrato a un tercero o terminarlo pagando el valor de la cláusula de permanencia mínima si esta
                está vigente.
            </p>

            <h2>Cobro por reconexión del servicio</h2>
            <p>
                En caso de suspensión del servicio por mora en el pago, podremos cobrarle un valor por reconexión
                que corresponderá estrictamente a los costos asociados a la operación de reconexión. En caso de
                servicios empaquetados procede máximo un cobro de reconexión por cada tipo de conexión empleado en
                la prestación de los servicios. Costo de reconexión:
                <strong>
                    {{ $branch?->reconnection_price > 0
                        ? '$' . number_format((float) $branch->reconnection_price, 0, ',', '.')
                        : '$__________' }}
                </strong>
            </p>
            <p>
                El usuario es el ÚNICO responsable por el contenido y la información que se curse a través de la
                red y del uso que se haga de los equipos o de los servicios.
            </p>
            <p class="nota">
                Los equipos de comunicaciones que ya no use son desechos que no deben ser botados a la caneca;
                consulte nuestra política de recolección de aparatos en desuso{{ $web ? ' en ' . $web : '' }}.
            </p>
        </td>
    </tr>
</table>

<div class="salto"></div>

{{-- ==================== Página 2 ==================== --}}
<table>
    <tr>
        <td class="col-izq">
            <h2>Cómo comunicarse con nosotros (medios de atención)</h2>
            <p>
                1. Nuestros medios de atención son: oficinas físicas, página web, redes sociales y líneas
                telefónicas{{ $branch?->number_phone ? ' (tel. ' . $branch->number_phone . ')' : '' }}.
            </p>
            <p>
                2. Presente cualquier queja, petición, reclamo o recurso a través de estos medios y le
                responderemos en máximo 15 días hábiles.
            </p>
            <p>
                3. Si no respondemos es porque aceptamos su petición o reclamo. Esto se llama silencio
                administrativo positivo y aplica para internet y telefonía.
            </p>
            <p>
                4. Si no está de acuerdo con nuestra respuesta, cuando su queja o petición esté relacionada con
                actos de negativa del contrato, suspensión del servicio, terminación del contrato, corte y
                facturación, usted puede insistir en su solicitud ante nosotros dentro de los 10 días hábiles
                siguientes a la respuesta, y pedir que, si no llegamos a una solución satisfactoria para usted,
                enviemos su reclamo directamente a la SIC (Superintendencia de Industria y Comercio), quien
                resolverá de manera definitiva su solicitud. Esto se llama recurso de reposición y en subsidio
                apelación.
            </p>

            <h2>Acepto cláusula de permanencia mínima</h2>
            <p>
                En consideración a que le estamos otorgando un descuento respecto del valor del cargo por conexión,
                o le diferimos el pago del mismo, se incluye la presente cláusula de permanencia mínima. En la
                factura encontrará el valor a pagar si decide terminar el contrato anticipadamente.
            </p>
            <table class="datos">
                <tr>
                    <td class="etiqueta">Valor total del cargo por conexión</td>
                    <td class="valor">$ {!! $linea !!}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Suma descontada o diferida del cargo por conexión</td>
                    <td class="valor">$ {!! $linea !!}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Fecha de inicio de la permanencia mínima</td>
                    <td class="valor">{{ $inicio ? $inicio->format('d/m/Y') : '___/___/______' }}</td>
                </tr>
                <tr>
                    <td class="etiqueta">Fecha de fin de la permanencia mínima</td>
                    <td class="valor">{{ $fin ? $fin->format('d/m/Y') : '___/___/______' }}</td>
                </tr>
            </table>
            <p class="nota">
                @if($meses > 0)
                    Permanencia mínima pactada: {{ $meses }} mes(es).
                @else
                    Este contrato se suscribe <strong>sin cláusula de permanencia mínima</strong>.
                @endif
            </p>

            <table style="margin-top: 26px;">
                <tr>
                    <td style="width: 60%; padding-right: 10px;">
                        <div class="firma">
                            Aceptación del contrato mediante firma o cualquier otro medio válido<br>
                            {{ $cliente?->fullName() }} · CC/CE {{ $cliente?->identity_number }}
                        </div>
                    </td>
                    <td style="width: 40%;">
                        <div class="firma">Huella</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding-top: 24px;">
                        <div class="firma">Asesor</div>
                    </td>
                    <td style="padding-top: 24px;">
                        <div class="firma">Fecha ____ / ____ / ________</div>
                    </td>
                </tr>
            </table>
            <p class="nota" style="margin-top: 6px;">
                Consulte el régimen de protección de usuarios en www.crcom.gov.co
            </p>
        </td>

        <td class="col-der">
            <h2>Obligaciones y condiciones del usuario</h2>
            <p class="mini">
                <strong>1. Equipos y red.</strong> Permitir el ingreso de personal de {{ $empresaNombre }} para
                auditorías y mantenimientos; verificar la identidad del personal a través de nuestras líneas de
                atención; responder por daño o deterioro en los equipos; devolver los equipos recibidos, so pena de
                pagarlos hasta por su precio de compra; informar el cambio de dirección de instalación; reportar y
                abstenerse de realizar conexiones fraudulentas o no autorizadas, como phishing o spamming, entre
                otras; no interceptar comunicaciones.
            </p>
            <p class="mini">
                <strong>2. Cambio de plan.</strong> Aplica en el periodo de facturación siguiente; estar al día;
                buen comportamiento de pago; solo lo puede solicitar el titular; se pierden las promociones previas.
            </p>
            <p class="mini">
                <strong>3. Tarifas.</strong> El aumento de tarifas no excederá anualmente el 30% de la tarifa
                vigente antes del incremento, más un porcentaje igual al IPC del año anterior; los incrementos se
                podrán realizar en cualquier tiempo, sin que el cómputo total de los aumentos supere el porcentaje
                establecido.
            </p>
            <p class="mini">
                <strong>4. Factores limitantes de la velocidad de internet.</strong> a) Controlados por
                {{ $empresaNombre }}: alta latencia en red, congestión o fallas en el canal de internet, redes
                troncales y de acceso, comportamientos anómalos de tráfico, entre otros. b) No controlados por
                {{ $empresaNombre }}: consumo excesivo del ancho de banda por aplicaciones del usuario, congestión
                en la red wifi o en dispositivos adicionales, fallas y congestión en las troncales internacionales
                de internet, entre otros. La velocidad contratada podrá disminuir al usar wifi, dependiendo del
                entorno y la distancia al módem.
            </p>
            <p class="mini">
                <strong>5. Vigencia.</strong> La vigencia del contrato inicia al vencimiento del beneficio
                promocional de servicio gratuito; si el cliente cancela los servicios durante la vigencia del
                beneficio promocional deberá pagar los costos de conexión.
            </p>
            <p class="mini">
                <strong>6. Equipos instalados.</strong> No podrán ser alterados, reubicados ni trasladados del
                lugar de instalación; hacerlo es causal de terminación del contrato. El usuario manifiesta conocer
                los términos y condiciones de los servicios adicionales contratados{{ $web ? ', disponibles en ' . $web : '' }}.
            </p>
            <p class="mini">
                <strong>7. Terminación por el operador.</strong> {{ $empresaNombre }} podrá terminar o suspender
                el servicio sin requerimiento ni declaración judicial por: incumplimiento del usuario de alguna
                obligación; retardo o falta de pago; fraude; entrega de información falsa o inconsistente; muerte o
                extinción de la personalidad jurídica; explotación comercial no autorizada. La terminación no exime
                del pago de las obligaciones causadas ni de los costos de cobranza judicial y extrajudicial. El
                retardo en el pago causará intereses de mora a la máxima tasa permitida por la ley. El usuario no
                recuperará los beneficios promocionales por ponerse al día. El usuario autoriza deducir o compensar
                cualquier suma de dinero que le adeude a {{ $empresaNombre }}.
            </p>
            <p class="mini">
                <strong>8. Título ejecutivo.</strong> Las partes establecen que este contrato será exigible
                judicialmente a partir del incumplimiento de cualquier obligación del usuario, para lo cual se
                agregará la factura respectiva determinando la cuantía adeudada, integrándose así un título
                ejecutivo con una obligación clara, expresa y exigible a cargo del usuario.
                @if($web) Consulte las condiciones técnicas y de calidad de cada tecnología en {{ $web }}.@endif
            </p>

            <h2 style="margin-top: 7px;">Anexos legales</h2>
<p class="mini">
    Para el cumplimiento de obligaciones de carácter legal, como las dispuestas en el Decreto número 1524 de 2012,
    orientado a prevenir el acceso de menores de edad a cualquier modalidad de información pornográfica y a impedir
    el aprovechamiento de redes globales de información con fines de explotación sexual infantil u ofrecimiento de
    servicios comerciales que impliquen abuso sexual con menores de edad; las leyes 1266 de 2008, 1581 de 2012 y
    2300 de 2023 sobre datos personales y el derecho a la intimidad; y la normatividad relacionada con el riesgo de
    lavado de activos y financiación del terrorismo.
</p>
            <h2>Anexo 1. Hábeas data y autorización de informaciones y referencias</h2>
            <p class="mini">
                Con la suscripción del contrato de prestación de servicios de telecomunicaciones, en los términos de
                las leyes 1266 de 2008, 1581 de 2012 y 2300 de 2023, EL USUARIO autoriza a {{ $empresaNombre }}
                para que consulte de cualquier fuente, reporte y/o actualice ante cualquier operador de información,
                y sea fuente de información respecto de los datos sobre su persona, nombre, apellidos y documento de
                identificación, su comportamiento y crédito comercial, hábitos de pago, manejo de sus cuentas
                bancarias y, en general, el cumplimiento de sus obligaciones comerciales y pecuniarias, con el fin
                de verificar sus condiciones para acceder o modificar productos o servicios en {{ $empresaNombre }}.
            </p>
            <p class="mini">
                Así mismo, de conformidad con lo dispuesto en el artículo 6 del Decreto 1377 de 2013, y sin
                perjuicio del derecho que le asiste al usuario de abstenerse de autorizar el tratamiento de datos
                sensibles, el usuario manifiesta que autoriza de manera libre, previa, informada, voluntaria y
                expresa el tratamiento de datos sensibles (huella dactilar) con la finalidad de validar su
                identidad. Para esta última finalidad también autoriza el tratamiento de sus datos sensibles de voz
                y rostro para validar su identidad. La autorización otorgada resulta irrevocable mientras existan
                obligaciones contractuales entre las partes. La política de tratamiento de datos de
                {{ $empresaNombre }} se encuentra disponible para consulta{{ $web ? ' en ' . $web : '' }}.
                Así mismo, autorizo de manera expresa a {{ $empresaNombre }} para que pueda comunicarse conmigo a
                través de WhatsApp a la línea móvil suministrada.
            </p>
        </td>
    </tr>
</table>

<div class="salto"></div>

{{-- Los anexos continúan: empiezan en la columna derecha de la página
     anterior, como fluye el formato impreso. --}}
<table>
    <tr>
        <td class="col-izq">
            <h2>Anexo 1. Hábeas data (continuación)</h2>
            <p class="mini">
                <strong>Parágrafo primero.</strong> Previa la realización de eventuales reportes a las centrales de
                información sobre comportamiento crediticio del usuario, {{ $empresaNombre }} le remitirá
                comunicación con una antelación de por lo menos veinte (20) días calendario a la fecha en que se
                produzca el reporte, indicando la obligación en mora que lo generó, el monto y su fundamento.
            </p>
            <p class="mini">
                <strong>Parágrafo segundo.</strong> Los datos personales suministrados serán objeto de tratamiento
                únicamente para los siguientes propósitos: consulta y reporte de información ante operadores de
                bancos de datos de contenido crediticio y financiero, y perfilamiento para fines comerciales y
                publicitarios relacionados con opciones y productos ofrecidos al público, llevados a cabo por
                {{ $empresaNombre }} o por terceros. Esta información será conservada con la debida diligencia.
            </p>
            <p class="mini">
                El usuario puede en cualquier momento ejercer los derechos previstos en el artículo 8 de la Ley 1581
                de 2012, en especial: a) conocer, actualizar y rectificar sus datos personales; b) solicitar prueba
                de la autorización otorgada; c) ser informado del uso dado a sus datos personales; d) presentar
                quejas ante la Superintendencia de Industria y Comercio; e) revocar la autorización y/o solicitar la
                supresión del dato cuando en el tratamiento no se respeten los principios, derechos y garantías
                constitucionales y legales; f) acceder en forma gratuita a sus datos personales objeto de
                tratamiento.
            </p>
            <p class="mini">
                <strong>Parágrafo tercero.</strong> El responsable del tratamiento de la información es
                {{ $empresaNombre }}{{ $nit ? ', con NIT ' . $nit : '' }}{{ $company?->address ? ', dirección ' . $company->address : '' }}{{ $company?->phone ? ' y teléfono ' . $company->phone : '' }}.
            </p>

            <div class="caja" style="margin-top: 6px;">
                <p class="mini" style="margin-bottom: 10px;">
                    Yo, <strong>{{ $cliente?->fullName() ?: '________________________' }}</strong>,
                    con cédula <strong>{{ $cliente?->identity_number ?: '______________' }}</strong>, en calidad de
                    USUARIO, autorizo a {{ $empresaNombre }}, en el marco de este contrato único de servicios
                    fijos, al tratamiento de mis datos personales según el Anexo 1 anteriormente descrito.
                </p>
                <div class="firma" style="margin-top: 20px;">Firma del usuario</div>
            </div>
        </td>

        <td class="col-der">
            <h2>Anexo 2. Pornografía infantil</h2>
            <p class="mini">
                EL USUARIO declara expresamente que conoce y acata las normas legales que prohíben contenidos
                perjudiciales para menores de edad en cualquier modalidad de información en las redes globales,
                como pornografía, explotación sexual u ofrecimiento de servicios comerciales que impliquen abuso
                sexual, incluida la Ley 679 de agosto 3 de 2001, el Decreto 1524 de 2002 y el Código Penal,
                artículos 218 y 219A, y las normas que los modifiquen o adicionen. Además, se obliga a prevenir y a
                no permitir el acceso desde su terminal de menores de edad a dichos contenidos; en especial, no
                podrá alojar en su propio sitio: a) imágenes, textos, documentos o archivos audiovisuales que
                impliquen directa o indirectamente actividades sexuales de menores de edad; b) material
                pornográfico, en imágenes o videos, si existen indicios de que las personas fotografiadas o filmadas
                son menores de edad; c) vínculos o enlaces sobre sitios telemáticos que contengan o distribuyan
                material pornográfico relativo a menores de edad.
            </p>
            <p class="mini">
                EL USUARIO deberá: a) denunciar ante las autoridades competentes cualquier acto criminal contra
                menores de edad del que tenga conocimiento, incluso la difusión de material pornográfico asociado a
                menores; b) combatir con todos los medios técnicos a su alcance la difusión de material pornográfico
                de menores de edad; c) abstenerse de usar las redes globales de información para la divulgación de
                material ilegal con menores de edad; y d) establecer mecanismos técnicos de bloqueo por medio de los
                cuales los usuarios puedan protegerse a sí mismos o a sus hijos de material ilegal, ofensivo o
                indeseable en relación con menores de edad.
            </p>

            <h2>Anexo 3. SARLAFT</h2>
            <p class="mini">
                EL USUARIO: a) acepta la terminación automática de la relación contractual en caso de encontrarse
                relacionado negativamente en listas o noticias por temas asociados al lavado de activos o la
                financiación del terrorismo; b) autoriza a revelar su información personal y de sus negocios en
                caso de ser requerida por una autoridad competente en Colombia; c) se compromete a actualizar
                anualmente la información, o en un tiempo menor en caso de que ocurran cambios en la información
                suministrada.
            </p>
            <p class="mini">
                EL USUARIO declara que no se encuentra en ninguna de las listas establecidas a nivel nacional o
                internacional para el control del lavado de activos y la financiación del terrorismo; así mismo, se
                responsabiliza ante {{ $empresaNombre }} porque sus empleados, accionistas, miembros de junta
                directiva o de junta de socios, sus representantes legales y su revisor fiscal tampoco se encuentren
                en dichas listas, y se compromete a actualizar anualmente la información.
            </p>
            <p class="mini">
                {{ $empresaNombre }} podrá terminar de manera unilateral e inmediata el presente contrato en caso de
                que EL USUARIO, sus socios o accionistas y/o sus administradores llegaren a ser: a) vinculados por
                las autoridades competentes a cualquier tipo de investigación por delitos de narcotráfico,
                terrorismo, lavado de activos, financiación del terrorismo, testaferrato, tráfico de estupefacientes
                o cualquier delito contra el orden constitucional; b) incluidos en listas para el control del lavado
                de activos y la financiación del terrorismo administradas por cualquier autoridad nacional o
                extranjera, tales como la lista de la Oficina de Control de Activos en el Exterior (OFAC) emitida
                por la Oficina del Tesoro de los Estados Unidos de América, la lista de la Organización de las
                Naciones Unidas y otras listas públicas relacionadas con el tema; o c) condenados por las
                autoridades competentes en cualquier tipo de proceso judicial relacionado con la comisión de delitos
                de igual o similar naturaleza a los indicados en esta cláusula.
            </p>
            <p class="mini">
                EL USUARIO indemnizará y mantendrá libre de cualquier daño a {{ $empresaNombre }} por cualquier
                multa o perjuicio probado que sufra por o con ocasión del incumplimiento, por parte de EL USUARIO,
                de las obligaciones que le apliquen en materia de prevención del riesgo de lavado de activos y
                financiación del terrorismo, así como de cualquier reclamo judicial, extrajudicial o administrativo
                que las autoridades competentes inicien en su contra por dicho incumplimiento.
            </p>

            <div class="caja" style="margin-top: 10px;">
                <table>
                    <tr>
                        <td style="width: 50%; padding-right: 8px;">
                            <div class="firma" style="margin-top: 26px;">
                                Firma del usuario<br>
                                {{ $cliente?->fullName() }} · CC/CE {{ $cliente?->identity_number }}
                            </div>
                        </td>
                        <td style="width: 50%;">
                            <div class="firma" style="margin-top: 26px;">
                                Por {{ $empresaNombre }}<br>
                                Nombre y cargo
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

</body>
</html>
