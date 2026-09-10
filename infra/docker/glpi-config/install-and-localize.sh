#!/usr/bin/env bash
# =====================================================================
# install-and-localize.sh — Instalación REPRODUCIBLE de GLPI con
# localización Paraguay, usando SOLO comandos oficiales de consola de
# GLPI 11 (verificados contra 11.0.8). No usa SQL directo al core.
# ---------------------------------------------------------------------
# Cubre de forma reproducible:
#   - Idioma por defecto = Español (es_ES)          [database:install -L]
#   - Soporte de timezones habilitado               [database:enable_timezones]
#   - Tablas de husos horarios cargadas en MariaDB  [mariadb-tzinfo-to-sql]
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

echo ">> [1/4] Instalando GLPI (idioma por defecto es_ES)..."
$COMPOSE exec -T glpi php bin/console database:install \
  --db-host=db --db-name="$MARIADB_DATABASE" \
  --db-user="$MARIADB_USER" --db-password="$MARIADB_PASSWORD" \
  --default-language=es_ES --no-interaction
$COMPOSE exec -T glpi rm -f /var/www/glpi/install/install.php || true

echo ">> [2/4] Cargando tablas de husos horarios en MariaDB..."
$COMPOSE exec -T db sh -c \
  'mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb -u root -p"$MARIADB_ROOT_PASSWORD" mysql'

echo ">> [3/4] Otorgando SELECT sobre mysql.time_zone_name al usuario de GLPI..."
$COMPOSE exec -T db sh -c \
  "mariadb -u root -p\"\$MARIADB_ROOT_PASSWORD\" -e \
   \"GRANT SELECT ON mysql.time_zone_name TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;\""

echo ">> [4/4] Habilitando soporte de timezones (database:enable_timezones)..."
$COMPOSE exec -T glpi php bin/console database:enable_timezones --no-interaction

echo "OK: GLPI instalado con idioma es_ES y timezones habilitadas de forma reproducible."
echo "    Zona horaria de instancia (America/Asuncion), formato PYG y SMTP: pasos"
echo "    administrativos documentados (ver docs/operations/localization.md)."
