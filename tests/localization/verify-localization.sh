#!/usr/bin/env bash
# =====================================================================
# verify-localization.sh — Verificación FAIL-CLOSED de la localización.
# Comprueba, contra la instancia en ejecución, que:
#   1. El idioma por defecto es Español (página de login anónima en es).
#   2. La zona horaria del servidor (PHP) es America/Asuncion.
#   3. El soporte de timezones está habilitado en la base de datos.
# Sale con código != 0 si algo no cumple.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$(cd "$HERE/../../infra/docker" && pwd)"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
ENV_FILE="$DOCKER_DIR/.env"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }
BASE_URL="${SMOKE_BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"

fail=0

echo ">> [1] Idioma por defecto = Español (página de login)"
html="$(curl -fsSL "$BASE_URL/" 2>/dev/null || true)"
if printf '%s' "$html" | grep -Eiq 'lang="es'; then
  echo "   OK: la interfaz anónima se sirve en español."
else
  echo "   FALLO: no se detectó 'lang=\"es\"' en la página (idioma por defecto != ES)."
  fail=1
fi

echo ">> [2] Zona horaria del servidor (PHP) = America/Asuncion"
tz="$($COMPOSE exec -T glpi php -r 'echo date_default_timezone_get();' 2>/dev/null | tr -d '\r\n')"
if [ "$tz" = "America/Asuncion" ]; then
  echo "   OK: date.timezone = $tz"
else
  echo "   FALLO: date.timezone = '$tz' (esperado America/Asuncion)."
  fail=1
fi

echo ">> [3] Soporte de timezones habilitado en la base de datos"
if $COMPOSE exec -T glpi php bin/console database:enable_timezones --no-interaction >/dev/null 2>&1; then
  echo "   OK: database:enable_timezones satisfecho (idempotente)."
else
  echo "   FALLO: database:enable_timezones no está satisfecho (faltan requisitos)."
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo "verify-localization: FALLÓ"
  exit 1
fi
echo "verify-localization: OK"
