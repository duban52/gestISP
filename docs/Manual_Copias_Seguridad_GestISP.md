# Manual de copias de seguridad

**gestISP — Procedimiento de respaldo y recuperación**

---

## 1. Para qué sirve este documento

Este manual explica cómo se protegen los datos de gestISP y, sobre todo, **cómo
recuperarlos el día que haga falta**.

Está escrito para dos momentos muy distintos:

- Un martes cualquiera, para **instalar** el sistema de copias y comprobar de vez
  en cuando que sigue funcionando (secciones 2 a 7).
- El día malo, con el servidor caído y el teléfono sonando, para **restaurar**
  (secciones 8 y 9). Esa parte está escrita como una lista de pasos que se puede
  seguir sin pensar.

> **Conviene imprimirlo.** Un manual de recuperación guardado únicamente dentro
> del servidor que se ha caído no sirve de nada. Guarde una copia impresa y otra
> en un teléfono o en un servicio de notas fuera de la empresa.

---

## 2. Qué se copia

Un ISP no se recupera solo con la base de datos. Se copian tres cosas, y las
tres hacen falta:

| Qué | Archivo | Contiene |
|---|---|---|
| **Base de datos** | `gestisp-db-FECHA-auto.sql.gz` | Clientes, contratos, facturas, pagos, cajas, órdenes técnicas, inventario, red (OLT/ONT/PPPoE), usuarios y trazabilidad |
| **Código y datos de la aplicación** | `gestisp-codigo-FECHA.tar.gz` | El proyecto entero, el archivo `.env` con la configuración real y `storage/app/public` con las firmas de los clientes y las fotos de las órdenes |
| **Configuración del servidor** | `gestisp-servidor-FECHA.tar.gz` | nginx, PHP, MySQL, certificados de Let's Encrypt, tareas programadas y un inventario de todo lo instalado con sus versiones |
| **Huellas de verificación** | `gestisp-huellas-FECHA.sha256` | La huella SHA-256 de cada archivo, para poder demostrar meses después que no se ha degradado ni lo ha cambiado nadie |

### Qué NO se copia, y por qué

- **`vendor/` y `node_modules/`.** Son dependencias descargadas: se reconstruyen
  con `composer install` y `npm install`. Copiarlas multiplicaría por diez el
  tamaño de cada copia sin aportar nada.
- **Logs y cachés.** No hacen falta para volver a funcionar.
- **Las propias copias.** Por razones evidentes.

---

## 3. Cómo está montado el respaldo

El esquema es el clásico **3-2-1**, que es el mínimo aceptable: *tres* copias del
dato, en *dos* soportes distintos, con *una* fuera del equipo original.

```
   SERVIDOR DE PRODUCCION                         NAS
   ----------------------                         ---

   Base de datos en vivo
            |
            |  02:30 y 14:30
            v
   storage/app/backups/        --- rsync --->    respaldos/gestisp/AAAA-MM/
   (ultimos 7 dias)                por SSH       (ultimos 30 dias)

   /var/backups/gestisp/                         respaldos/gestisp/mensuales/
   (codigo y configuracion)                      (dia 1 de cada mes, 2 anios)
```

### Quién dispara cada cosa

| Pieza | Quién la ejecuta | Cuándo |
|---|---|---|
| Volcado de la base de datos | `php artisan backup:run` | Lo llama el script del servidor |
| Copia completa (base + código + configuración + envío) | `/usr/local/bin/gestisp-backup.sh` | Cron del sistema, 02:30 y 14:30 |
| Copia bajo demanda | Botón en gestISP → Gestión del sistema → Copias de seguridad | Cuando alguien lo pulsa |
| Restauración | `/usr/local/bin/gestisp-restore.sh` | A mano, siempre |

### Por qué el cron del sistema y no el programador de Laravel

gestISP ya tiene tareas programadas (sondeo de ONTs, recordatorios de
facturación) que corren dentro del programador de Laravel. Las copias **no** van
ahí, y es a propósito:

> Una copia de seguridad tiene que seguir haciéndose el día en que la aplicación
> está rota. Si dependiera del programador de Laravel, un despliegue fallido o un
> `composer` a medias dejaría al sistema sin copias justo cuando más falta
> hacen — y sin que nadie se enterase.

---

