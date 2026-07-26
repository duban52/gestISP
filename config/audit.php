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

        // Temperatura y uptime los refresca el sondeo de OLTs
        App\Models\Olt::class => [
            'temperature', 'uptime', 'status',
        ],

        App\Models\PppoeAccount::class => [
            'last_seen_at', 'is_online', 'rx_bytes', 'tx_bytes',
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
