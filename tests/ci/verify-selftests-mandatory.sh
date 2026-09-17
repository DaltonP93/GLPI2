#!/usr/bin/env bash
# =====================================================================
# verify-selftests-mandatory.sh — Regresión ESTÁTICA y determinista que
# impide reintroducir el "falso verde" de Fase 2.
#
# Contexto: el paso de integración solía descubrir los selftests con
#   `php bin/console list | grep -q "plugins:<p>:selftest"`
# bajo `set -o pipefail`. `grep -q` cierra el pipe al primer match y
# `bin/console list` recibe SIGPIPE (exit 141), que pipefail propaga como
# fallo → el `if` caía al `else` "omitido". Resultado: un CI VERDE que en
# realidad NUNCA ejecutaba los selftests de companyworkflow /
# companyintegrations / companysignature.
#
# Esta prueba (sin stack, sólo lectura de archivos) garantiza que:
#   1) los tres comandos de selftest EXISTEN en el código (registro real);
#   2) el CI los ejecuta DIRECTAMENTE (sin guard `list | grep -q`);
#   3) no queda rastro del guard racy ni del concepto "omitido".
# Falla (exit 1) ante cualquier regresión.
# =====================================================================
set -euo pipefail
cd "$(cd "$(dirname "$0")/../.." && pwd)"

CI=".github/workflows/ci.yml"
PLUGINS="companyworkflow companyintegrations companysignature"
FAIL=0

if [ ! -f "$CI" ]; then
  echo "FALLO: no se encontró $CI"
  exit 1
fi

echo ">> [1] Cada plugin registra su comando plugins:<p>:selftest"
for p in $PLUGINS; do
  cmd="plugins:${p}:selftest"
  # El comando se registra vía setName() en el/los SelftestCommand del plugin.
  if grep -Rqs --include='*.php' -- "->setName('${cmd}')" "plugins/${p}/src"; then
    echo "   OK: ${cmd} registrado en plugins/${p}/src"
  else
    echo "   FALLO: no se encontró el registro de ${cmd} en plugins/${p}/src"
    FAIL=1
  fi
done

echo ">> [2] El CI ejecuta los tres selftests DIRECTAMENTE (sin guard de descubrimiento)"
# El paso itera sobre los tres plugins e invoca el comando por variable.
if grep -q 'for p in companyworkflow companyintegrations companysignature' "$CI"; then
  echo "   OK: el bucle recorre exactamente los tres plugins obligatorios"
else
  echo "   FALLO: no se encontró el bucle 'for p in companyworkflow companyintegrations companysignature' en $CI"
  FAIL=1
fi
if grep -q 'bin/console "plugins:${p}:selftest"' "$CI"; then
  echo "   OK: cada selftest se invoca directamente (plugins:\${p}:selftest)"
else
  echo "   FALLO: no se encontró la invocación directa 'bin/console \"plugins:\${p}:selftest\"' en $CI"
  FAIL=1
fi

echo ">> [3] No debe quedar el guard racy 'list | grep -q ...:selftest' ni el concepto 'omitido'"
if grep -Eq 'grep[[:space:]]+-q[[:space:]]+"plugins:\$\{?p\}?:selftest"' "$CI"; then
  echo "   FALLO: persiste el guard racy 'grep -q \"plugins:\${p}:selftest\"' en $CI"
  FAIL=1
else
  echo "   OK: no hay guard 'grep -q ...:selftest'"
fi
if grep -q 'plugins:companysignature:reconcile' "$CI" \
   && grep -Eq 'grep[[:space:]]+-q[[:space:]]+"plugins:companysignature:reconcile"' "$CI"; then
  echo "   FALLO: el reconcile aún usa el guard racy 'grep -q'"
  FAIL=1
else
  echo "   OK: el reconcile no usa guard racy"
fi
if grep -q 'omitido' "$CI"; then
  echo "   FALLO: el CI todavía contempla 'omitido' para estos selftests (deben ser obligatorios)"
  FAIL=1
else
  echo "   OK: no existe el concepto 'omitido'"
fi

if [ "$FAIL" -ne 0 ]; then
  echo "verify-selftests-mandatory: FALLO"
  exit 1
fi
echo "verify-selftests-mandatory: OK"
