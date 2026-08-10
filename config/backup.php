<?php

/*
|--------------------------------------------------------------------------
| Copias de seguridad
|--------------------------------------------------------------------------
|
| Aquí se configura ÚNICAMENTE el volcado de la base de datos, que es la
| parte que hace la aplicación. El empaquetado del código, la copia de
| la configuración del servidor y el envío a la NAS los hace el script
| deploy/backup/gestisp-backup.sh, que llama a `php artisan backup:run`
| para esta parte.
|
| Por qué el reparto es así: PHP sabe hablar con la base de datos y
| conoce sus credenciales sin que haya que repetirlas en ningún script;
| el sistema operativo sabe empaquetar archivos y moverlos por la red.
| Cada uno hace lo suyo y las credenciales de la base de datos viven en
| un solo sitio (.env).
|
*/

return [

    /*
    |----------------------------------------------------------------
    | Dónde se guardan las copias en el propio servidor
    |----------------------------------------------------------------
    |
    | Va bajo storage/, NUNCA bajo public/: un volcado de la base de
    | datos contiene los datos de todos los clientes y sus contraseñas
    | PPPoE. Si estuviera en public/ bastaría con adivinar el nombre
    | del archivo para descargarlo sin haber iniciado sesión.
    |
    | Esta carpeta es solo la ESCALA: la copia que de verdad protege
    | es la que queda en la NAS. Un servidor que se quema se lleva sus
    | copias locales con él.
    |
    */

    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    |----------------------------------------------------------------
    | Binario de mysqldump
    |----------------------------------------------------------------
    |
    | En Ubuntu basta con el nombre (está en el PATH). Se deja
    | configurable porque en Windows/Laragon hay que dar la ruta
    | completa a mysqldump.exe, y porque algunos servidores tienen
    | varias versiones instaladas.
    |
    */

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

    /*
    |----------------------------------------------------------------
    | Opciones del volcado
    |----------------------------------------------------------------
    |
    | Se listan aquí para poder ajustarlas sin tocar código, porque
    | dependen del motor y de los privilegios del usuario de base de
    | datos. Qué hace cada una y por qué está:
    |
    |  --single-transaction  Saca una foto coherente de todas las
    |                        tablas InnoDB SIN bloquearlas. Sin esto,
    |                        un volcado a las 14:30 dejaría el sistema
    |                        de cobros parado mientras dura.
    |  --quick               Lee fila por fila en vez de cargar la
    |                        tabla entera en memoria (imprescindible
    |                        con ont_metrics, que es la tabla grande).
    |  --routines/--triggers Se copian procedimientos y disparadores;
    |                        si no, la base restaurada queda coja.
    |  --no-tablespaces      Evita exigir el privilegio PROCESS, que
    |                        MySQL 8 pide para leer los tablespaces y
    |                        que un usuario de aplicación no suele
    |                        tener.
    |  --hex-blob            Los binarios (firmas de los clientes en
    |                        las órdenes) viajan en hexadecimal y no
    |                        se corrompen al restaurar.
    |
    | ATENCIÓN: no quitar los comentarios del volcado (--skip-comments
    | o --compact). La verificación de integridad busca la línea final
    | "Dump completed" que escribe mysqldump; sin ella toda copia se
    | daría por truncada.
    |
    */

    'dump_options' => [
        '--single-transaction',
        '--quick',
        '--routines',
        '--triggers',
        '--no-tablespaces',
        '--hex-blob',
        '--default-character-set=utf8mb4',
    ],

    /*
    |----------------------------------------------------------------
    | Tablas de las que se copia la estructura pero no los datos
    |----------------------------------------------------------------
    |
    | Viene VACÍO a propósito: una copia de seguridad debe ser fiel.
    |
    | Si algún día el volcado se vuelve inmanejable, las candidatas
    | son las de telemetría —ont_metrics y pppoe_session_metrics—,
    | que se rellenan solas cada 5 minutos y solo alimentan las
    | gráficas de los últimos 30 días. Excluirlas puede reducir el
    | volcado a una fracción, pero hay que asumirlo con los ojos
    | abiertos: al restaurar, las gráficas de tráfico y potencia
    | arrancan vacías y tardan un mes en volver a estar completas.
    |
    | Se excluyen los DATOS, nunca la estructura: la tabla tiene que
    | existir para que el sondeo pueda volver a escribir en ella.
    |
    */

    'exclude_data_tables' => [
        // 'ont_metrics',
        // 'pppoe_session_metrics',
    ],

    /*
    |----------------------------------------------------------------
    | Tiempo máximo del volcado
    |----------------------------------------------------------------
    |
    | Si mysqldump se cuelga (bloqueo, red caída al servidor de base
    | de datos) el proceso se mata a los 15 minutos en vez de dejar
    | un PHP colgado indefinidamente ocupando un worker.
    |
    */

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

    /*
    |----------------------------------------------------------------
    | Nivel de compresión
    |----------------------------------------------------------------
    |
    | 6 es el equilibrio habitual de gzip. Un volcado SQL es texto muy
    | repetitivo: comprime alrededor del 90 %. Subir a 9 gana poco y
    | cuesta bastante más CPU en un servidor que además está
    | atendiendo usuarios.
    |
    */

    'compression_level' => (int) env('BACKUP_COMPRESSION', 6),

    /*
    |----------------------------------------------------------------
    | Retención LOCAL
    |----------------------------------------------------------------
    |
    | Cuánto se conserva en el disco del servidor. Es corto a
    | propósito: el histórico largo vive en la NAS, aquí solo hacen
    | falta las copias recientes para una restauración rápida.
    |
    | 'keep_days' borra por antigüedad y 'keep_min' es la red de
    | seguridad: pase lo que pase nunca se baja de ese número de
    | copias. Sin ese mínimo, un servidor que estuvo dos semanas
    | apagado se quedaría sin ninguna copia local al arrancar.
    |
    */

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 7),
    'keep_min' => (int) env('BACKUP_KEEP_MIN', 4),

    /*
    |----------------------------------------------------------------
    | Aviso de copia automática atrasada
    |----------------------------------------------------------------
    |
    | La pantalla de copias avisa en rojo si la última copia
    | automática es más antigua que esto. Con dos copias diarias
    | (02:30 y 14:30) el hueco máximo normal es de 12 horas; 15 da
    | margen para un arranque lento sin dar falsas alarmas.
    |
    */

    'stale_after_hours' => (int) env('BACKUP_STALE_HOURS', 15),

];
