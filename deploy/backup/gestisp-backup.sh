#!/usr/bin/env bash
#
# ============================================================
#  Copia de seguridad de gestISP
# ============================================================
#
#  Hace, en este orden:
#
#    1. El volcado de la base de datos (llamando a `php artisan
#       backup:run`, que es quien conoce las credenciales).
#    2. El paquete del código de la aplicación.
#    3. El paquete de la configuración del servidor.
#    4. Una huella SHA-256 de cada archivo.
#    5. El envío de todo a la NAS por SSH.
#    6. La comprobación de que lo que llegó a la NAS es lo que salió.
#    7. La limpieza de lo antiguo aquí y allá.
#
#  Uso:
#      /usr/local/bin/gestisp-backup.sh
#      /usr/local/bin/gestisp-backup.sh --solo-bd     (sin código ni config)
#      /usr/local/bin/gestisp-backup.sh --sin-envio   (no toca la NAS)
#
#  Configuración: /etc/gestisp/backup.conf
#  Registro:      /var/log/gestisp-backup.log
#  Manual:        docs/Manual_Copias_Seguridad_GestISP.pdf
#
#  Devuelve 0 si TODO salió bien y 1 si algo falló. Cualquier fallo
#  detiene el script: una copia a medias que se da por buena es peor
#  que no tener copia, porque nadie va a revisarla hasta el día que
#  haga falta.
#
# ============================================================

set -Eeuo pipefail

# Idioma neutro para todo lo que se ejecute desde aquí. No es cosmético:
# en un servidor en español, rsync escribe los tamaños como
# "80.005.464" y la comprobación de que el archivo llegó completo
# comparaba ese texto contra el número a secas. La copia estaba bien y
# el script la daba por fallida.
export LC_ALL=C

CONFIG_FILE="${GESTISP_BACKUP_CONF:-/etc/gestisp/backup.conf}"
LOG_FILE="/var/log/gestisp-backup.log"
LOCK_FILE="/var/lock/gestisp-backup.lock"

SOLO_BD=0
SIN_ENVIO=0

for argumento in "$@"; do
    case "$argumento" in
        --solo-bd)   SOLO_BD=1 ;;
        --sin-envio) SIN_ENVIO=1 ;;
        -h|--help)
            sed -n '2,40p' "$0" | sed 's/^#//'
            exit 0
            ;;
        *)
            echo "Opción desconocida: $argumento" >&2
            exit 2
            ;;
    esac
done

# ------------------------------------------------------------
#  Registro
# ------------------------------------------------------------

log() {
    local linea
    linea="$(printf '%s  %s' "$(date '+%Y-%m-%d %H:%M:%S')" "$*")"

    printf '%s\n' "$linea" >> "$LOG_FILE"

    # Por pantalla solo cuando se ejecuta a mano. Desde cron no, o el
    # registro saldría duplicado en el log del cron y el correo de
    # cron llegaría lleno de ruido en cada ejecución correcta.
    if [[ -t 1 ]]; then
        printf '%s\n' "$linea"
    fi

    return 0
}

# Se dispara ante cualquier error gracias a `set -e` + trap ERR.
# Sin esto, un fallo a mitad dejaría el log terminando sin más y la
# copia parecería correcta.
al_fallar() {
    local codigo=$?
    local linea=${BASH_LINENO[0]}

    log "ERROR: la copia falló en la línea ${linea} (código ${codigo})."

    if [[ -n "${ALERT_EMAIL:-}" ]] && command -v mail >/dev/null 2>&1; then
        tail -n 40 "$LOG_FILE" | mail -s "[gestISP] FALLÓ la copia de seguridad en $(hostname)" "$ALERT_EMAIL" || true
    fi

    exit 1
}

trap al_fallar ERR

# ------------------------------------------------------------
#  Configuración
# ------------------------------------------------------------

if [[ ! -r "$CONFIG_FILE" ]]; then
    echo "No se encuentra la configuración en $CONFIG_FILE" >&2
    echo "Cópiela desde deploy/backup/gestisp-backup.conf.example" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${APP_DIR:?Falta APP_DIR en la configuración}"
: "${APP_USER:=www-data}"
: "${PHP_BIN:=/usr/bin/php}"
: "${WORK_DIR:=/var/backups/gestisp}"
: "${KEEP_LOCAL_DAYS:=7}"
: "${KEEP_NAS_DAYS:=30}"
: "${KEEP_NAS_MONTHLY_DAYS:=730}"

