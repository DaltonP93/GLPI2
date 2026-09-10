#!/usr/bin/env bash
# =====================================================================
# verify-core-untouched.sh — Garantiza la Regla 0 a nivel de repositorio:
# el core de GLPI NO vive aquí y no se versiona ningún archivo de core.
# Falla (exit 1) si detecta rutas típicas de código de core versionadas.
# =====================================================================
set -euo pipefail
cd "$(cd "$(dirname "$0")/../.." && pwd)"

FAIL=0

# 1) El directorio glpi/ solo puede contener el placeholder .gitkeep.
if git ls-files glpi/ | grep -vE '^glpi/\.gitkeep$' | grep -q .; then
  echo "FALLO: hay archivos de GLPI versionados bajo glpi/:"
  git ls-files glpi/ | grep -vE '^glpi/\.gitkeep$'
  FAIL=1
fi

# 2) No deben existir marcadores de código de core en la raíz del repo.
for marker in inc/based_config.php front/central.php ajax/common.tabs.php \
              install/install.php vendor/autoload.php; do
  if git ls-files | grep -qx "$marker"; then
    echo "FALLO: se detectó código de core versionado: $marker"
    FAIL=1
  fi
done

if [ "$FAIL" -eq 0 ]; then
  echo "OK: el core de GLPI no está versionado en este repositorio."
fi
exit "$FAIL"
