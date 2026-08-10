#!/usr/bin/env bash
#
# ============================================================
#  Restauración de gestISP
# ============================================================
#
#  Devuelve la base de datos al estado de una copia concreta.
#
#  Uso:
#      gestisp-restore.sh --listar
#      gestisp-restore.sh /var/backups/.../gestisp-db-2026-08-10_023000-auto.sql.gz
#      gestisp-restore.sh --recrear <archivo>    (borra y recrea el esquema)
#
#  Antes de tocar nada hace SIEMPRE una copia de seguridad de lo que
#  hay ahora mismo. Restaurar es una operación que se hace con prisa y
#  con el jefe mirando; el error habitual no es restaurar la copia
#  equivocada, es descubrir después que la copia equivocada ya se
#  llevó por delante lo que había.
#
#  NO restaura el código ni la configuración del servidor: eso se hace
#  a mano, descomprimiendo los paquetes gestisp-codigo-*.tar.gz y
#  gestisp-servidor-*.tar.gz. El procedimiento completo está en
#  docs/Manual_Copias_Seguridad_GestISP.pdf.
#
# ============================================================

set -Eeuo pipefail

CONFIG_FILE="${GESTISP_BACKUP_CONF:-/etc/gestisp/backup.conf}"
RECREAR=0
ARCHIVO=""

# ------------------------------------------------------------

if [[ ! -r "$CONFIG_FILE" ]]; then
    echo "No se encuentra la configuración en $CONFIG_FILE" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${APP_DIR:?Falta APP_DIR en la configuración}"
: "${APP_USER:=www-data}"
: "${PHP_BIN:=/usr/bin/php}"

BACKUP_DIR="${APP_DIR}/storage/app/backups"

# ------------------------------------------------------------
#  Lee un valor del .env de la aplicación.
#
#  Se leen de ahí y no de este script para que exista UNA sola copia
#  de la contraseña de la base de datos en el servidor.
# ------------------------------------------------------------

env_valor() {
    local clave="$1" linea
    linea="$(grep -E "^[[:space:]]*${clave}=" "${APP_DIR}/.env" | tail -n1 || true)"
    linea="${linea#*=}"
    linea="${linea%\"}"; linea="${linea#\"}"
    linea="${linea%\'}"; linea="${linea#\'}"
    printf '%s' "$linea"
}

# ------------------------------------------------------------
#  Argumentos
# ------------------------------------------------------------

for argumento in "$@"; do
    case "$argumento" in
        --listar)
            echo "Copias disponibles en ${BACKUP_DIR}:"
            echo
            ls -lht "$BACKUP_DIR"/gestisp-db-*.sql.gz 2>/dev/null || echo "  (ninguna)"
            echo
            echo "Para traer una copia desde la NAS:"
            echo "  scp -i ${SSH_KEY:-<clave>} -P ${NAS_PORT:-22} ${NAS_USER:-usuario}@${NAS_HOST:-nas}:${NAS_PATH:-/ruta}/AAAA-MM/gestisp-db-*.sql.gz ${BACKUP_DIR}/"
            exit 0
            ;;
        --recrear) RECREAR=1 ;;
        -h|--help)
            sed -n '2,26p' "$0" | sed 's/^#//'
            exit 0
            ;;
        *) ARCHIVO="$argumento" ;;
    esac
done

if [[ -z "$ARCHIVO" ]]; then
    echo "Indique la copia que quiere restaurar (o --listar para verlas)." >&2
    exit 2
fi

if [[ ! -f "$ARCHIVO" ]]; then
    echo "No existe el archivo: $ARCHIVO" >&2
    exit 1
fi

# ============================================================
#  1. Verificar la copia ANTES de tocar la base de datos
# ============================================================

echo "==> Verificando la copia..."

gzip -t "$ARCHIVO" || {
    echo "La copia está corrupta (gzip -t falló). NO se ha tocado nada." >&2
    exit 1
}

# Si la copia vino acompañada de su archivo de huellas, se comprueba
HUELLAS="$(dirname "$ARCHIVO")/$(basename "$ARCHIVO" | sed -E 's/^gestisp-db-(.*)-(auto|manual)\.sql\.gz$/gestisp-huellas-\1.sha256/')"

if [[ -f "$HUELLAS" ]]; then
    if (cd "$(dirname "$ARCHIVO")" && sha256sum -c --ignore-missing "$HUELLAS" >/dev/null 2>&1); then
        echo "    Huella SHA-256 correcta."
    else
        echo "La huella SHA-256 NO coincide: el archivo no es el que se generó." >&2
        echo "NO se ha tocado nada." >&2
        exit 1
    fi
fi

# El final del volcado tiene que estar ahí: una copia truncada se abre
# sin error hasta que se acaba a mitad de una tabla
if ! gunzip -c "$ARCHIVO" | tail -n 5 | grep -q "Dump completed"; then
    echo "La copia no tiene la marca de cierre de mysqldump: está incompleta." >&2
    echo "NO se ha tocado nada." >&2
    exit 1
fi

echo "    La copia es válida y está completa."

# ============================================================
#  2. Confirmación explícita
# ============================================================