mkdir -p "$WORK_DIR" "$(dirname "$LOG_FILE")"
chmod 700 "$WORK_DIR"

# ------------------------------------------------------------
#  Un solo proceso a la vez
# ------------------------------------------------------------
#
#  Si la copia de las 02:30 todavía está subiendo cuando entra la de
#  las 14:30, la segunda se descarta en vez de pelearse con la primera
#  por el disco y por el ancho de banda.

exec 9>"$LOCK_FILE"

if ! flock -n 9; then
    log "AVISO: ya hay una copia en curso. Esta ejecución se descarta."
    exit 0
fi

# ------------------------------------------------------------

FECHA="$(date '+%Y-%m-%d_%H%M%S')"
DIA_DEL_MES="$(date '+%d')"
ARCHIVOS_A_ENVIAR=()

log "=================================================="
log "Inicio de la copia de seguridad ($(hostname))"

# ============================================================
#  1. Base de datos
# ============================================================
#
#  Lo hace la aplicación: es la que tiene las credenciales en su .env
#  y la que sabe comprimir y verificar el volcado. Aquí no se repiten.

log "[1/6] Volcando la base de datos..."

SALIDA_ARTISAN="$(sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" backup:run --origen=auto 2>&1)" || {
    log "$SALIDA_ARTISAN"
    log "ERROR: falló 'php artisan backup:run'."
    exit 1
}

log "$SALIDA_ARTISAN"

# La última línea que empieza por la ruta de storage es el archivo
# recién creado; el comando la imprime justo para esto.
VOLCADO="$(printf '%s\n' "$SALIDA_ARTISAN" | grep -E '\.sql\.gz$' | tail -n1 | tr -d '\r')"

if [[ -z "$VOLCADO" || ! -f "$VOLCADO" ]]; then
    log "ERROR: el volcado no aparece en el disco. Salida de artisan arriba."
    exit 1
fi

# Segunda comprobación, independiente de la que hace la aplicación:
# que el gzip se abre entero. Cuesta un segundo y es lo único que
# separa "tengo copias" de "tengo copias que sirven".
gzip -t "$VOLCADO" || {
    log "ERROR: el volcado $VOLCADO está corrupto (gzip -t falló)."
    exit 1
}

log "Volcado correcto: $(basename "$VOLCADO") ($(du -h "$VOLCADO" | cut -f1))"
ARCHIVOS_A_ENVIAR+=("$VOLCADO")

# ============================================================
#  2. Código de la aplicación
# ============================================================
#
#  Se excluye lo que se puede reconstruir con una orden (vendor y
#  node_modules salen de composer y npm) y lo que no aporta nada al
#  restaurar (logs, cachés, las propias copias). Lo que SÍ va, y es
#  lo importante: el .env, con la configuración real de producción,
#  y storage/app/public, donde están las firmas de los clientes y las
#  fotos de las órdenes.

if [[ "$SOLO_BD" -eq 0 ]]; then
    log "[2/6] Empaquetando el código de la aplicación..."

    CODIGO="$WORK_DIR/gestisp-codigo-${FECHA}.tar.gz"

    tar czf "$CODIGO" \
        --exclude='./vendor' \
        --exclude='./node_modules' \
        --exclude='./.git' \
        --exclude='./storage/app/backups' \
        --exclude='./storage/logs/*' \
        --exclude='./storage/framework/cache/*' \
        --exclude='./storage/framework/sessions/*' \
        --exclude='./storage/framework/views/*' \
        --exclude='./public/storage' \
        -C "$APP_DIR" .

    gzip -t "$CODIGO"

    # El paquete lleva dentro el .env con la contraseña de la base de
    # datos: solo root puede leerlo
    chmod 600 "$CODIGO"

    log "Código empaquetado: $(basename "$CODIGO") ($(du -h "$CODIGO" | cut -f1))"
    ARCHIVOS_A_ENVIAR+=("$CODIGO")
else
    log "[2/6] Omitido (--solo-bd)."
fi

# ============================================================
#  3. Configuración del servidor
# ============================================================
#
#  Es la parte que todo el mundo olvida y la que más tiempo cuesta
#  rehacer a mano: el virtual host de nginx, el certificado, los
#  límites de PHP, la línea del cron. Sin esto, restaurar significa
#  volver a configurar un servidor desde cero de memoria.

