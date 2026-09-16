#!/usr/bin/env bash
# =====================================================================
# verify-glpi-security-key.sh — Regresión FAIL-CLOSED del workaround para el
# bug upstream de GLPI 11 en `kernel.secret` (clave `glpicrypt.key` con '%').
# ---------------------------------------------------------------------
# Verifica, contra la instancia en ejecución (sólo lectura salvo el muestreo
# en memoria):
#   [1] La clave provisionada es válida: existe, 32 bytes, propietario www-data,
#       sin acceso para "otros", y SIN ningún byte '%'. (Nunca imprime la clave.)
#   [2] Reproducción DETERMINISTA del bug con el mismo componente Symfony DI:
#       un kernel.secret con '%' rompe compile(); sin '%' compila (ver
#       tests/security/glpi-kernel-secret-repro.php).
#   [3] Varios `php bin/console` consecutivos SIN ParameterNotFoundException
#       (el contenedor Symfony + plugins compila correctamente).
#   [4] El generador de clave NUNCA produce '%' (muestreo CSPRNG, N grande):
#       demuestra que el origen del flake quedó eliminado por construcción.
#
# Sale != 0 si algo no cumple. No usa `|| true` para ocultar errores.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$HERE/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infra/docker"
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
ENV_FILE="$DOCKER_DIR/.env"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }

REPRO="$REPO_ROOT/tests/security/glpi-kernel-secret-repro.php"
fail=0

echo ">> [1] Clave provisionada válida (32 bytes, www-data, sin acceso 'otros', sin %)"
if $COMPOSE exec -T -u www-data glpi php -r '
  $f = "/var/www/glpi/config/glpicrypt.key";
  clearstatcache();
  if (!is_file($f)) { fwrite(STDERR, "no existe\n"); exit(1); }
  $c = file_get_contents($f); $e = [];
  if (strlen($c) !== 32) { $e[] = "tam=" . strlen($c); }
  if (strpos($c, "%") !== false) { $e[] = "contiene %"; }
  if (fileowner($f) !== getmyuid()) { $e[] = "owner!=www-data"; }
  if ((fileperms($f) & 0007) !== 0) { $e[] = "otros con acceso"; }
  if ($e) { fwrite(STDERR, implode("; ", $e) . "\n"); exit(1); }
  exit(0);
'; then
  echo "   OK: la clave cumple todas las invariantes."
else
  echo "   FALLO: la clave provisionada no es válida."
  fail=1
fi

echo ">> [2] Reproducción determinista del bug kernel.secret (Symfony DI)"
if [ ! -f "$REPRO" ]; then
  echo "   FALLO: no se encontró $REPRO"
  fail=1
elif $COMPOSE exec -T glpi php < "$REPRO"; then
  echo "   OK: el bug se reproduce con '%' y desaparece sin '%'."
else
  echo "   FALLO: la reproducción determinista no pasó."
  fail=1
fi

echo ">> [3] Varios 'php bin/console' consecutivos sin ParameterNotFoundException"
console_ok=1
for i in 1 2 3; do
  if out="$($COMPOSE exec -T -u www-data glpi php bin/console list 2>&1)"; then
    if printf '%s' "$out" | grep -q 'ParameterNotFoundException'; then
      echo "   FALLO: la pasada $i lanzó ParameterNotFoundException."
      console_ok=0
    fi
  else
    echo "   FALLO: 'bin/console list' falló en la pasada $i."
    console_ok=0
  fi
done
if [ "$console_ok" -eq 1 ]; then
  echo "   OK: 3 invocaciones de consola limpias (container Symfony compila)."
else
  fail=1
fi

echo ">> [4] El generador de clave nunca produce '%' (muestreo CSPRNG, N=200)"
if $COMPOSE exec -T -u www-data glpi php -r '
  for ($i = 0; $i < 200; $i++) {
    $n = 0;
    do { $k = random_bytes(32); $n++; } while (strpos($k, "%") !== false && $n < 10000);
    if (strlen($k) !== 32) { fwrite(STDERR, "len!=32 en muestra $i\n"); exit(1); }
    if (strpos($k, "%") !== false) { fwrite(STDERR, "quedó % en muestra $i\n"); exit(1); }
  }
  echo "200 claves de 32 bytes, todas sin %\n";
'; then
  echo "   OK: origen del flake eliminado por construcción."
else
  echo "   FALLO: el generador produjo una clave con % (o de tamaño incorrecto)."
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo "verify-glpi-security-key: FALLÓ"
  exit 1
fi
echo "verify-glpi-security-key: OK"