DB_NOMBRE="$(env_valor DB_DATABASE)"
DB_USUARIO="$(env_valor DB_USERNAME)"
DB_CLAVE="$(env_valor DB_PASSWORD)"
DB_HOST="$(env_valor DB_HOST)"
DB_PUERTO="$(env_valor DB_PORT)"
: "${DB_HOST:=127.0.0.1}"
: "${DB_PUERTO:=3306}"

echo
echo "  Copia a restaurar : $(basename "$ARCHIVO")"
echo "  Fecha de la copia : $(basename "$ARCHIVO" | sed -E 's/^gestisp-db-([0-9-]+)_([0-9]{2})([0-9]{2})[0-9]{2}.*/\1 a las \2:\3/')"
echo "  Base de datos     : ${DB_NOMBRE} en ${DB_HOST}"
echo "  Recrear esquema   : $([[ "$RECREAR" -eq 1 ]] && echo 'SÍ (se borran las tablas actuales)' || echo 'no')"
echo
echo "  Todo lo que haya en ${DB_NOMBRE} después de la fecha de la copia"
echo "  SE PERDERÁ: pagos registrados, órdenes cerradas, clientes nuevos."
echo
read -r -p "Escriba el nombre de la base de datos para continuar: " CONFIRMACION

if [[ "$CONFIRMACION" != "$DB_NOMBRE" ]]; then
    echo "Cancelado. No se ha tocado nada."
    exit 0
fi

# ------------------------------------------------------------
#  Credenciales en un archivo temporal, nunca en la línea de
#  comandos: con `-pclave` cualquiera vería la contraseña con un
#  `ps aux` mientras dura la restauración.
# ------------------------------------------------------------

CNF="$(mktemp)"
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT

cat > "$CNF" <<EOF
[client]
host="${DB_HOST}"
port=${DB_PUERTO}
user="${DB_USUARIO}"
password="${DB_CLAVE}"
EOF

# ============================================================
#  3. Red de seguridad: copia de lo que hay AHORA
# ============================================================

echo
echo "==> Guardando el estado actual antes de sobrescribirlo..."

RED_DE_SEGURIDAD="${BACKUP_DIR}/gestisp-db-$(date '+%Y-%m-%d_%H%M%S')-manual.sql.gz"
mkdir -p "$BACKUP_DIR"

mysqldump --defaults-extra-file="$CNF" \
    --single-transaction --quick --routines --triggers \
    --no-tablespaces --hex-blob --default-character-set=utf8mb4 \
    "$DB_NOMBRE" | gzip -6 > "$RED_DE_SEGURIDAD"

gzip -t "$RED_DE_SEGURIDAD"
chown "$APP_USER":"$APP_USER" "$RED_DE_SEGURIDAD" 2>/dev/null || true

echo "    Estado actual guardado en:"
echo "    $RED_DE_SEGURIDAD"

# ============================================================
#  4. Restaurar
# ============================================================

echo
echo "==> Poniendo la aplicación en mantenimiento..."
sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" down --render="errors::503" >/dev/null 2>&1 || true

# Pase lo que pase a partir de aquí, la aplicación tiene que volver a
# levantarse: dejarla en mantenimiento sin avisar es peor que el
# problema original.
levantar_aplicacion() {
    sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" up >/dev/null 2>&1 || true
    rm -f "$CNF"
}
trap levantar_aplicacion EXIT

if [[ "$RECREAR" -eq 1 ]]; then
    echo "==> Recreando el esquema ${DB_NOMBRE}..."
    mysql --defaults-extra-file="$CNF" -e \
        "DROP DATABASE IF EXISTS \`${DB_NOMBRE}\`; CREATE DATABASE \`${DB_NOMBRE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

echo "==> Restaurando la copia (puede tardar varios minutos)..."
gunzip -c "$ARCHIVO" | mysql --defaults-extra-file="$CNF" "$DB_NOMBRE"

# ============================================================
#  5. Dejar la aplicación coherente
# ============================================================

echo "==> Limpiando cachés de la aplicación..."
for orden in config:clear cache:clear route:clear view:clear; do
    sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" "$orden" >/dev/null 2>&1 || true
done

# Las sesiones abiertas apuntan a usuarios y sucursales del estado
# anterior: se cierran todas para que nadie siga navegando con datos
# que ya no existen.
find "${APP_DIR}/storage/framework/sessions" -type f -name 'sess_*' -delete 2>/dev/null || true

echo "==> Levantando la aplicación..."
sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/artisan" up >/dev/null 2>&1 || true
trap 'rm -f "$CNF"' EXIT

echo
echo "=================================================="
echo " Restauración COMPLETADA"
echo "=================================================="
echo
echo " Compruebe ahora, en este orden:"
echo "   1. Que puede iniciar sesión en gestISP."
echo "   2. Que el último contrato y el último pago son los esperados"
echo "      para la fecha de la copia."
echo "   3. Que el listado de facturación abre sin errores."
echo
echo " Si algo no cuadra, el estado anterior a esta restauración está en:"
echo "   $RED_DE_SEGURIDAD"
echo