## 4. Instalación en el servidor

Todo lo que sigue se hace **una sola vez**, como `root`, en el servidor de
producción (Ubuntu/Debian).

### 4.1 Requisitos

```bash
sudo apt update && sudo apt install -y mysql-client rsync gzip openssh-client
```

Compruebe que la zona horaria del servidor es la correcta, porque de ella
dependen las horas del cron:

```bash
timedatectl
sudo timedatectl set-timezone America/Bogota
```

### 4.2 Ejecutar el instalador

Desde la carpeta del proyecto:

```bash
cd /var/www/gestisp/deploy/backup && sudo ./instalar.sh
```

El instalador deja:

| Archivo | Para qué |
|---|---|
| `/usr/local/bin/gestisp-backup.sh` | El script de copia |
| `/usr/local/bin/gestisp-restore.sh` | El script de restauración |
| `/etc/gestisp/backup.conf` | La configuración (permisos 600) |
| `/etc/cron.d/gestisp-backup` | Las dos copias diarias |
| `/etc/logrotate.d/gestisp-backup` | La rotación del registro |
| `/var/backups/gestisp/` | Carpeta de trabajo |

### 4.3 Configurar

```bash
sudo nano /etc/gestisp/backup.conf
```

Los valores que hay que revisar sí o sí:

| Variable | Qué poner |
|---|---|
| `APP_DIR` | Ruta del proyecto, donde está `artisan` |
| `APP_USER` | Usuario dueño de los archivos (normalmente `www-data`) |
| `NAS_HOST`, `NAS_USER`, `NAS_PORT` | Dirección y usuario de la NAS |
| `NAS_PATH` | Carpeta de destino **en la NAS** (tiene que existir) |
| `ALERT_EMAIL` | Correo al que avisar si una copia falla |
| `PING_URL` | Vigilante externo (ver 7.3). Muy recomendable |

### 4.4 Conectar con la NAS

El script corre de madrugada sin nadie delante, así que la conexión tiene que
funcionar sola. Hay dos formas, y la elección se indica en `NAS_TRANSPORTE`.

#### Opción A — rsync sobre SSH (recomendada)

Es la que conviene siempre que la NAS admita SSH: el contenido viaja **cifrado**,
se autentica con clave (no hay contraseñas guardadas en el servidor) y permite
que el script rote lo antiguo en la propia NAS.

```bash
sudo ssh-keygen -t ed25519 -f /root/.ssh/gestisp-nas -N ""
```

```bash
sudo ssh-copy-id -i /root/.ssh/gestisp-nas.pub -p 22 gestisp@192.168.1.50
```

Y se comprueba que entra sola:

```bash
sudo ssh -i /root/.ssh/gestisp-nas -p 22 gestisp@192.168.1.50 "echo Conexion correcta"
```

En una QNAP, el SSH se habilita en **Panel de control → Telnet/SSH**. Tenga en
cuenta que QNAP solo permite entrar por SSH a las cuentas del grupo de
administradores.

#### Opción B — Servidor Rsync (rsyncd)

En QNAP se activa en **HBS 3 → Servicios → Servidor Rsync**, con una cuenta
compartida y el puerto 873. Es más fácil de poner en marcha, pero tiene tres
consecuencias que hay que asumir:

1. **El contenido viaja sin cifrar.** La contraseña no se manda en claro, pero
   los archivos sí. Y esos archivos son la base de datos completa de los clientes
   y el `.env` con la contraseña de MySQL. Solo es razonable dentro de la red
   local o a través de una VPN.
2. **No se pueden crear carpetas remotas.** El destino (`NAS_MODULO` y, si se
   usa, `NAS_SUBCARPETA`, más `mensuales`) tiene que existir ya en la NAS.
3. **La rotación hay que configurarla en la NAS.** El script no puede ejecutar
   nada al otro lado, así que la carpeta crecerá sin límite hasta que alguien le
   ponga una tarea de limpieza.

Configuración:

```bash
sudo nano /etc/gestisp/backup.conf
```

```
NAS_TRANSPORTE="rsyncd"
NAS_USER="duban_restrepo"
NAS_HOST="192.168.1.100"
NAS_PORT=873
NAS_MODULO="respaldos"
NAS_SUBCARPETA="gestisp"
NAS_PASSWORD_FILE="/etc/gestisp/rsyncd.pass"
```

