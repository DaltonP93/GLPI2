#!/usr/bin/env bash
# =====================================================================
# verify-mail.sh — Verificación FAIL-CLOSED del transporte de correo DEV.
# Comprueba que el correo saliente de GLPI (msmtp -> MailHog) llega a
# MailHog. Prueba la MISMA vía que usa GLPI (sendmail_path = msmtp).
#
# Alcance: valida el TRANSPORTE (contenedor GLPI -> MailHog). Habilitar
# los "seguimientos por correo" dentro de GLPI es un paso administrativo
# documentado en docs/operations/email-dev.md.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$(cd "$HERE/../../infra/docker" && pwd)"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
ENV_FILE="$DOCKER_DIR/.env"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }
MAILHOG_API="${MAILHOG_API:-http://localhost:${MAILHOG_UI_PORT:-8025}}"

count() {
  curl -fsS "$MAILHOG_API/api/v2/messages?limit=1" 2>/dev/null \
    | grep -o '"total":[0-9]*' | head -n1 | cut -d: -f2
}

echo ">> MailHog alcanzable en $MAILHOG_API ?"
before="$(count || true)"; before="${before:-0}"
echo "   mensajes antes: $before"

echo ">> Enviando correo de prueba por la vía de GLPI (msmtp -> MailHog)..."
$COMPOSE exec -T glpi sh -c \
  'printf "From: no-reply@glpi.local\nTo: it@glpi.local\nSubject: GLPI DEV mail test\n\nPrueba de transporte a MailHog.\n" | msmtp -t'

# Dar un margen a MailHog para registrar el mensaje.
sleep 2
after="$(count || true)"; after="${after:-0}"
echo "   mensajes después: $after"

if [ "$after" -gt "$before" ]; then
  echo "verify-mail: OK (MailHog capturó el correo enviado por la vía de GLPI)."
else
  echo "verify-mail: FALLO (MailHog no recibió el correo). Revisar msmtp/MailHog."
  exit 1
fi