if [[ "$SOLO_BD" -eq 0 ]]; then
    log "[3/6] Empaquetando la configuración del servidor..."

    CONFIG_TMP="$(mktemp -d)"
    CONFIG="$WORK_DIR/gestisp-servidor-${FECHA}.tar.gz"

    for ruta in "${SYSTEM_CONFIG_PATHS[@]:-}"; do
        [[ -e "$ruta" ]] || continue
        mkdir -p "$CONFIG_TMP/$(dirname "$ruta")"
        cp -a "$ruta" "$CONFIG_TMP/$(dirname "$ruta")/"
    done

    # Un inventario de lo que hay instalado y con qué versiones. Al
    # reconstruir el servidor, esto ahorra el "¿qué versión de PHP
    # tenía?" que siempre aparece.
    {
        echo "# Inventario del servidor de gestISP"
        echo "# Generado: $(date '+%Y-%m-%d %H:%M:%S')"
        echo
        echo "## Sistema"
        (lsb_release -a 2>/dev/null || cat /etc/os-release) || true
        uname -a
        echo
        echo "## Versiones"
        "$PHP_BIN" -v 2>/dev/null | head -n1 || true
        (mysql --version 2>/dev/null) || true
        (nginx -v 2>&1) || true
        echo
        echo "## Extensiones de PHP"
        "$PHP_BIN" -m 2>/dev/null || true
        echo
        echo "## Paquetes instalados"
        (dpkg-query -W -f='${binary:Package} ${Version}\n' 2>/dev/null) || true
        echo
        echo "## Tareas programadas de root"
        (crontab -l 2>/dev/null) || echo "(ninguna)"
    } > "$CONFIG_TMP/inventario-del-servidor.txt"

    tar czf "$CONFIG" -C "$CONFIG_TMP" .
    gzip -t "$CONFIG"
    chmod 600 "$CONFIG"
    rm -rf "$CONFIG_TMP"

    log "Configuración empaquetada: $(basename "$CONFIG") ($(du -h "$CONFIG" | cut -f1))"
    ARCHIVOS_A_ENVIAR+=("$CONFIG")
else
    log "[3/6] Omitido (--solo-bd)."
fi

# ============================================================
#  4. Huellas SHA-256
# ============================================================
#
#  Permiten demostrar, meses después, que el archivo que se baja de la
#  NAS es byte a byte el que salió de este servidor. Un archivo grande
#  que se degrada en el disco de la NAS (o que alguien reemplaza) no
#  se nota de ninguna otra forma hasta que se intenta restaurar.

log "[4/6] Calculando huellas de verificación..."

HUELLAS="$WORK_DIR/gestisp-huellas-${FECHA}.sha256"
: > "$HUELLAS"

for archivo in "${ARCHIVOS_A_ENVIAR[@]}"; do
    (cd "$(dirname "$archivo")" && sha256sum "$(basename "$archivo")") >> "$HUELLAS"
done

ARCHIVOS_A_ENVIAR+=("$HUELLAS")
log "Huellas escritas en $(basename "$HUELLAS")"

# ============================================================
#  5. Envío a la NAS
# ============================================================