Para saber qué recursos publica la NAS y así acertar con `NAS_MODULO`:

```bash
rsync --list-only rsync://duban_restrepo@192.168.1.100:873/
```

La contraseña va en su propio archivo, sin salto de línea final y sin el nombre
de usuario:

```bash
printf '%s' 'LA-CONTRASENA' | sudo tee /etc/gestisp/rsyncd.pass > /dev/null && sudo chmod 600 /etc/gestisp/rsyncd.pass
```

Y se comprueba que entra:

```bash
rsync --list-only --password-file=/etc/gestisp/rsyncd.pass rsync://duban_restrepo@192.168.1.100:873/respaldos/
```

> **Importante, sea cual sea la opción:** la cuenta de la NAS debe poder escribir
> en su carpeta y **nada más**. Si además puede borrar el resto de la NAS, un
> intruso que entre en el servidor se lleva por delante las copias — que es
> exactamente lo que hacen los ataques de secuestro de datos. Si la NAS lo
> permite, active también las **instantáneas** (*snapshots*) sobre esa carpeta.

#### Si la NAS se alcanza desde fuera de la red local

Cuando el servidor entra a la NAS por una IP pública redirigida en el router
(NAT), hay dos medidas que cuestan cinco minutos y evitan disgustos:

- **Limite la regla del router a la IP del servidor.** En Mikrotik, añada
  `src-address=<IP pública del servidor>` a la regla `dst-nat`. Sin eso, el
  puerto queda abierto a internet entero y cualquiera puede intentar entrar.
- **Si usa rsyncd, monte una VPN.** WireGuard entre el servidor y el Mikrotik
  deja el 873 escuchando solo en la red interna y cifra todo el tráfico. Con
  rsyncd expuesto a internet, cualquiera que observe la conexión ve pasar los
  datos de sus clientes.

### 4.5 Probar

```bash
sudo /usr/local/bin/gestisp-backup.sh
```

Debe terminar con `Copia de seguridad COMPLETADA correctamente.` Si falla, el
motivo está en la última línea y en `/var/log/gestisp-backup.log`.

---

## 5. Horarios y cuánto se conserva

| Copia | Hora | Dónde | Se conserva |
|---|---|---|---|
| Automática | 02:30 | Servidor + NAS | 7 días en el servidor, 30 en la NAS |
| Automática | 14:30 | Servidor + NAS | 7 días en el servidor, 30 en la NAS |
| Mensual | Día 1, 02:30 | NAS (`mensuales/`) | 2 años |
| Manual | Cuando se pulsa el botón | Servidor | 7 días |

**Por qué dos al día.** La copia de la madrugada sola dejaría una ventana de
pérdida de 24 horas: si la base se rompe a las 18:00, se pierde todo el trabajo
del día — los pagos cobrados en caja, las órdenes cerradas, los clientes nuevos.
Con la copia de las 14:30 la pérdida máxima baja a media jornada.

**Por qué las mensuales.** Las copias de 30 días cubren el desastre evidente
(el disco que muere, la tabla que se borra). No cubren el error que se descubre
tarde: un dato mal cambiado en marzo del que nadie se da cuenta hasta junio. Para
eso están las mensuales.

Los periodos se cambian en `/etc/gestisp/backup.conf` (`KEEP_LOCAL_DAYS`,
`KEEP_NAS_DAYS`, `KEEP_NAS_MONTHLY_DAYS`).

> **Con `NAS_TRANSPORTE="rsyncd"`, la retención en la NAS no la aplica el
> script**, porque el demonio rsync no permite ejecutar nada al otro lado. Hay
> que crear una tarea programada en la propia NAS que borre lo anterior a 30
> días. Si se olvida, la carpeta crece hasta llenar el disco y las copias
> empiezan a fallar — normalmente un domingo.

---

## 6. Copia manual desde gestISP

Para cuando alguien quiere una foto de la base **antes** de tocar algo delicado:
una carga masiva de clientes, un cambio de tarifas, una actualización.

**Ruta:** menú lateral → **Gestión del sistema** → **Copias de seguridad**

La pantalla tiene tres partes:

