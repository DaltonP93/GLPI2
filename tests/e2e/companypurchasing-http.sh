#!/usr/bin/env bash
# =====================================================================
# companypurchasing-http.sh — E2E HTTP REAL de la UI de Compras (P2D-4, ADR-0023 §8).
# Verifica por HTTP (no sólo servicios) las reglas de la superficie web:
#   - páginas GET autenticadas (sin sesión ⇒ no se sirven);
#   - acciones sólo POST (GET sobre una ruta de acción ⇒ 405, el controlador no corre);
#   - CSRF nativo obligatorio (sin token / token inválido / token reutilizado ⇒ 403);
#   - PRG: POST válido ⇒ 302 a una página GET;
#   - salida escapada (un texto con <script> se muestra escapado);
#   - ACL del lado servidor: un usuario sin derechos de Compras recibe 403 en las páginas y su POST
#     (con CSRF válido) NO crea nada;
#   - sin secretos en el HTML (lease_token, hashes internos).
# Fail-closed: cualquier paso que falle aborta con exit≠0.
# =====================================================================
set -euo pipefail

BASE="${SMOKE_BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"
P="$BASE/plugins/companypurchasing"
ADMIN_USER="${GLPI_E2E_USER:-glpi}"
ADMIN_PASS="${GLPI_E2E_PASS:-glpi}"
# Usuario por defecto de GLPI con perfil Self-Service (sin derechos de Compras).
LOW_USER="${GLPI_E2E_LOW_USER:-post-only}"
LOW_PASS="${GLPI_E2E_LOW_PASS:-postonly}"
TMP="$(mktemp -d)"
JAR_A="$TMP/admin.jar"
JAR_L="$TMP/low.jar"
MARK="e2e-$(date +%s)-$$"

fail() { echo "E2E FAIL: $*" >&2; exit 1; }
csrf_of() { grep -oE 'name="_glpi_csrf_token"[^>]*value="[^"]+"' "$1" | head -1 | sed -E 's/.*value="([^"]+)".*/\1/'; }
meta_csrf_of() { grep -oE '<meta[^>]+glpi:csrf_token[^>]+>' "$1" | head -1 | sed -E 's/.*content="([^"]+)".*/\1/'; }
code() { # $1 jar, resto: args de curl ⇒ imprime el código HTTP (sin seguir redirecciones)
  local jar="$1"; shift
  curl -sS -b "$jar" -c "$jar" -o "$TMP/body.html" -D "$TMP/headers.txt" -w '%{http_code}' "$@"
}
expect() { # $1 esperado (regex), $2 obtenido, $3 descripción
  [[ "$2" =~ ^($1)$ ]] || fail "$3: HTTP $2 (esperado $1)"
  echo "   ok: $3 (HTTP $2)"
}
login() { # $1 jar, $2 usuario, $3 clave
  curl -sS -c "$1" "$BASE/index.php" -o "$TMP/login.html" || fail "GET login"
  local t; t="$(csrf_of "$TMP/login.html")"
  [ -n "$t" ] || fail "sin CSRF en la página de login"
  curl -sS -b "$1" -c "$1" -o /dev/null -X POST "$BASE/front/login.php" \
    --data-urlencode "login_name=$2" --data-urlencode "login_password=$3" --data-urlencode "_glpi_csrf_token=$t"
  # preference.php: 200 para CUALQUIER usuario autenticado (central.php es 403 para Self-Service).
  local c; c="$(curl -sS -b "$1" -o /dev/null -w '%{http_code}' "$BASE/front/preference.php")"
  [ "$c" = "200" ] || fail "login de '$2' falló (preference.php=$c)"
}
request_ids() { grep -oE '/plugins/companypurchasing/request/[0-9]+"' "$1" | grep -oE '[0-9]+' | sort -u || true; }

echo ">> [0] Sin sesión: la UI no se sirve a anónimos"
c="$(code "$TMP/anon.jar" "$P/requests")"
[ "$c" != "200" ] || fail "página servida sin sesión (HTTP 200)"
echo "   ok: sin sesión ⇒ HTTP $c"

echo ">> [1] Login administrador ('$ADMIN_USER')"
login "$JAR_A" "$ADMIN_USER" "$ADMIN_PASS"

echo ">> [2] Páginas GET (layout nativo) responden 200"
for path in requests inbox/approvals request/new metrics config; do
  expect '200' "$(code "$JAR_A" "$P/$path")" "GET /$path"
  grep -q 'lease_token\|payload_sha256\|input_sha256' "$TMP/body.html" && fail "/$path expone datos internos"
