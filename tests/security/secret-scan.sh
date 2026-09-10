#!/usr/bin/env bash
# =====================================================================
# secret-scan.sh — Chequeo BÁSICO de secretos en el repositorio.
# FAIL-CLOSED: sale != 0 si detecta indicios de secretos versionados.
# Patrones de alta señal (bajo riesgo de falsos positivos).
# =====================================================================
set -euo pipefail
cd "$(cd "$(dirname "$0")/../.." && pwd)"

fail=0

echo ">> [1] No debe haber archivos .env reales versionados"
if git ls-files | grep -E '(^|/)\.env$' >/dev/null 2>&1; then
  echo "   FALLO: hay archivos .env versionados:"
  git ls-files | grep -E '(^|/)\.env$'
  fail=1
else
  echo "   OK"
fi

echo ">> [2] No debe haber claves privadas embebidas"
if git grep -nI -E -- '-----BEGIN (RSA |OPENSSH |EC |DSA |PGP )?PRIVATE KEY-----' >/dev/null 2>&1; then
  echo "   FALLO: bloque de clave privada detectado:"
  git grep -nI -E -- '-----BEGIN (RSA |OPENSSH |EC |DSA |PGP )?PRIVATE KEY-----'
  fail=1
else
  echo "   OK"
fi

echo ">> [3] No debe haber tokens/credenciales de alto riesgo conocidos"
# AWS AKIA, GitHub PAT, Slack, Google API key.
patterns='AKIA[0-9A-Z]{16}|ghp_[0-9A-Za-z]{36}|xox[baprs]-[0-9A-Za-z-]{10,}|AIza[0-9A-Za-z_-]{35}'
if git grep -nI -E -- "$patterns" >/dev/null 2>&1; then
  echo "   FALLO: posible token/credencial detectado:"
  git grep -nI -E -- "$patterns"
  fail=1
else
  echo "   OK"
fi

echo ">> [4] Los .env.example sólo deben contener placeholders para secretos"
# Buscar asignaciones de PASSWORD/SECRET/TOKEN con valor concreto (no placeholder,
# no vacío, no interpolación) dentro de los .env.example versionados.
env_examples="$(git ls-files '*.env.example')"
if [ -n "$env_examples" ]; then
  # shellcheck disable=SC2086
  if bad="$(grep -nE '(PASSWORD|SECRET|TOKEN|APIKEY|API_KEY)=' $env_examples \
            | grep -vE '=(\s*$|__CHANGE_ME__|\$\{)' || true)"; then
    if [ -n "$bad" ]; then
      echo "   FALLO: valor concreto en un .env.example (debería ser placeholder):"
      echo "$bad"
      fail=1
    else
      echo "   OK"
    fi
  fi
fi

if [ "$fail" -ne 0 ]; then
  echo "secret-scan: FALLÓ"
  exit 1
fi
echo "secret-scan: OK"
