#!/usr/bin/env bash
# =====================================================================
# verify-localization.sh — Verificación FAIL-CLOSED de la localización.
# ---------------------------------------------------------------------
# VERIFICA ESTADO, NO RECONFIGURA. Todas las comprobaciones son de sólo
# lectura e IDEMPOTENTES: pueden ejecutarse N veces sobre la misma
# instalación con el mismo resultado (ver la regresión en CI y ADR-0016).
#
# Comprueba, contra la instancia en ejecución, que:
#   1. El idioma por defecto es Español (página de login anónima en es).
#   2. La zona horaria del servidor (PHP) es America/Asuncion.
#   3. Las tablas de husos horarios de MariaDB están POBLADAS y son
#      ACCESIBLES por el usuario de GLPI (prerequisito real de GLPI).
#   4. El soporte de timezones es USABLE: el usuario de GLPI resuelve
#      una zona NOMBRADA (America/Asuncion) vía CONVERT_TZ.
#
# NOTA (ADR-0016): NO se reejecuta `database:enable_timezones`. Ese es un
# comando de CONFIGURACIÓN (mutación), no una sonda de estado; usarlo como
# "test" acopla la verificación a un efecto de escritura y produce fallos
# intermitentes ("faltan requisitos"). La habilitación se hace, una vez,
# en infra/docker/glpi-config/install-and-localize.sh.
#
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

: "${MARIADB_USER:=glpi}"
: "${MARIADB_PASSWORD:?Definir MARIADB_PASSWORD (infra/docker/.env)}"

# SQL de SÓLO LECTURA como usuario de GLPI, dentro del contenedor `db`.
# La contraseña se expande en el entorno del contenedor (no se imprime).
db_user_sql() {
  $COMPOSE exec -T db sh -c "mariadb -u \"\$MARIADB_USER\" -p\"\$MARIADB_PASSWORD\" -N -B -e \"$1\""
}

fail=0

echo ">> [1] Idioma por defecto = Español (página de login)"
html="$(curl -fsSL "$BASE_URL/" 2>/dev/null || true)"
# here-string (sin pipe): evita el falso negativo por 'broken pipe' que provoca
# grep -q al cerrar el pipe antes de que termine de escribir el productor.
if grep -Eiq 'lang="es' <<<"$html"; then
  echo "   OK: la interfaz anónima se sirve en español."
else
  echo "   FALLO: no se detectó 'lang=\"es\"' en la página (idioma por defecto != ES)."
  fail=1
fi

echo ">> [2] Zona horaria del servidor (PHP) = America/Asuncion"
tz="$($COMPOSE exec -T glpi php -r 'echo date_default_timezone_get();' 2>/dev/null | tr -d '\r\n' || true)"
if [ "$tz" = "America/Asuncion" ]; then
  echo "   OK: date.timezone = $tz"
else
  echo "   FALLO: date.timezone = '$tz' (esperado America/Asuncion)."
  fail=1
fi

echo ">> [3] Tablas de husos horarios pobladas y accesibles por el usuario de GLPI"
tz_rows="$(db_user_sql 'SELECT COUNT(*) FROM mysql.time_zone_name;' 2>/dev/null | tr -d '\r\n' || true)"
case "$tz_rows" in
  ''|*[!0-9]*)
    echo "   FALLO: el usuario de GLPI no pudo leer mysql.time_zone_name (resultado: '${tz_rows}')."
    fail=1 ;;
  *)
    if [ "$tz_rows" -gt 0 ]; then
      echo "   OK: ${tz_rows} zonas visibles para el usuario de GLPI."
    else
      echo "   FALLO: mysql.time_zone_name está vacía para el usuario de GLPI."
      fail=1
    fi ;;
esac

echo ">> [4] Soporte de timezones usable: resolución de zona nombrada (America/Asuncion)"
conv="$(db_user_sql "SELECT CONVERT_TZ('2020-06-01 12:00:00','UTC','America/Asuncion');" 2>/dev/null | tr -d '\r\n' || true)"
if [ -n "$conv" ] && [ "$conv" != "NULL" ]; then
  echo "   OK: CONVERT_TZ(UTC->America/Asuncion) = $conv"
else
  echo "   FALLO: no se resolvió la zona nombrada (CONVERT_TZ='${conv}'); timezones no usables."
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo "verify-localization: FALLÓ"
  exit 1
fi
echo "verify-localization: OK"