enviar_por_ssh() {
    : "${NAS_PATH:?Falta NAS_PATH}"
    : "${SSH_KEY:?Falta SSH_KEY}"
    : "${NAS_PORT:=22}"

    SSH_OPCIONES=(-i "$SSH_KEY" -p "$NAS_PORT" -o BatchMode=yes -o ConnectTimeout=20)
    DESTINO_DIA="${NAS_PATH}/$(date '+%Y-%m')"

    ssh "${SSH_OPCIONES[@]}" "${NAS_USER}@${NAS_HOST}" "mkdir -p '${DESTINO_DIA}'"

    for archivo in "${ARCHIVOS_A_ENVIAR[@]}"; do
        rsync -a --partial \
            -e "ssh ${SSH_OPCIONES[*]}" \
            "$archivo" "${NAS_USER}@${NAS_HOST}:${DESTINO_DIA}/"

        # Comprobación de que llegó completo. Se compara el tamaño
        # porque `wc -c` existe hasta en la NAS más pelada; rsync ya
        # verifica el contenido durante la transferencia.
        local_bytes="$(wc -c < "$archivo" | tr -cd '0-9')"
        remoto_bytes="$(ssh "${SSH_OPCIONES[@]}" "${NAS_USER}@${NAS_HOST}" "wc -c < '${DESTINO_DIA}/$(basename "$archivo")'" | tr -cd '0-9')"

        if [[ "$local_bytes" != "$remoto_bytes" ]]; then
            log "ERROR: $(basename "$archivo") llegó incompleto a la NAS (${remoto_bytes} de ${local_bytes} bytes)."
            exit 1
        fi

        log "Enviado y verificado: $(basename "$archivo")"
    done

    # ---- Copia mensual ----
    # El día 1 el volcado se guarda aparte y sobrevive a la rotación
    # de los 30 días.
    if [[ "$DIA_DEL_MES" == "01" ]]; then
        log "Guardando la copia mensual..."
        ssh "${SSH_OPCIONES[@]}" "${NAS_USER}@${NAS_HOST}" \
            "mkdir -p '${NAS_PATH}/mensuales' && cp '${DESTINO_DIA}/$(basename "$VOLCADO")' '${NAS_PATH}/mensuales/'"
    fi

    # ---- Limpieza en la NAS ----
    # -exec rm en vez de -delete: hay NAS con busybox donde -delete no
    # existe y el find falla en silencio.
    log "Aplicando la retención en la NAS (${KEEP_NAS_DAYS} días)..."
    ssh "${SSH_OPCIONES[@]}" "${NAS_USER}@${NAS_HOST}" "
        find '${NAS_PATH}' -path '${NAS_PATH}/mensuales' -prune -o \
             -type f -name 'gestisp-*' -mtime +${KEEP_NAS_DAYS} -exec rm -f {} + ;
        find '${NAS_PATH}/mensuales' -type f -name 'gestisp-*' -mtime +${KEEP_NAS_MONTHLY_DAYS} -exec rm -f {} + 2>/dev/null ;
        find '${NAS_PATH}' -type d -empty -delete 2>/dev/null ;
        true
    "
}

# ------------------------------------------------------------
#  Envío al demonio rsync (QNAP: «Servidor Rsync» de HBS 3)
# ------------------------------------------------------------
#
#  Aquí NO hay intérprete de órdenes al otro lado: el demonio rsync
#  solo sabe recibir archivos. Eso cambia tres cosas respecto al modo
#  SSH, y conviene tenerlas presentes:
#
#   1. No se pueden crear carpetas remotas. La carpeta de destino
#      (NAS_MODULO y, si se usa, NAS_SUBCARPETA) tiene que existir ya
#      en la NAS.
#   2. La rotación de lo antiguo no se puede hacer desde aquí. Hay que
#      configurarla EN LA NAS; si no, la carpeta crece sin límite
#      hasta llenar el disco.
#   3. La verificación se hace pidiendo el listado del archivo recién
#      subido (`--list-only`), que sí funciona sin intérprete.
#
enviar_por_rsyncd() {
    : "${NAS_MODULO:?Falta NAS_MODULO (el nombre del recurso publicado por el servidor rsync)}"
    : "${NAS_PASSWORD_FILE:=/etc/gestisp/rsyncd.pass}"
    : "${NAS_PORT:=873}"

    if [[ ! -r "$NAS_PASSWORD_FILE" ]]; then
        log "ERROR: no se puede leer el archivo de contraseña ${NAS_PASSWORD_FILE}."
        log "       Créelo con la contraseña del servidor rsync de la NAS:"
        log "       printf '%s' 'LA-CONTRASENA' > ${NAS_PASSWORD_FILE} && chmod 600 ${NAS_PASSWORD_FILE}"
        exit 1
    fi

    # rsync se niega a usar el archivo si otros pueden leerlo, y hace
    # bien: dentro está la contraseña de la NAS en claro
    chmod 600 "$NAS_PASSWORD_FILE" 2>/dev/null || true

    local destino="rsync://${NAS_USER}@${NAS_HOST}:${NAS_PORT}/${NAS_MODULO}"

    if [[ -n "${NAS_SUBCARPETA:-}" ]]; then
        destino="${destino}/${NAS_SUBCARPETA}"
    fi

    local opciones=(-a --partial --timeout=120 --contimeout=30 --password-file="$NAS_PASSWORD_FILE")

    for archivo in "${ARCHIVOS_A_ENVIAR[@]}"; do
        rsync "${opciones[@]}" "$archivo" "${destino}/"

        # El listado devuelve el tamaño con separadores de millar, y
        # cuáles son depende del idioma del sistema ("76,543,210" o
        # "76.543.210"). Se quita todo lo que no sea un dígito en vez
        # de intentar adivinar el separador.
        local_bytes="$(wc -c < "$archivo" | tr -cd '0-9')"
        remoto_bytes="$(rsync --list-only --password-file="$NAS_PASSWORD_FILE" \
            "${destino}/$(basename "$archivo")" 2>/dev/null | awk 'NR==1 {print $2}' | tr -cd '0-9')"

        if [[ -z "$remoto_bytes" ]]; then
            log "ERROR: $(basename "$archivo") no aparece en la NAS después de enviarlo."
            exit 1
        fi

        if [[ "$local_bytes" != "$remoto_bytes" ]]; then
            log "ERROR: $(basename "$archivo") llegó incompleto a la NAS (${remoto_bytes} de ${local_bytes} bytes)."
            exit 1
        fi

        log "Enviado y verificado: $(basename "$archivo")"
    done

    # ---- Copia mensual ----
    # Sin intérprete remoto no se puede copiar allí de un sitio a
    # otro, así que el día 1 el volcado se sube dos veces. Cuesta unos
    # megas al mes y evita depender de la NAS para conservarla.
    if [[ "$DIA_DEL_MES" == "01" ]]; then
        log "Guardando la copia mensual..."

        if ! rsync "${opciones[@]}" "$VOLCADO" "${destino}/mensuales/"; then
            log "AVISO: no se pudo guardar la copia mensual."
            log "       Cree la carpeta 'mensuales' dentro de ${NAS_MODULO}${NAS_SUBCARPETA:+/$NAS_SUBCARPETA} en la NAS."
        fi
    fi

    log "NOTA: con el servidor rsync la retención se configura EN LA NAS."
    log "      Revise que hay una tarea que borre lo anterior a ${KEEP_NAS_DAYS} días."
}

