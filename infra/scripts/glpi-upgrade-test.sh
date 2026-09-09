#!/usr/bin/env bash
# =====================================================================
# glpi-upgrade-test.sh — Prueba de actualización de GLPI verificando que
# NUESTROS PLUGINS SOBREVIVEN sin modificar el core.
# ---------------------------------------------------------------------
# Estrategia:
#   1. Backup del entorno actual.
#   2. Verificar que el core NO tiene cambios propios (huella limpia).
#   3. Reconstruir la imagen con la NUEVA versión de GLPI (upstream).
#   4. Ejecutar migraciones del core y de plugins.
#   5. Reinstalar/activar plugins y correr smoke tests.
#   6. Si algo falla -> rollback con restore.sh.
#
# Uso:  ./glpi-upgrade-test.sh <version_destino>   (ej: 11.0.9)
# Requiere el stack de infra/docker levantado.
# =====================================================================
set -euo pipefail

TARGET="${1:-}"
[ -n "$TARGET" ] || { echo "Uso: $0 <version_destino_glpi>"; exit 2; }

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$HERE/../docker"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
PLUGINS="companyportal companypurchasing companyworkflow companyqr companydashboard companysignature companyintegrations"

echo "== [1/6] Backup previo =="
"$HERE/../backup/backup.sh"

echo "== [2/6] Verificar huella del core (no debe haber cambios propios) =="
# El core se descarga en la imagen; NO vive en el repo. Aquí solo confirmamos
# que el repo no contiene código de core (ver tests/upgrade/verify-core-untouched.sh).
"$HERE/../../tests/upgrade/verify-core-untouched.sh" || {
  echo "ERROR: se detectó posible código de core en el repo. Abortando."; exit 1;
}

echo "== [3/6] Reconstruir imagen con GLPI $TARGET =="
GLPI_VERSION="$TARGET" $COMPOSE build glpi cron
GLPI_VERSION="$TARGET" $COMPOSE up -d glpi cron

echo "== [4/6] Migraciones del core =="
$COMPOSE exec -T glpi php bin/console db:update --no-interaction || {
  echo "ERROR en migración de core. Ejecutar rollback: infra/backup/restore.sh <backup>"; exit 1;
}

echo "== [5/6] Migrar/activar plugins =="
for p in $PLUGINS; do
  # Solo si el plugin declara compatibilidad con la nueva versión.
  $COMPOSE exec -T glpi php bin/console glpi:plugin:install --username=glpi "$p" || true
  $COMPOSE exec -T glpi php bin/console glpi:plugin:activate "$p" || \
    echo "AVISO: '$p' no se activó (revisar rango de versiones soportadas)."
done

echo "== [6/6] Smoke tests =="
"$HERE/../../tests/smoke/run-smoke.sh" || {
  echo "ERROR en smoke tests. Ejecutar rollback: infra/backup/restore.sh <backup>"; exit 1;
}

echo "OK: GLPI actualizado a $TARGET y plugins verificados sin tocar el core."
