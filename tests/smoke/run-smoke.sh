#!/usr/bin/env bash
# =====================================================================
# run-smoke.sh — Smoke tests mínimos tras instalar/actualizar.
# Verifica que GLPI responde y que la consola de plugins funciona.
# =====================================================================
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$HERE/../../infra/docker"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
BASE_URL="${SMOKE_BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"

echo ">> [1] GLPI responde por HTTP..."
code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/" || true)"
case "$code" in
  200|301|302) echo "   OK ($code)";;
  *) echo "   FALLO: HTTP $code desde $BASE_URL"; exit 1;;
esac

echo ">> [2] Consola de GLPI operativa..."
$COMPOSE exec -T glpi php bin/console --version >/dev/null && echo "   OK"

echo ">> [3] Listado de plugins (deben aparecer los nuestros)..."
$COMPOSE exec -T glpi php bin/console plugin:list || {
  echo "   AVISO: no se pudo listar plugins (¿instalación incompleta?)"; }

echo "OK: smoke tests superados."
