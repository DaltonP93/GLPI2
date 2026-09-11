#!/usr/bin/env bash
# =====================================================================
# companyqr-http.sh — E2E HTTP REAL contra el stack GLPI.
# Ejercita el flujo completo por HTTP (no sólo servicios):
#   login → GET /scan/{token} → (verifica URL absoluta del form) →
#   POST report → ticket → Item_Ticket.
# Fail-closed: cualquier paso que falle aborta con exit≠0.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$HERE/../../infra/docker"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
BASE="${SMOKE_BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"
GLPI_USER="${GLPI_E2E_USER:-glpi}"
GLPI_PASS="${GLPI_E2E_PASS:-glpi}"
JAR="$(mktemp)"
TMP="$(mktemp -d)"

fail() { echo "E2E FAIL: $*" >&2; exit 1; }
csrf_of() { grep -oE 'name="_glpi_csrf_token"[^>]*value="[^"]+"' "$1" | head -1 | sed -E 's/.*value="([^"]+)".*/\1/'; }

echo ">> [1] Preparar fixture (activo + código) y emitir token"
FIX="$($COMPOSE exec -T -u www-data glpi php bin/console plugins:companyqr:emit-fixture)"
TOKEN="$(printf '%s\n' "$FIX" | sed -n 's/^token=//p' | tr -d '\r')"
ITEMS_ID="$(printf '%s\n' "$FIX" | sed -n 's/^items_id=//p' | tr -d '\r')"
[ -n "$TOKEN" ] || fail "no se obtuvo token del fixture"
[ -n "$ITEMS_ID" ] || fail "no se obtuvo items_id del fixture"
echo "   token=$TOKEN items_id=$ITEMS_ID"

echo ">> [2] Página de login (cookies + CSRF)"
curl -sS -c "$JAR" "$BASE/index.php" -o "$TMP/login.html" || fail "GET login"
LOGIN_CSRF="$(csrf_of "$TMP/login.html")"
[ -n "$LOGIN_CSRF" ] || fail "no se encontró CSRF en la página de login"

echo ">> [3] Login como '$GLPI_USER'"
lcode="$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/after_login.html" -w '%{http_code}' \
  -X POST "$BASE/front/login.php" \
  --data-urlencode "login_name=$GLPI_USER" \
  --data-urlencode "login_password=$GLPI_PASS" \
  --data-urlencode "_glpi_csrf_token=$LOGIN_CSRF")"
echo "   login http=$lcode"
ccode="$(curl -sS -b "$JAR" -o "$TMP/central.html" -w '%{http_code}' "$BASE/front/central.php")"
[ "$ccode" = "200" ] || fail "no autenticado (central.php=$ccode)"

echo ">> [4] GET ficha autenticada /plugins/companyqr/scan/{token}"
scode="$(curl -sS -b "$JAR" -o "$TMP/fiche.html" -w '%{http_code}' "$BASE/plugins/companyqr/scan/$TOKEN")"
[ "$scode" = "200" ] || fail "scan no devolvió 200 (=$scode)"

echo ">> [4b] La acción del formulario debe ser la ruta ABSOLUTA correcta"
grep -qE "action=\"[^\"]*/plugins/companyqr/scan/$TOKEN/report\"" "$TMP/fiche.html" \
  || fail "acción del formulario incorrecta/ausente (¿bug de URL relativa?)"
if grep -q "/scan/scan/" "$TMP/fiche.html"; then
  fail "acción del formulario duplicada (/scan/scan/) — bug de URL relativa"
fi
FICHE_CSRF="$(csrf_of "$TMP/fiche.html")"
[ -n "$FICHE_CSRF" ] || fail "no se encontró CSRF en la ficha"

echo ">> [5] POST reportar problema"
rcode="$(curl -sS -b "$JAR" -o "$TMP/report.html" -w '%{http_code}' \
  -X POST "$BASE/plugins/companyqr/scan/$TOKEN/report" \
  --data-urlencode "content=Reporte E2E automatizado" \
  --data-urlencode "_glpi_csrf_token=$FICHE_CSRF")"
echo "   report http=$rcode"
case "$rcode" in
  200|302|303) : ;;
  *) fail "el POST de reporte devolvió $rcode" ;;
esac

echo ">> [6] Verificar ticket vinculado al activo (Item_Ticket)"
$COMPOSE exec -T -u www-data glpi php bin/console plugins:companyqr:verify-report --items-id="$ITEMS_ID" \
  || fail "no hay ticket vinculado al activo tras el reporte"

echo "E2E OK: login → scan → report → ticket vinculado (flujo HTTP real)."
