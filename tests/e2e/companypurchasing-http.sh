#!/usr/bin/env bash
# =====================================================================
# companypurchasing-http.sh — E2E HTTP REAL de la UI de Compras (P2D-4, ADR-0023 §8).
# Comprueba, por HTTP contra el stack (no sólo servicios):
#   [0] sin sesión ⇒ la UI no se sirve (cualquier código ≠ 200; GLPI redirige al login);
#   [2] páginas GET autenticadas (admin) ⇒ 200 y sin `lease_token` / `payload_sha256` / `input_sha256` en el HTML;
#   [3] GET sobre 7 rutas de acción ⇒ 405 (el controlador no corre);
#   [4] POST sin token CSRF y con token inválido ⇒ 403;
#   [5] POST válido ⇒ 302/303 a `/request/{id}/edit` (PRG); token ya usado ⇒ 403; texto con <script> mostrado
#       escapado; exactamente UNA solicitud creada y CERO por los POST rechazados por CSRF;
#   [6] acción AUTORIZADA pero rechazada por el dominio (admin con DELIVER entrega un BORRADOR) ⇒ 302/303 (PRG) y el
#       contenido del detalle (cabecera…auditoría) no cambia;
#   [7] usuario autenticado SIN derechos de Compras (Self-Service): 7 páginas ⇒ 403/404; control positivo (un token de
#       la meta nativa es un CSRF válido: el admin crea con él ⇒ PRG); con un token CSRF VÁLIDO de su propia sesión:
#       POST /request/create ⇒ 403 y exactamente 0 solicitudes; POST /request/{id}/receive y /deliver ⇒ 403 y el
#       contenido del detalle no cambia.
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

echo ">> [6] Rechazo funcional de una acción AUTORIZADA ⇒ PRG (no 403), sin cambios"
detail_digest() { # huella del CONTENIDO del detalle (cabecera … auditoría), sin los tokens que cambian por render
  expect '200' "$(code "$JAR_A" "$P/request/$1")" "GET detalle /request/$1 (admin)" >&2
  python3 - "$TMP/body.html" <<'PYEOF'
import hashlib, re, sys
h = open(sys.argv[1], encoding='utf-8').read()
a, z = h.find('<span class="badge bg-primary">'), h.rfind('</tbody>')
seg = h[a:z] if a >= 0 and z > a else ''
seg = re.sub(r'(_glpi_csrf_token"[^>]*value=")[^"]+', r'\1X', seg)
seg = re.sub(r'ui-(rcv|dlv)-[0-9a-f]+', r'ui-\1-X', seg)
print(hashlib.sha256(seg.encode()).hexdigest() if seg else 'VACIO')
PYEOF
}
SNAP="$(detail_digest "$RID")"
[ "$SNAP" != "VACIO" ] || fail "no se pudo extraer el contenido del detalle"
expect '200' "$(code "$JAR_A" "$P/request/$RID")" "GET detalle (token CSRF del admin)"
AT="$(csrf_of "$TMP/body.html")"
[ -n "$AT" ] || fail "el detalle no trae token CSRF"
# El admin TIENE RIGHT_DELIVER: pasa el preflight; el DOMINIO rechaza entregar un BORRADOR ⇒ PRG + mensaje, nada cambia.
expect '302|303' "$(code "$JAR_A" -X POST "$P/request/$RID/deliver" --data-urlencode "idempotency_key=e2e-dlv-$MARK" \
  --data-urlencode "_glpi_csrf_token=$AT")" "POST /request/$RID/deliver autorizado pero inválido para un borrador (PRG)"
[ "$(detail_digest "$RID")" = "$SNAP" ] || fail "el rechazo funcional cambió la solicitud"
echo "   ok: rechazo funcional ⇒ PRG y la solicitud no cambió"

echo ">> [7] ACL del lado servidor: usuario autenticado SIN derechos de Compras ('$LOW_USER') ⇒ 403"
login "$JAR_L" "$LOW_USER" "$LOW_PASS"
for path in requests request/new metrics config inbox/purchasing inbox/delivery "request/$RID"; do
  expect '403|404' "$(code "$JAR_L" "$P/$path")" "GET /$path sin derecho"
done
meta_token() { # token CSRF VÁLIDO y NUEVO de la sesión (meta nativa del layout); uno por POST (GLPI los consume)
  expect '200' "$(code "$1" "$BASE/front/preference.php")" "GET preferencias (token CSRF de la sesión)" >&2
  local t; t="$(meta_csrf_of "$TMP/body.html")"; [ -n "$t" ] || t="$(csrf_of "$TMP/body.html")"
  printf '%s' "$t"
}
# Control POSITIVO del método: un token de la meta nativa ES un CSRF válido (el admin crea con él ⇒ PRG). Así el 403
# del usuario sin derecho, obtenido con el MISMO método, no puede deberse al CSRF.
T="$(meta_token "$JAR_A")"; [ -n "$T" ] || fail "sin token CSRF (meta) del admin"
expect '302|303' "$(code "$JAR_A" -X POST "$P/request/create" --data-urlencode "category=$MARK-meta" \
  --data-urlencode "_glpi_csrf_token=$T")" "control: POST /request/create con token de la meta (admin) ⇒ PRG"
[ "$(count_cat "$MARK-meta")" -eq 1 ] || fail "control: el token de la meta no creó la solicitud"
T="$(meta_token "$JAR_L")"; [ -n "$T" ] || fail "sin token CSRF (meta) de la sesión sin derechos"
expect '403' "$(code "$JAR_L" -X POST "$P/request/create" --data-urlencode "category=$MARK-low" \
  --data-urlencode "_glpi_csrf_token=$T")" "POST /request/create sin CREATE_REQUEST (CSRF válido)"
[ "$(count_cat "$MARK-low")" -eq 0 ] || fail "un usuario SIN derecho creó una solicitud"
echo "   ok: exactamente 0 solicitudes creadas por el usuario sin derecho"
for op in receive deliver; do
  T="$(meta_token "$JAR_L")"; [ -n "$T" ] || fail "sin token CSRF (meta) de la sesión sin derechos"
  expect '403' "$(code "$JAR_L" -X POST "$P/request/$RID/$op" --data-urlencode "idempotency_key=e2e-low-$op-$MARK" \
    --data-urlencode "_glpi_csrf_token=$T")" "POST /request/$RID/$op sin derecho operativo (CSRF válido)"
done
[ "$(detail_digest "$RID")" = "$SNAP" ] || fail "un POST operacional SIN derecho cambió la solicitud"
echo "   ok: POST operacionales sin derecho ⇒ 403 y la solicitud no cambió"

rm -rf "$TMP"
echo "E2E OK: UI de Compras — sesión, GET/POST (405), CSRF (403), PRG, escape, rechazo funcional (PRG), ACL server-side (403) — flujo HTTP real."
