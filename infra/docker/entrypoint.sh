#!/bin/sh
# =====================================================================
# entrypoint.sh — Prepara SOLO los directorios escribibles de GLPI.
# No modifica el código del core. Reconstruye la estructura de files/
# (necesaria cuando se monta un volumen vacío) y ajusta permisos.
# =====================================================================
set -e

GLPI_DIR=/var/www/glpi

# Subdirectorios de trabajo que GLPI espera bajo files/.
for d in _cache _cron _dumps _graphs _lock _log _pictures _plugins \
         _rss _sessions _tmp _uploads _inventories _locales; do
  mkdir -p "$GLPI_DIR/files/$d"
done

# Config y marketplace deben existir y ser escribibles por el servidor web.
mkdir -p "$GLPI_DIR/config" "$GLPI_DIR/marketplace"

# --- Correo DEV: generar /etc/msmtprc para enrutar el correo a MailHog ---
# Sin secretos (MailHog no requiere auth ni TLS). Valores desde el entorno.
: "${SMTP_HOST:=mailhog}"
: "${SMTP_PORT:=1025}"
: "${SMTP_FROM:=no-reply@glpi.local}"
cat > /etc/msmtprc <<EOF
# Generado por entrypoint.sh (DEV). Enruta el correo saliente a MailHog.
defaults
auth off
tls off
account mailhog
host ${SMTP_HOST}
port ${SMTP_PORT}
from ${SMTP_FROM}
account default : mailhog
EOF
chmod 644 /etc/msmtprc

# Ajuste de propietario (montajes de volumen entran como root).
chown -R www-data:www-data \
  "$GLPI_DIR/files" \
  "$GLPI_DIR/config" \
  "$GLPI_DIR/marketplace" \
  "$GLPI_DIR/plugins" 2>/dev/null || true

exec "$@"
