<?php

/**
 * glpi-kernel-secret-repro.php — Reproducción DETERMINISTA del bug upstream
 * de GLPI 11 en `kernel.secret`, y demostración del fix.
 *
 * Mecanismo (confirmado contra GLPI 11.0.8):
 *   - kernel.secret = contenido crudo de `config/glpicrypt.key` (32 bytes).
 *   - Symfony DI, al compilar el contenedor, resuelve `%...%` como referencias
 *     a parámetros (ResolveParameterPlaceHoldersPass). Si el secreto contiene
 *     `%`, intenta resolver `%param%` -> ParameterNotFoundException.
 *
 * Este test NO toca el core: sólo ejercita el MISMO componente
 * (symfony/dependency-injection, vía el vendor del contenedor GLPI) para
 * demostrar de forma determinista:
 *   - un kernel.secret con `%...%` ROMPE compile()  (reproduce el bug);
 *   - un kernel.secret SIN `%` compila correctamente (demuestra el fix).
 *
 * Ejecución (dentro del contenedor GLPI, que tiene el vendor de Symfony):
 *   docker compose exec -T glpi php < tests/security/glpi-kernel-secret-repro.php
 *
 * Fail-closed: exit != 0 si el bug no se reproduce o si un secreto sano falla.
 */

declare(strict_types=1);

require '/var/www/glpi/vendor/autoload.php';

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;

/**
 * Compila un contenedor con el kernel.secret dado.
 * Devuelve null si compiló OK, o el mensaje de ParameterNotFoundException.
 */
function compileWithSecret(string $secret): ?string
{
    $c = new ContainerBuilder();
    $c->setParameter('kernel.secret', $secret);
    try {
        $c->compile();
        return null;
    } catch (ParameterNotFoundException $e) {
        return 'ParameterNotFoundException: ' . $e->getMessage();
    }
}

$failures = 0;

// 1) NEGATIVO (texto): un secreto con patrón %algo% DEBE romper compile().
$r = compileWithSecret('A%glpi_missing_param%B');
if ($r === null) {
    fwrite(STDERR, "[NEG] FALLO: kernel.secret con '%...%' compiló sin error (no se reprodujo el bug).\n");
    $failures++;
} else {
    echo "[NEG] OK: kernel.secret con '%...%' rompe compile() -> {$r}\n";
}

// 2) NEGATIVO (binario 32 bytes): como una glpicrypt.key mala con '%'.
$badKey = '%glpi_x%' . str_repeat("\x01", 24); // 8 + 24 = 32 bytes, contiene %glpi_x%
if (strlen($badKey) !== 32) {
    fwrite(STDERR, "[NEG-BIN] FALLO: fixture no mide 32 bytes\n");
    $failures++;
} elseif (compileWithSecret($badKey) === null) {
    fwrite(STDERR, "[NEG-BIN] FALLO: clave binaria de 32 bytes con '%' no rompió compile().\n");
    $failures++;
} else {
    echo "[NEG-BIN] OK: clave binaria de 32 bytes con '%' rompe compile().\n";
}

// 3) POSITIVO (texto): un secreto SIN '%' compila sin error (fix).
if (compileWithSecret(bin2hex(random_bytes(16))) !== null) {
    fwrite(STDERR, "[POS] FALLO: un kernel.secret sin '%' no debería romper compile().\n");
    $failures++;
} else {
    echo "[POS] OK: kernel.secret sin '%' compila correctamente.\n";
}

// 4) POSITIVO (binario 32 bytes): como la provisión real (CSPRNG + rechazo de '%').
$n = 0;
$k = '';
do {
    $k = random_bytes(32);
    $n++;
} while (strpos($k, '%') !== false && $n < 10000);
if (strpos($k, '%') !== false) {
    fwrite(STDERR, "[POS-BIN] FALLO: no se pudo muestrear una clave sin '%'.\n");
    $failures++;
} elseif (strlen($k) !== 32) {
    fwrite(STDERR, "[POS-BIN] FALLO: la clave muestreada no mide 32 bytes.\n");
    $failures++;
} elseif (compileWithSecret($k) !== null) {
    fwrite(STDERR, "[POS-BIN] FALLO: una clave de 32 bytes sin '%' rompió compile().\n");
    $failures++;
} else {
    echo "[POS-BIN] OK: clave de 32 bytes sin '%' compila correctamente.\n";
}

if ($failures > 0) {
    fwrite(STDERR, "glpi-kernel-secret-repro: FALLÓ ({$failures})\n");
    exit(1);
}
echo "glpi-kernel-secret-repro: OK\n";