1. **Respaldo automático.** Cuándo fue la última copia del servidor y si está al
   día. Es lo primero que se ve porque es lo que de verdad protege el sistema.
   Si aparece en rojo, las copias automáticas han dejado de funcionar: vaya a la
   sección 10.
2. **Copia inmediata.** El botón *Generar copia ahora*. Al terminar aparece un
   aviso verde con el enlace de descarga.
3. **Listado.** Las copias que hay en el servidor, con su botón de descarga.

### Advertencias

- La pantalla está reservada al **superadministrador**, y no por capricho: el
  archivo que se descarga contiene la base de datos entera — todos los clientes,
  sus documentos, sus contraseñas PPPoE y el histórico de pagos. No es un permiso
  que se pueda conceder marcando una casilla en el módulo de roles.
- **Cada descarga queda registrada** en Trazabilidad, con quién, cuándo y desde
  qué dirección IP.
- Una vez descargado, el archivo es responsabilidad de quien lo tiene. Guárdelo
  cifrado y bórrelo del equipo cuando ya no haga falta.
- En una base grande el volcado puede tardar minutos. El botón se deshabilita
  solo y avisa; **no cierre la página**.

> Si el navegador se queda esperando y acaba dando un error de tiempo agotado
> (`504 Gateway Timeout`), el volcado probablemente **sí** terminó: recargue la
> pantalla y búsquelo en el listado. Para que no vuelva a pasar, suba
> `fastcgi_read_timeout` en nginx (sección 10).

---

## 7. Cómo saber que las copias funcionan

Esta es la sección más importante del manual. Casi todas las historias de datos
perdidos terminan igual: *sí había copias, pero nadie las había mirado nunca*.

### 7.1 Cada día (10 segundos)

Entre en **Gestión del sistema → Copias de seguridad** y mire la tarjeta de
arriba a la izquierda. Si dice **Al día**, no hay nada más que hacer.

### 7.2 Cada semana (2 minutos)

Compruebe que en la NAS están llegando las dos copias diarias:

```bash
ssh -i /root/.ssh/gestisp-nas gestisp@192.168.1.50 "ls -lh /volume1/respaldos/gestisp/$(date +%Y-%m)/ | tail -20"
```

Deben verse dos archivos `gestisp-db-*` por cada día, y su tamaño debe ser
parecido al de los días anteriores. **Una copia que de repente pesa la mitad es
una señal de alarma**, aunque el script diga que terminó bien.

### 7.3 Vigilancia automática (recomendado)

