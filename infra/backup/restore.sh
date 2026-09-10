#!/usr/bin/env bash
# =====================================================================
# restore.sh — Restaura un backup creado por backup.sh.
# Uso:  ./restore.sh <ruta-al-directorio-de-backup>
# Procedimiento de rollback ante fallo de actualización.
# =====================================================================
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Uso: $0 <directorio-de-backup>" >&2
  exit 2
fi
SRC="$1"
HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$HERE/../docker"
ENV_FILE="$DOCKER_DIR/.env"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"

[ -f "$ENV_FILE" ] || { echo "ERROR: falta $ENV_FILE" >&2; exit 1; }
[ -d "$SRC" ]      || { echo "ERROR: no existe $SRC" >&2; exit 1; }
# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

echo ">> ATENCIÓN: se sobrescribirá la base '$MARIADB_DATABASE' y los archivos."
printf ">> Escribí 'RESTAURAR' para continuar: "
read -r ans
[ "$ans" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }

# 1) Restaurar base de datos.
DB_DUMP="$(ls "$SRC"/db-*.sql.gz 2>/dev/null | head -n1 || true)"
[ -n "$DB_DUMP" ] || { echo "ERROR: no hay volcado db-*.sql.gz en $SRC" >&2; exit 1; }
echo ">> Restaurando base de datos desde $(basename "$DB_DUMP")..."
gunzip -c "$DB_DUMP" | $COMPOSE exec -T db \
  mariadb --default-character-set=utf8mb4 -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"

# 2) Restaurar archivos (files/, config/).
if [ -f "$SRC/glpi-files.tar.gz" ]; then
  echo ">> Restaurando files/ y config/..."
  $COMPOSE exec -T glpi tar -xzf - -C /var/www/glpi < "$SRC/glpi-files.tar.gz"
  $COMPOSE exec -T glpi chown -R www-data:www-data /var/www/glpi/files /var/www/glpi/config
fi

echo ">> Restauración completada. Ejecutar smoke tests (tests/smoke)."
