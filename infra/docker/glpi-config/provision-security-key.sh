#!/usr/bin/env bash
# =====================================================================
# provision-security-key.sh — Workaround de INFRAESTRUCTURA para un bug
# upstream de GLPI 11 (NO modifica el core ni el algoritmo criptográfico).
# ---------------------------------------------------------------------
# Bug upstream (GLPI 11.0.8):
#   - `config/glpicrypt.key` es una clave BINARIA de 32 bytes generada por
#     GLPI en `database:install` (`sodium_crypto_aead_chacha20poly1305_ietf_keygen`).
#   - `dependency_injection/services.php` define:
#       kernel.secret = env('default:glpi.default_secret:file:APP_SECRET_FILE')
#     es decir, kernel.secret = **contenido crudo** de `config/glpicrypt.key`.
#   - Si esa clave binaria contiene el byte `%` (0x25), Symfony DI lo interpreta
#     como una referencia a parámetro (`%param%`) al compilar el contenedor y
#     lanza ParameterNotFoundException, rompiendo CUALQUIER `bin/console` que
#     bootee los plugins. Es intermitente (~depende del azar de la clave).
#
# Workaround (sin tocar core): GLPI SÓLO genera la clave si NO existe
#   (`if (!$glpikey->keyExists() && !$glpikey->generate(...))` en el comando de
#   instalación). Por eso PRE-PROVISIONAMOS aquí, ANTES de `database:install`,
#   una clave CRIPTOGRÁFICAMENTE ALEATORIA de EXACTAMENTE 32 bytes (válida para
#   Sodium) rechazando cualquier candidata que contenga `%` ANTES de escribirla.
#   GLPI la respeta y kernel.secret queda libre de `%`.
#
# Reglas: aleatoriedad criptográfica siempre; NUNCA clave estática/legible;
# NUNCA hex/base64 crudos como clave; la clave NO se imprime en logs.
# Ver docs/adr/ADR-0017-glpi-security-key-hardening.md y docs/operations/installation.md.
# =====================================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DOCKER_DIR="$(cd "$HERE/.." && pwd)"        # infra/docker
COMPOSE="docker compose -f $DOCKER_DIR/docker-compose.yml"
ENV_FILE="$DOCKER_DIR/.env"
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && { set -a; . "$ENV_FILE"; set +a; }

KEYFILE="/var/www/glpi/config/glpicrypt.key"

echo ">> Provisionando glpicrypt.key (workaround GLPI 11 / kernel.secret)..."
# Generación DENTRO del contenedor y como www-data (propietario correcto).
# Rejection sampling con CSPRNG: se descarta cualquier candidata con '%' ANTES
# de escribirla (esto NO es "reintentar hasta que CI pase por azar": es muestreo
# de los bytes de la clave, con un tope de seguridad fail-closed). No imprime la clave.
$COMPOSE exec -T -u www-data glpi php -r '
  $f = "/var/www/glpi/config/glpicrypt.key";
  if (is_file($f)) { fwrite(STDERR, "AVISO: glpicrypt.key ya existe; no se sobrescribe\n"); exit(0); }
  $dir = dirname($f);
  if (!is_dir($dir) || !is_writable($dir)) { fwrite(STDERR, "FALLO: config dir no escribible: $dir\n"); exit(1); }
  $n = 0; $k = "";
  do { $k = random_bytes(32); $n++; } while (strpos($k, "%") !== false && $n < 10000);
  if (strpos($k, "%") !== false) { fwrite(STDERR, "FALLO: no se pudo muestrear clave sin %\n"); exit(1); }
  $w = file_put_contents($f, $k, LOCK_EX);
  if ($w !== 32) { fwrite(STDERR, "FALLO: escritura != 32 bytes ($w)\n"); @unlink($f); exit(1); }
  if (!chmod($f, 0640)) { fwrite(STDERR, "FALLO: no se pudo chmod 0640\n"); exit(1); }
  echo "OK: glpicrypt.key provisionada (32 bytes, sin %).\n";
'

echo ">> Verificando la clave provisionada (fail-closed, sin imprimirla)..."
# Verifica: existe, 32 bytes exactos, propietario = usuario actual (www-data),
# sin acceso para "otros", y sin ningún byte '%'. Nunca imprime la clave.
$COMPOSE exec -T -u www-data glpi php -r '
  $f = "/var/www/glpi/config/glpicrypt.key";
  clearstatcache();
  if (!is_file($f)) { fwrite(STDERR, "FALLO: la clave no existe\n"); exit(1); }
  $c = file_get_contents($f);
  $e = [];
  if (strlen($c) !== 32) { $e[] = "tamaño=" . strlen($c) . " (esperado 32)"; }
  if (strpos($c, "%") !== false) { $e[] = "contiene % (rompería kernel.secret)"; }
  if (fileowner($f) !== getmyuid()) { $e[] = "propietario != www-data (uid " . fileowner($f) . ")"; }
  if ((fileperms($f) & 0007) !== 0) { $e[] = sprintf("legible por otros (mode %04o)", fileperms($f) & 0777); }
  if ($e) { fwrite(STDERR, "FALLO: " . implode("; ", $e) . "\n"); exit(1); }
  echo "OK: 32 bytes, www-data, sin acceso 'otros', sin %.\n";
'

echo "OK: glpicrypt.key lista antes de database:install (kernel.secret libre de %)."
