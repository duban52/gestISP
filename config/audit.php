<?php

/*
|--------------------------------------------------------------------------
| Trazabilidad / auditoría
|--------------------------------------------------------------------------
|
| Qué se registra y qué NO. La regla es: se audita todo lo que hace una
| PERSONA, y se descarta el ruido que genera la MÁQUINA sola.
|
| Sin este filtro la auditoría sería inservible: el sondeo de ONTs
| actualiza la potencia de miles de equipos cada 5 minutos, lo que
| generaría cientos de miles de registros diarios que esconderían las
| acciones reales de los usuarios.
|
*/

return [

    /*
    |----------------------------------------------------------------
    | Activación
    |----------------------------------------------------------------
    */

    'enabled' => env('AUDIT_ENABLED', true),

    /*
    |----------------------------------------------------------------
    | Modelos que NO se auditan
    |----------------------------------------------------------------
    |
    | - Audit: se auditaría a sí mismo (bucle infinito).
    | - OntMetric / PppoeSessionMetric: son muestras de telemetría que
    |   escriben los comandos de sondeo cada 5 minutos.
    | - UserSession / FailedLogin: ya tienen su propio módulo de
    |   trazabilidad de sesiones.
    |
    */

    'excluded_models' => [
        App\Models\Audit::class,
        App\Models\OntMetric::class,
        // Faltaba, y costo 1.965.252 filas: `olt:poll-ports` escribe
        // una lectura por puerto PON cada cinco minutos. Ahora la
        // exclusion vive ademas en el propio modelo (trait NotAudited),
        // que es la via que no se olvida.
        App\Models\OltPortMetric::class,
        App\Models\PppoeSessionMetric::class,
        App\Models\UserSession::class,
        App\Models\FailedLogin::class,
        App\Models\PaymentAudit::class,
    ],

    /*
    |----------------------------------------------------------------
    | Campos que no cuentan como cambio
    |----------------------------------------------------------------
    |
    | Los escribe el sistema al refrescar datos del equipo, no una
    | persona. Si un cambio SOLO toca estos campos, no se registra.
    |
    | Clave '*' = para cualquier modelo.
    |
    */

    'ignored_attributes' => [
        '*' => ['updated_at', 'created_at', 'remember_token'],

        // Potencia y estado los reescribe onts:poll cada 5 minutos
        App\Models\Ont::class => [
            'rx_power', 'status', 'metrics_history', 'catv_checked_at',
        ],

        // Temperatura y uptime los refresca el sondeo de OLTs; la hora
        // de la consulta, el motivo del último fallo y la pausa tras
        // una credencial rechazada, también: los escribe la consulta
        // de estado en cada visita a la ficha o al listado.
        App\Models\Olt::class => [
            'temperature', 'uptime', 'status',
            'status_checked_at', 'last_error', 'auth_failed_at',
        ],

        // LO QUE LLENÓ EL DISCO DE PRODUCCIÓN.
        //
        // Esta lista decía `is_online`, `rx_bytes` y `tx_bytes`:
        // columnas que YA NO EXISTEN. Se renombraron y la lista se
        // quedó con los nombres viejos, así que no ignoraba nada. El
        // sondeo de PPPoE escribe `connected`, `last_address` y
        // `last_polled_at` en las 2.368 cuentas cada cinco minutos, y
        // cada escritura acabó en la auditoría: 37,5 millones de filas
        // y 21 GB en noviembre de 2026, que junto con los binlogs
        // llenaron el disco y tumbaron el sitio.
        //
        // Hay una prueba (AuditTelemetryTest) que falla si el sondeo
        // escribe un campo que no esté aquí.
        App\Models\PppoeAccount::class => [
            'connected', 'last_address', 'last_seen_at', 'last_polled_at',
        ],

        // Tráfico y potencia de cada puerto PON y cada uplink, que
        // `olt:poll-ports` reescribe cada cinco minutos. El estado
        // operativo (`oper_status`) NO está: que un puerto caiga es
        // un hecho que sí interesa encontrar después.
        App\Models\PonPort::class => [
            'in_bps', 'out_bps', 'tx_power', 'measured_at', 'discovered_at',
        ],

        App\Models\OltUplink::class => [
            'in_bps', 'out_bps', 'measured_at', 'discovered_at',
        ],

        // La sucursal seleccionada cambia sola al entrar al sistema
        App\Models\User::class => [
            'selected_branch_id', 'dark_mode',
        ],
    ],

    /*
    |----------------------------------------------------------------
    | Datos sensibles
    |----------------------------------------------------------------
    |
    | Nunca se guardan en la auditoría: se reemplazan por "********".
    | Incluye contraseñas del sistema, de equipos de red y de clientes,
    | además de tokens y comunidades SNMP.
    |
    */

    'redacted_attributes' => [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'password_pppoe',
        'password_wifi',
        'remember_token',
        'token',
        'api_token',
        'access_token',
        'secret',
        'read_snmp_comunity',
        'write_snmp_comunity',
        // Los secretos de la DIAN. El PIN entra en el CUDE y la clave
        // tecnica en el CUFE: con ellos se puede fabricar el codigo de
        // un documento que la empresa nunca emitio.
        'software_pin',
        'technical_key',
        '_token',
    ],

    /*
    |----------------------------------------------------------------
    | Peticiones
    |----------------------------------------------------------------
    |
    | Se registra toda petición que MODIFIQUE algo (POST, PUT, PATCH,
    | DELETE). Las consultas (GET) no se registran una a una —serían
    | millones y no cambian nada—, salvo las que EXTRAEN información
    | del sistema (exportaciones, PDF, descargas), que sí interesan
    | para una auditoría.
    |
    */

    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

    'audited_read_patterns' => [
        '*export*',
        '*pdf*',
        '*download*',
        '*.excel',
    ],

    /*
    | Rutas que no se registran ni siendo de escritura: son sondeos
    | automáticos del navegador, no acciones del usuario.
    */

    'excluded_routes' => [
        'notifications.poll',
        'notifications.read_all',
        'onts.import.status',
        'adminlte.darkmode.toggle',
        'onts.realtime',
        'onts.metrics_history',
        'pppoe.realtime',
    ],

    /*
    |----------------------------------------------------------------
    | Conservación
    |----------------------------------------------------------------
    |
    | Días que se conservan los registros al ejecutar
    | `php artisan audits:prune`. No se borra nada de forma
    | automática: el comando debe lanzarse a propósito.
    |
    */

    'retention_days' => env('AUDIT_RETENTION_DAYS', 730),

];
