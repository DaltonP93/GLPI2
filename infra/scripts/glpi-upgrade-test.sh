#!/usr/bin/env bash
# =====================================================================
# glpi-upgrade-test.sh — Prueba de actualización de GLPI verificando que
# NUESTROS PLUGINS REQUERIDOS SOBREVIVEN sin modificar el core.
# ---------------------------------------------------------------------
# FAIL-CLOSED: si CUALQUIER plugin requerido no instala, migra o activa
# en la versión destino, o si los smoke tests fallan, el script termina
# con exit != 0. Sólo imprime OK si TODO está verde.
#
# Nombres de comandos verificados contra GLPI 11.0.8 (sin prefijo glpi:):
#   database:update  ·  plugin:install  ·  plugin:activate  ·  plugin:list
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

# Plugins REQUERIDOS: todos deben quedar instalados y activos en la versión
# destino. Si alguno no puede, el upgrade NO se considera exitoso.
REQUIRED_PLUGINS="companyportal companypurchasing companyworkflow companyqr companydashboard companysignature companyintegrations"

echo "== [1/6] Backup previo =="
"$HERE/../backup/backup.sh"

echo "== [2/6] Verificar huella del core (no debe haber cambios propios) =="
"$HERE/../../tests/upgrade/verify-core-untouched.sh" || {
  echo "ERROR: se detectó posible código de core en el repo. Abortando."; exit 1;
}

echo "== [3/6] Reconstruir imagen con GLPI $TARGET =="
GLPI_VERSION="$TARGET" $COMPOSE build glpi cron
GLPI_VERSION="$TARGET" $COMPOSE up -d glpi cron

echo "== [4/6] Migraciones del core =="
if ! $COMPOSE exec -T glpi php bin/console database:update --no-interaction; then
  echo "ERROR en migración de core (database:update)."
  echo "Rollback: infra/backup/restore.sh <backup>"; exit 1
fi

echo "== [5/6] Migrar/activar plugins requeridos (FAIL-CLOSED) =="
fail=0
for p in $REQUIRED_PLUGINS; do
  echo "-- Plugin requerido: $p"
  # Instalar/migrar. Un fallo aquí NO se ignora.
  if ! $COMPOSE exec -T glpi php bin/console plugin:install --username=glpi "$p"; then
    echo "ERROR: fallo instalación/migración del plugin requerido '$p'."
    fail=1
    continue
  fi
  # Activar. Un fallo aquí ES un error, NO un simple aviso.
  if ! $COMPOSE exec -T glpi php bin/console plugin:activate "$p"; then
    echo "ERROR: fallo activación del plugin requerido '$p' en GLPI $TARGET"
    echo "       (revisar rango de versiones soportadas del plugin)."
    fail=1
  fi
done

if [ "$fail" -ne 0 ]; then
  echo "ERROR: uno o más plugins requeridos no sobrevivieron a la actualización."
  echo "Rollback: infra/backup/restore.sh <backup>"
  exit 1
fi

echo "== [6/6] Smoke tests =="
if ! "$HERE/../../tests/smoke/run-smoke.sh"; then
  echo "ERROR en smoke tests tras la actualización."
  echo "Rollback: infra/backup/restore.sh <backup>"; exit 1
fi

echo "OK: GLPI actualizado a $TARGET; TODOS los plugins requeridos instalados y"
echo "    activos, y smoke tests verdes, sin tocar el core."
