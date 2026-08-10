#!/usr/bin/env bash
#
# ============================================================
#  Instalación del sistema de copias de seguridad de gestISP
# ============================================================
#
#  Deja el servidor listo para hacer dos copias diarias. Se ejecuta
#  UNA vez, como root, desde la carpeta deploy/backup del proyecto:
#
#      sudo ./instalar.sh
#
#  Es idempotente: se puede volver a lanzar sin miedo tras una
#  actualización del proyecto. Lo único que NO sobrescribe es
#  /etc/gestisp/backup.conf, para no borrar la configuración real.
#
# ============================================================

set -Eeuo pipefail

ORIGEN="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$EUID" -ne 0 ]]; then
    echo "Ejecute este instalador con sudo." >&2
    exit 1
fi

echo "==> Instalando los scripts en /usr/local/bin..."
install -m 750 -o root -g root "$ORIGEN/gestisp-backup.sh"  /usr/local/bin/gestisp-backup.sh
install -m 750 -o root -g root "$ORIGEN/gestisp-restore.sh" /usr/local/bin/gestisp-restore.sh

echo "==> Preparando /etc/gestisp..."
mkdir -p /etc/gestisp
chmod 750 /etc/gestisp

if [[ -f /etc/gestisp/backup.conf ]]; then
    echo "    Ya existe /etc/gestisp/backup.conf: se respeta."
else
    install -m 600 -o root -g root "$ORIGEN/gestisp-backup.conf.example" /etc/gestisp/backup.conf
    echo "    Creado /etc/gestisp/backup.conf a partir del ejemplo."
    echo "    ⚠ EDÍTELO ANTES DE SEGUIR: la NAS y las rutas son de ejemplo."
fi

echo "==> Creando la carpeta de trabajo..."
mkdir -p /var/backups/gestisp
chmod 700 /var/backups/gestisp

echo "==> Programando las dos copias diarias..."

# Se respeta igual que backup.conf: las horas suelen ajustarse a la
# zona horaria real del servidor (muchos están en UTC), y volver a
# pisarlas en cada actualización devolvería las copias a una hora que
# nadie eligió, sin avisar.
if [[ -f /etc/cron.d/gestisp-backup ]]; then
    echo "    Ya existe /etc/cron.d/gestisp-backup: se respeta."
    echo "    (si quiere los horarios de fábrica: cp $ORIGEN/gestisp-backup.cron /etc/cron.d/gestisp-backup)"
else
    install -m 644 -o root -g root "$ORIGEN/gestisp-backup.cron" /etc/cron.d/gestisp-backup
fi

echo "==> Configurando la rotación del registro..."
cat > /etc/logrotate.d/gestisp-backup <<'EOF'
# El registro de las copias crece unos pocos KB al día. Se conserva un
# año: es el histórico que permite responder "¿desde cuándo falla?"
/var/log/gestisp-backup.log /var/log/gestisp-backup-cron.log {
    monthly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    create 640 root adm
}
EOF

echo
echo "=================================================="
echo " Instalación terminada"
echo "=================================================="
echo
echo " Faltan tres pasos que no se pueden automatizar:"
echo
echo " 1. Editar la configuración:"
echo "      sudo nano /etc/gestisp/backup.conf"
echo
echo " 2. Crear la clave SSH y autorizarla en la NAS:"
echo "      sudo ssh-keygen -t ed25519 -f /root/.ssh/gestisp-nas -N \"\""
echo "      sudo ssh-copy-id -i /root/.ssh/gestisp-nas.pub -p <puerto> <usuario>@<nas>"
echo
echo " 3. Probar la copia entera a mano y leer el resultado:"
echo "      sudo /usr/local/bin/gestisp-backup.sh"
echo
echo " El procedimiento completo está en Manual_Copias_Seguridad_GestISP.pdf"
echo
