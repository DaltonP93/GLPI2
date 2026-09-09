#!/usr/bin/env bash
# =====================================================================
# backup.sh — Copia de seguridad de GLPI (base de datos + archivos).
# Debe ejecutarse ANTES de cualquier actualización (core o plugins).
# No incluye secretos: lee credenciales desde infra/docker/.env.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$HERE/../docker"
ENV_FILE="$DOCKER_DIR/.env"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT_DIR="${BACKUP_DIR:-$HERE/../../_backups}/$STAMP"

if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: no existe $ENV_FILE (copiar de .env.example y completar)." >&2
  exit 1
fi
# shellcheck disable=SC1090
set -a; . "$ENV_FILE"; set +a

mkdir -p "$OUT_DIR"
echo ">> Backup en: $OUT_DIR"

# 1) Volcado de base de datos (consistente).
echo ">> Volcando base de datos '$MARIADB_DATABASE'..."
docker compose -f "$DOCKER_DIR/docker-compose.yml" exec -T db \
  mariadb-dump --single-transaction --quick --default-character-set=utf8mb4 \
  -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" \
  | gzip > "$OUT_DIR/db-$MARIADB_DATABASE.sql.gz"

# 2) Archivos de GLPI (files/, config/). Los plugins ya están en Git.
echo ">> Copiando volumen de archivos (files/) y config/..."
docker compose -f "$DOCKER_DIR/docker-compose.yml" exec -T glpi \
  tar -czf - -C /var/www/glpi files config \
  > "$OUT_DIR/glpi-files.tar.gz"

# 3) Metadatos del backup (para verificar en el restore/upgrade).
{
  echo "timestamp=$STAMP"
  echo "glpi_version=${GLPI_VERSION:-unknown}"
  echo "database=$MARIADB_DATABASE"
} > "$OUT_DIR/MANIFEST.txt"

echo ">> Backup completado."
ls -lh "$OUT_DIR"