Configure `PING_URL` en `/etc/gestisp/backup.conf` con una URL de
[healthchecks.io](https://healthchecks.io) (tiene plan gratuito) o de un Uptime
Kuma propio. El script la visita **solo cuando todo ha salido bien**.

Es el único aviso que funciona cuando el servidor entero se apaga: el vigilante
deja de recibir la señal y avisa por correo o por Telegram. Un aviso que sale del
propio servidor no llega nunca cuando el servidor es el problema.

### 7.4 Cada mes: la prueba de restauración

**Una copia que nunca se ha restaurado no es una copia, es una suposición.**

Una vez al mes, restaure la última copia sobre una base de datos **de prueba** y
compruebe que los datos están:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS gestisp_prueba CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```bash
gunzip -c /var/www/gestisp/storage/app/backups/gestisp-db-*-auto.sql.gz | mysql -u root -p gestisp_prueba
```

```bash
mysql -u root -p gestisp_prueba -e "SELECT (SELECT COUNT(*) FROM clients) AS clientes, (SELECT COUNT(*) FROM contracts) AS contratos, (SELECT COUNT(*) FROM invoices) AS facturas, (SELECT MAX(created_at) FROM payments) AS ultimo_pago;"
```

Los números tienen que parecerse a los de producción y `ultimo_pago` debe ser
cercano a la hora de la copia. Anote el resultado en el registro del anexo B.

Cuando termine:

```bash
mysql -u root -p -e "DROP DATABASE gestisp_prueba;"
```

---

## 8. Restaurar la base de datos

Para el caso más habitual: la base se corrompió, alguien borró algo grande, o una
actualización salió mal. **El servidor sigue en pie.**

### 8.1 Con el script (recomendado)

Ver las copias disponibles:

```bash
sudo /usr/local/bin/gestisp-restore.sh --listar
```

Si la copia que hace falta ya no está en el servidor, tráigala de la NAS:

```bash
scp -i /root/.ssh/gestisp-nas gestisp@192.168.1.50:/volume1/respaldos/gestisp/2026-08/gestisp-db-2026-08-10_023000-auto.sql.gz /var/www/gestisp/storage/app/backups/
```

Y restaure:

```bash
sudo /usr/local/bin/gestisp-restore.sh /var/www/gestisp/storage/app/backups/gestisp-db-2026-08-10_023000-auto.sql.gz
```

El script hace, en este orden:

1. **Verifica la copia** (gzip, huella SHA-256 y marca de cierre) *antes* de
   tocar nada. Si la copia está mal, se detiene y no ha pasado nada.
2. Pide que escriba el nombre de la base de datos. No es burocracia: obliga a
   leer en pantalla qué copia y qué base se van a usar.
3. **Guarda el estado actual** en una copia nueva. Si la restauración era la
   equivocada, se puede volver atrás.
4. Pone la aplicación en mantenimiento.
5. Restaura.
6. Limpia las cachés, cierra las sesiones abiertas y levanta la aplicación.

Si la base quedó con tablas sobrantes o muy dañada, use `--recrear`, que borra el
esquema y lo vuelve a crear desde cero:

```bash
sudo /usr/local/bin/gestisp-restore.sh --recrear /ruta/gestisp-db-....sql.gz
```

### 8.2 A mano, si el script no está disponible

```bash
cd /var/www/gestisp && php artisan down
```

```bash
gunzip -c /ruta/gestisp-db-2026-08-10_023000-auto.sql.gz | mysql -u root -p gestisp_db
```

```bash
cd /var/www/gestisp && php artisan config:clear && php artisan cache:clear && php artisan up
```

### 8.3 Comprobar que salió bien

1. Inicie sesión en gestISP.
2. Abra el último contrato y el último pago: deben corresponder a la fecha de la
   copia.
3. Abra el listado de facturación y compruebe que carga sin errores.
4. Avise al personal de caja de **qué se ha perdido** (todo lo registrado entre la
   hora de la copia y el momento del fallo) para que lo vuelvan a capturar.

---

## 9. Recuperación completa del servidor

Para el día malo: el servidor no arranca, se lo llevaron, o se quemó.

**Tiempo estimado: entre 2 y 4 horas.**

### Paso 1 — Servidor nuevo

Instale Ubuntu Server con la misma versión que el anterior (está en
`inventario-del-servidor.txt`, dentro del paquete `gestisp-servidor-*.tar.gz`).

### Paso 2 — Bajar las copias de la NAS

```bash
scp -P 22 gestisp@192.168.1.50:/volume1/respaldos/gestisp/2026-08/gestisp-* /root/recuperacion/
```

Y verifique que llegaron íntegras:

```bash
cd /root/recuperacion && sha256sum -c gestisp-huellas-*.sha256
```

### Paso 3 — Programas base

```bash
sudo apt update && sudo apt install -y mysql-server php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-snmp composer unzip
```

Y el servidor web que tuviera, **el mismo de antes** — lo dice
`inventario-del-servidor.txt` y también se ve en el paquete de configuración
(si dentro hay `./etc/apache2/` era Apache; si hay `./etc/nginx/`, nginx):

```bash
sudo apt install -y apache2 libapache2-mod-php8.3    # si era Apache
```

```bash
sudo apt install -y nginx php8.3-fpm                 # si era nginx
```

> Ajuste la versión de PHP y la lista de extensiones a lo que diga
> `inventario-del-servidor.txt`. La extensión **snmp** es imprescindible: sin
> ella el sondeo de OLTs y ONTs no funciona.

### Paso 4 — Restaurar la configuración del servidor

```bash
sudo tar xzf gestisp-servidor-FECHA.tar.gz -C /
```

Compruebe que la configuración es válida y reinicie:

```bash
sudo apache2ctl configtest && sudo systemctl restart apache2       # Apache
```

```bash
sudo nginx -t && sudo systemctl restart nginx php8.3-fpm           # nginx
```

En Apache, recuerde habilitar los módulos y el sitio si no arrancan solos:

```bash
sudo a2enmod rewrite ssl && sudo a2ensite gestisp && sudo systemctl reload apache2
```

### Paso 5 — Restaurar el código

```bash
sudo mkdir -p /var/www/gestisp && sudo tar xzf gestisp-codigo-FECHA.tar.gz -C /var/www/gestisp
```

```bash
cd /var/www/gestisp && sudo -u www-data composer install --no-dev --optimize-autoloader
```

```bash
sudo chown -R www-data:www-data /var/www/gestisp/storage /var/www/gestisp/bootstrap/cache
```

### Paso 6 — Restaurar la base de datos

```bash
sudo mysql -e "CREATE DATABASE gestisp_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Cree el usuario con **la misma contraseña que tiene el `.env` restaurado** (así
no hay que tocar la configuración):

```bash
sudo mysql -e "CREATE USER 'gestisp'@'localhost' IDENTIFIED BY 'LA-DEL-ENV'; GRANT ALL ON gestisp_db.* TO 'gestisp'@'localhost'; FLUSH PRIVILEGES;"
```

```bash
gunzip -c gestisp-db-FECHA-auto.sql.gz | mysql -u gestisp -p gestisp_db
```

### Paso 7 — Levantar la aplicación

```bash
cd /var/www/gestisp && php artisan storage:link && php artisan config:cache && php artisan route:cache
```

### Paso 8 — Volver a poner en marcha lo que corre solo

```bash
sudo crontab -e
```

Vuelva a añadir la línea del programador de Laravel:

```
* * * * * cd /var/www/gestisp && php artisan schedule:run >> /dev/null 2>&1
```

Y reinstale las copias de seguridad, que es lo primero que se olvida:

```bash
cd /var/www/gestisp/deploy/backup && sudo ./instalar.sh
```

### Paso 9 — Comprobaciones finales

- [ ] Se puede iniciar sesión en gestISP.
- [ ] El certificado HTTPS es válido (si no, `sudo certbot --nginx`).
- [ ] El sondeo de ONTs actualiza potencias (espere 5 minutos y mire una OLT).
- [ ] La conexión con los routers Mikrotik responde.
- [ ] El correo saliente funciona (pruebe un envío de factura).
- [ ] **Las copias de seguridad vuelven a ejecutarse.**

---

## 10. Problemas frecuentes

### La pantalla dice que el respaldo automático está atrasado

```bash
sudo tail -50 /var/log/gestisp-backup.log
```

Causas, de la más común a la menos:

| Síntoma en el registro | Causa | Solución |
|---|---|---|
| `Permission denied (publickey)` | La clave SSH ya no la acepta la NAS | Repita el paso 4.4 |
| `No route to host` / `Connection timed out` | La NAS está apagada o cambió de IP | Compruebe `NAS_HOST` |
| `kex_exchange_identification: Connection closed` | Está hablando SSH contra un puerto que no es SSH (el 873 es rsyncd) | Use `NAS_TRANSPORTE="rsyncd"` o corrija `NAS_PORT` |
| `@ERROR: auth failed on module` | Usuario o contraseña del servidor rsync incorrectos | Revise `NAS_PASSWORD_FILE` (sin salto de línea final) |
| `@ERROR: Unknown module` | `NAS_MODULO` no coincide con lo que publica la NAS | Lístelos con `rsync --list-only rsync://usuario@ip:873/` |
| `mkdir failed: No such file or directory` (rsyncd) | La subcarpeta no existe en la NAS | Créela a mano: el demonio rsync no puede crear carpetas |
| `No space left on device` | Disco lleno | Baje `KEEP_LOCAL_DAYS` o amplíe el disco |
| `Access denied for user` | Cambió la contraseña de la base de datos | Actualice el `.env` |
| `mkdir(): Invalid path` | Falta `config/backup.php` o la configuración está cacheada de antes | `php artisan config:clear && php artisan config:cache` |
| El registro no tiene nada de hoy | El cron no se está ejecutando | `systemctl status cron` y revise `/etc/cron.d/gestisp-backup` |

### Edité la configuración y el script sigue usando los valores viejos

Comprobado que no editó `deploy/backup/gestisp-backup.conf.example`, que es solo
la plantilla. El archivo que el script lee es **`/etc/gestisp/backup.conf`**.
Además, lo que se escriba en el `.example` viaja dentro del repositorio y se
perderá en el siguiente despliegue.

### El botón de generar copia da un error de tiempo agotado

El volcado tarda más de lo que espera el servidor web.

En **nginx**, en `/etc/nginx/sites-available/gestisp`, dentro del bloque
`location ~ \.php$`:

```
fastcgi_read_timeout 600;
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

En **Apache con mod_php**, basta con subir el límite de PHP en
`/etc/php/8.3/apache2/php.ini`:

```
max_execution_time = 600
```

```bash
sudo systemctl reload apache2
```

### `mysqldump: command not found`

```bash
sudo apt install -y mysql-client
```

Si está instalado en otra ruta, indíquela en el `.env` de la aplicación:

```
BACKUP_MYSQLDUMP=/usr/local/mysql/bin/mysqldump
```

### Las copias ocupan demasiado

La causa casi siempre son las tablas de telemetría (`ont_metrics` y
`pppoe_session_metrics`), que se rellenan solas cada 5 minutos. Se pueden excluir
sus datos en `config/backup.php`, pero **asúmalo con los ojos abiertos**: al
restaurar, las gráficas de tráfico y potencia arrancan vacías y tardan un mes en
volver a estar completas. Todo lo demás se restaura igual.

### El archivo descargado no se abre

Un `.sql.gz` no se abre con doble clic en Windows. Use 7-Zip, o desde la línea
de comandos:

```bash
gzip -d gestisp-db-2026-08-10_143000-manual.sql.gz
```

---

## Anexo A — Referencia rápida

| Necesito... | Orden |
|---|---|
| Hacer una copia ahora | `sudo /usr/local/bin/gestisp-backup.sh` |
| Solo la base de datos, sin enviar | `sudo /usr/local/bin/gestisp-backup.sh --solo-bd --sin-envio` |
| Ver las copias del servidor | `sudo /usr/local/bin/gestisp-restore.sh --listar` |
| Restaurar | `sudo /usr/local/bin/gestisp-restore.sh <archivo>` |
| Ver el registro | `sudo tail -50 /var/log/gestisp-backup.log` |
| Comprobar que un archivo no está corrupto | `gzip -t <archivo>` |
| Ver qué hay en la NAS (SSH) | `ssh -i /root/.ssh/gestisp-nas <usuario>@<nas> "ls -lh <ruta>"` |
| Ver qué hay en la NAS (rsyncd) | `rsync --list-only --password-file=/etc/gestisp/rsyncd.pass rsync://<usuario>@<nas>:873/<modulo>/` |
| Ver qué recursos publica el servidor rsync | `rsync --list-only rsync://<usuario>@<nas>:873/` |

### Dónde está cada cosa

| Ruta | Qué es |
|---|---|
| `/etc/gestisp/backup.conf` | Configuración de las copias (**este** es el que se edita) |
| `/etc/gestisp/rsyncd.pass` | Contraseña del servidor rsync, si se usa ese modo |
| `/etc/cron.d/gestisp-backup` | Los dos horarios diarios |
| `/var/log/gestisp-backup.log` | Registro de las copias |
| `/var/backups/gestisp/` | Paquetes de código y configuración |
| `<proyecto>/storage/app/backups/` | Volcados de la base de datos |
| `<proyecto>/config/backup.php` | Opciones del volcado y retención local |
| `<proyecto>/deploy/backup/` | Los scripts, tal como se instalan |

---

## Anexo B — Registro de pruebas de restauración

Rellene una línea cada mes. Este registro es lo que permite decir, con
fundamento, que el sistema está respaldado.

| Fecha | Copia probada | Clientes | Contratos | Facturas | ¿Correcto? | Quién |
|---|---|---|---|---|---|---|
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |
| | | | | | | |

---

## Anexo C — Datos para el día malo

Rellene esta tabla **ahora**, no cuando haga falta, e imprímala.

| Dato | Valor |
|---|---|
| Dirección de la NAS | |
| Usuario de la NAS | |
| Ruta de las copias en la NAS | |
| Dónde está la clave SSH | |
| Proveedor del servidor / VPS | |
| Usuario del panel del proveedor | |
| Dominio y dónde se administra el DNS | |
| Responsable técnico (teléfono) | |
| Responsable alternativo (teléfono) | |