if [[ "$SIN_ENVIO" -eq 1 ]]; then
    log "[5/6] Omitido (--sin-envio)."
else
    : "${NAS_USER:?Falta NAS_USER}"
    : "${NAS_HOST:?Falta NAS_HOST}"
    : "${NAS_TRANSPORTE:=ssh}"

    log "[5/6] Enviando a la NAS ${NAS_HOST} (transporte: ${NAS_TRANSPORTE})..."

    case "$NAS_TRANSPORTE" in
        ssh)    enviar_por_ssh ;;
        rsyncd) enviar_por_rsyncd ;;
        *)
            log "ERROR: NAS_TRANSPORTE debe ser 'ssh' o 'rsyncd', no '${NAS_TRANSPORTE}'."
            exit 1
            ;;
    esac
fi

# ============================================================
#  6. Limpieza local
# ============================================================
#
#  El volcado lo purga la propia aplicación (config/backup.php); aquí
#  solo se limpian los paquetes de código y configuración.

log "[6/6] Limpiando copias locales de más de ${KEEP_LOCAL_DAYS} días..."

find "$WORK_DIR" -maxdepth 1 -type f -name 'gestisp-*' -mtime "+${KEEP_LOCAL_DAYS}" -exec rm -f {} + || true

LIBRE="$(df -h "$WORK_DIR" | awk 'NR==2 {print $4}')"
log "Espacio libre en el servidor: ${LIBRE}"

# ------------------------------------------------------------
#  Señal de vida al vigilante externo
# ------------------------------------------------------------
#
#  Se envía SOLO si todo lo anterior salió bien. Es lo que convierte
#  "creo que las copias se están haciendo" en "sé que se están
#  haciendo": si el servidor se apaga, la señal deja de llegar y el
#  vigilante avisa.

if [[ -n "${PING_URL:-}" ]] && command -v curl >/dev/null 2>&1; then
    curl -fsS -m 20 "$PING_URL" >/dev/null 2>&1 || log "AVISO: no se pudo avisar al vigilante externo."
fi

log "Copia de seguridad COMPLETADA correctamente."
log "=================================================="

exit 0