done
count_cat() { # $1 categoría exacta ⇒ cantidad de solicitudes listadas por el admin (filtro del listado)
  expect '200' "$(code "$JAR_A" -G "$P/requests" --data-urlencode "scope=entity" --data-urlencode "category=$1")" "GET /requests?category=$1" >&2
  request_ids "$TMP/body.html" | wc -l
}
[ "$(count_cat "$MARK")" -eq 0 ] || fail "fixture: ya existe una solicitud con la marca $MARK"

echo ">> [3] Acciones sólo POST: GET sobre una ruta de acción ⇒ 405 (no muta)"
for path in request/create request/1/submit request/1/decide request/1/deliver request/1/close request/1/receive config/save; do
  expect '405' "$(code "$JAR_A" "$P/$path")" "GET /$path"
done

echo ">> [4] CSRF nativo obligatorio en POST"
expect '403' "$(code "$JAR_A" -X POST "$P/request/create" --data-urlencode "category=$MARK-nocsrf")" "POST sin token"
expect '403' "$(code "$JAR_A" -X POST "$P/request/create" --data-urlencode "category=$MARK-badcsrf" \
  --data-urlencode "_glpi_csrf_token=0000000000000000000000000000000000000000000000000000000000000000")" "POST con token inválido"

echo ">> [5] POST válido ⇒ PRG (302 → GET) y salida escapada"
expect '200' "$(code "$JAR_A" "$P/request/new")" "GET /request/new"
T="$(csrf_of "$TMP/body.html")"
[ -n "$T" ] || fail "el formulario de nueva solicitud no trae CSRF"
XSS="<script>alert('$MARK')</script>"
expect '302|303' "$(code "$JAR_A" -X POST "$P/request/create" \
  --data-urlencode "category=$MARK" --data-urlencode "destination=E2E" --data-urlencode "reason=$XSS" \
  --data-urlencode "_glpi_csrf_token=$T")" "POST /request/create"
LOC="$(grep -i '^location:' "$TMP/headers.txt" | tr -d '\r' | sed -E 's/^[Ll]ocation: *//')"
[[ "$LOC" =~ /plugins/companypurchasing/request/([0-9]+)/edit$ ]] || fail "PRG: redirección inesperada '$LOC'"
RID="${BASH_REMATCH[1]}"
echo "   borrador creado: id=$RID"
expect '403' "$(code "$JAR_A" -X POST "$P/request/create" --data-urlencode "category=$MARK-replay" \
  --data-urlencode "_glpi_csrf_token=$T")" "POST con token YA usado (anti-replay)"
expect '200' "$(code "$JAR_A" "$P/request/$RID")" "GET detalle del borrador"
grep -q "$MARK" "$TMP/body.html" || fail "el detalle no muestra el borrador creado"
grep -qF "<script>alert('$MARK')" "$TMP/body.html" && fail "salida SIN escapar (XSS)"
grep -qE "&lt;script&gt;alert\((&#0?39;|&#x27;|')$MARK" "$TMP/body.html" || fail "el texto con <script> no aparece escapado"
echo "   ok: texto con <script> escapado"
[ "$(count_cat "$MARK")" -eq 1 ] || fail "se esperaba exactamente UNA solicitud nueva (PRG sin duplicar)"
for bad in nocsrf badcsrf replay; do
  [ "$(count_cat "$MARK-$bad")" -eq 0 ] || fail "un POST rechazado por CSRF ($bad) creó una solicitud"
done
echo "   ok: los POST rechazados por CSRF no crearon nada"

echo ">> [6] ACL del lado servidor: usuario sin derechos de Compras ('$LOW_USER')"
login "$JAR_L" "$LOW_USER" "$LOW_PASS"
for path in requests request/new metrics config inbox/purchasing inbox/delivery "request/$RID"; do
  expect '403|404' "$(code "$JAR_L" "$P/$path")" "GET /$path sin derecho"
done
# Token CSRF VÁLIDO de su propia sesión (meta nativa del layout) ⇒ pasa CSRF y lo frena la ACL.
expect '200' "$(code "$JAR_L" "$BASE/front/preference.php")" "GET preferencias (sesión sin derechos de Compras)"
LT="$(meta_csrf_of "$TMP/body.html")"
[ -n "$LT" ] || LT="$(csrf_of "$TMP/body.html")"
[ -n "$LT" ] || fail "no se obtuvo un token CSRF de la sesión sin derechos"
expect '302|303' "$(code "$JAR_L" -X POST "$P/request/create" --data-urlencode "category=$MARK-low" \
  --data-urlencode "_glpi_csrf_token=$LT")" "POST /request/create sin derecho (CSRF válido)"
[ "$(count_cat "$MARK-low")" -eq 0 ] || fail "un usuario SIN derecho creó una solicitud"
echo "   ok: el POST sin derecho no creó nada"

rm -rf "$TMP"
echo "E2E OK: UI de Compras — GET/POST, CSRF, PRG, escape, ACL server-side (flujo HTTP real)."
