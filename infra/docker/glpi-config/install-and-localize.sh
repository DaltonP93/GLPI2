#!/usr/bin/env bash
# =====================================================================
# install-and-localize.sh — Instalación REPRODUCIBLE de GLPI con
# localización Paraguay, usando SOLO comandos oficiales de consola de
# GLPI 11 (verificados contra 11.0.8). No usa SQL directo al core.
# ---------------------------------------------------------------------
# Este script es la fase de CONFIGURACIÓN (mutación). Deja el sistema en
# el estado deseado y VERIFICA fail-closed cada prerequisito ANTES de
# habilitar timezones. La fase de VERIFICACIÓN de estado (read-only, sin
# reconfigurar) vive en tests/localization/verify-localization.sh.
#
# Cubre de forma reproducible:
#   - Idioma por defecto = Español (es_ES)          [database:install -L]
#   - Tablas de husos horarios cargadas en MariaDB  [mariadb-tzinfo-to-sql]
#     (se VERIFICA que quedaron pobladas: fail-closed)
#   - SELECT sobre mysql.time_zone_name para GLPI    [GRANT]
#   - Soporte de timezones habilitado               [database:enable_timezones]
#     (se VERIFICA que las zonas nombradas son usables por el usuario GLPI)
#
# IMPORTANTE (idempotencia): `database:enable_timezones` es un comando de
# CONFIGURACIÓN, no una sonda de estado. Se ejecuta aquí, una vez, tras
# garantizar sus prerequisitos. NUNCA debe reejecutarse como "test" de
# estado (ver ADR-0016 y verify-localization.sh).
#
# Config que en GLPI 11 es ADMINISTRATIVA (no hay comando de consola):
#   - zona horaria por defecto de la instancia (America/Asuncion)
#   - formato numérico para PYG (0 decimales)
#   - SMTP / seguimientos por correo
# -> documentada en docs/operations/localization.md y email-dev.md,
#    con pruebas fail-closed en tests/localization y tests/mail.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$(cd "$HERE/.." && pwd)"        # infra/docker
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
ENV_FILE="$DOCKER_DIR/.env"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }

: "${MARIADB_DATABASE:=glpi}"
: "${MARIADB_USER:=glpi}"
: "${MARIADB_PASSWORD:?Definir MARIADB_PASSWORD (infra/docker/.env)}"
: "${MARIADB_ROOT_PASSWORD:?Definir MARIADB_ROOT_PASSWORD (infra/docker/.env)}"

# ---------------------------------------------------------------------
# Helpers: ejecutar SQL DENTRO del contenedor `db`. Las contraseñas se
# expanden en el entorno del contenedor (inyectado por compose), no se
# imprimen. `-N -B` => salida sin cabeceras en modo batch (una fila/valor).
# El SQL ($1) NO debe contener comillas dobles.
# ---------------------------------------------------------------------
db_root_sql() {
  $COMPOSE exec -T db sh -c "mariadb -u root -p\"\$MARIADB_ROOT_PASSWORD\" -N -B -e \"$1\""
}
db_user_sql() {
  $COMPOSE exec -T db sh -c "mariadb -u \"\$MARIADB_USER\" -p\"\$MARIADB_PASSWORD\" -N -B -e \"$1\""
}

echo ">> [0/6] Esperando a que MariaDB acepte conexiones..."
db_ready=0
for _ in $(seq 1 60); do
  if db_root_sql "SELECT 1;" >/dev/null 2>&1; then db_ready=1; break; fi
  sleep 2
done
[ "$db_ready" -eq 1 ] || { echo "   FALLO: MariaDB no respondió a tiempo."; exit 1; }
echo "   OK: MariaDB acepta conexiones."

echo ">> [1/6] Instalando GLPI (idioma por defecto es_ES)..."
$COMPOSE exec -T -u www-data glpi php bin/console database:install \
  --db-host=db --db-name="$MARIADB_DATABASE" \
  --db-user="$MARIADB_USER" --db-password="$MARIADB_PASSWORD" \
  --default-language=es_ES --no-interaction
$COMPOSE exec -T glpi rm -f /var/www/glpi/install/install.php || true

echo ">> [2/6] Cargando tablas de husos horarios en MariaDB (fail-closed)..."
# Se genera el SQL y se carga con exit-codes encadenados por '&&' (portable en
# dash, sin depender de 'pipefail'): si el generador falla, NO se enmascara y el
# paso aborta. La salida de error se conserva para diagnóstico.
if ! $COMPOSE exec -T db sh -c '
      mariadb-tzinfo-to-sql /usr/share/zoneinfo >/tmp/tz.sql 2>/tmp/tzinfo.err &&
      mariadb -u root -p"$MARIADB_ROOT_PASSWORD" mysql </tmp/tz.sql
    '; then
  echo "   FALLO: no se pudieron generar/cargar las tablas de timezones."
  $COMPOSE exec -T db sh -c 'echo "--- tzinfo stderr (últimas líneas) ---"; tail -n 40 /tmp/tzinfo.err 2>/dev/null || true' || true
  exit 1
fi
echo "   OK: tablas de timezones cargadas."

echo ">> [3/6] Verificando que mysql.time_zone_name quedó poblada (fail-closed)..."
tz_rows="$(db_root_sql 'SELECT COUNT(*) FROM mysql.time_zone_name;' | tr -d '\r\n' || true)"
case "$tz_rows" in
  ''|*[!0-9]*) echo "   FALLO: no se pudo leer el conteo de zonas (resultado: '${tz_rows}')."; exit 1 ;;
esac
[ "$tz_rows" -gt 0 ] || { echo "   FALLO: mysql.time_zone_name está vacía."; exit 1; }
echo "   OK: ${tz_rows} zonas horarias cargadas."

echo ">> [4/6] Otorgando SELECT sobre mysql.time_zone_name al usuario de GLPI..."
db_root_sql "GRANT SELECT ON mysql.time_zone_name TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;"
echo "   OK: privilegio otorgado a '${MARIADB_USER}'@'%'."

echo ">> [5/6] Habilitando soporte de timezones (database:enable_timezones)..."
$COMPOSE exec -T -u www-data glpi php bin/console database:enable_timezones --no-interaction
echo "   OK: database:enable_timezones ejecutado."

echo ">> [6/6] Verificando que el usuario de GLPI resuelve zonas nombradas (fail-closed)..."
# CONVERT_TZ con una zona NOMBRADA devuelve NULL si las tablas tz no están
# cargadas o el usuario no puede leerlas. Prueba de extremo a extremo del
# prerequisito real de GLPI (areTimezonesAvailable), como usuario de GLPI.
conv="$(db_user_sql "SELECT CONVERT_TZ('2020-06-01 12:00:00','UTC','America/Asuncion');" | tr -d '\r\n' || true)"
if [ -z "$conv" ] || [ "$conv" = "NULL" ]; then
  echo "   FALLO: el usuario de GLPI no resuelve zonas nombradas (CONVERT_TZ='${conv}')."
  exit 1
fi
echo "   OK: CONVERT_TZ(UTC->America/Asuncion) = ${conv} (zonas nombradas usables)."

echo "OK: GLPI instalado con idioma es_ES y timezones habilitadas de forma reproducible."
echo "    Zona horaria de instancia (America/Asuncion), formato PYG y SMTP: pasos"
echo "    administrativos documentados (ver docs/operations/localization.md)."
