<?php

/**
 * Tests UNITARIOS puros de companyqr (sin bootstrap de GLPI).
 *
 * Sólo ejercitan lógica que NO depende del core: generación de token, decisión de
 * `public_code`, whitelist/no-fuga y utilidades de etiqueta. Se ejecuta en el job
 * estático de CI:  php plugins/companyqr/tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$service = dirname(__DIR__, 2) . '/src/Service/';
require $service . 'TokenGenerator.php';
require $service . 'AssetResolver.php';
require $service . 'CodeManager.php';
require $service . 'LabelRenderer.php';
require $service . 'AltchaVerifier.php';
require $service . 'ScanUrl.php';
require $service . 'LabelComposer.php';
require dirname(__DIR__, 2) . '/src/Api/CompanyQrException.php';
require dirname(__DIR__, 2) . '/src/Api/CompanyQrApi.php';

use GlpiPlugin\Companyqr\Api\CompanyQrApi;
use GlpiPlugin\Companyqr\Api\CompanyQrException;
use GlpiPlugin\Companyqr\Service\AltchaVerifier;
use GlpiPlugin\Companyqr\Service\LabelComposer;
use GlpiPlugin\Companyqr\Service\ScanUrl;
use GlpiPlugin\Companyqr\Service\AssetResolver;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\LabelRenderer;
use GlpiPlugin\Companyqr\Service\TokenGenerator;

$fail = 0;
$total = 0;
function ok(string $label, bool $cond): void
{
    global $fail, $total;
    $total++;
    echo ($cond ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}

echo "== TokenGenerator ==\n";
$tg = new TokenGenerator();
$t = $tg->generate();
ok('token en alfabeto base32 [a-z2-7]', preg_match('/^[a-z2-7]+$/', $t) === 1);
ok('token con longitud >= 24', strlen($t) >= 24);
ok('token es "well formed"', TokenGenerator::isWellFormed($t));
ok('cadena corta/ilegal NO es well formed', !TokenGenerator::isWellFormed('abc!'));
$seen = [];
for ($i = 0; $i < 2000; $i++) {
    $seen[$tg->generate()] = true;
}
ok('2000 tokens generados son únicos', count($seen) === 2000);

echo "== CodeManager::decidePublicCodeSource (puro) ==\n";
ok('otherserial válido y libre → otherserial',
    CodeManager::decidePublicCodeSource('NB-001245', true) === 'otherserial');
ok('otherserial vacío → generate',
    CodeManager::decidePublicCodeSource('', true) === 'generate');
ok('otherserial duplicado → generate',
    CodeManager::decidePublicCodeSource('NB-1', false) === 'generate');
ok('otherserial sólo espacios → generate',
    CodeManager::decidePublicCodeSource('   ', true) === 'generate');

echo "== AssetResolver (whitelist / no-fuga) ==\n";
$inter = array_values(array_intersect(AssetResolver::SAFE_FIELDS, AssetResolver::FORBIDDEN_FIELDS));
ok('SAFE_FIELDS ∩ FORBIDDEN_FIELDS = ∅', $inter === []);
$stripped = AssetResolver::stripForbidden([
    'name' => 'x', 'ip' => '10.0.0.1', 'mac' => 'aa:bb', 'public_code' => 'NB-1', 'users_id' => 5,
]);
ok('stripForbidden elimina ip/mac/users_id',
    !isset($stripped['ip'], $stripped['mac'], $stripped['users_id']));
ok('stripForbidden conserva name/public_code',
    isset($stripped['name'], $stripped['public_code']));
ok('ningún SAFE_FIELD contiene una subcadena prohibida', (function (): bool {
    foreach (AssetResolver::SAFE_FIELDS as $safe) {
        foreach (AssetResolver::FORBIDDEN_FIELDS as $bad) {
            if (stripos($safe, $bad) !== false) {
                return false;
            }
        }
    }
    return true;
})());

echo "== LabelRenderer::hexToRgb ==\n";
ok('#f7e300 → [247,227,0]', LabelRenderer::hexToRgb('#f7e300') === [247, 227, 0]);
ok('valor inválido → amarillo por defecto', LabelRenderer::hexToRgb('zzz') === [247, 227, 0]);

echo "== AltchaVerifier (casos negativos deterministas) ==\n";
$av = new AltchaVerifier();
ok('payload vacío → rechazado', $av->isValid('') === false);
ok('payload en blanco → rechazado', $av->isValid('   ') === false);
ok('payload ilegible sin AltchaManager → rechazado', $av->isValid('!!not-base64!!') === false);

echo "== API pública (SI4-3, ADR-0022): URL del QR, etiqueta y metadatos sin token ==\n";
$tok = (new TokenGenerator())->generate();
ok('ScanUrl: ruta AUTENTICADA estándar /plugins/companyqr/scan/{token}', ScanUrl::forToken($tok, 'https://glpi.test') === 'https://glpi.test/plugins/companyqr/scan/' . $tok);
ok('ScanUrl: el token se codifica para URL (nada se interpreta como ruta)', ScanUrl::forToken('a/b?c', '') === '/plugins/companyqr/scan/a%2Fb%3Fc');
ok('LabelComposer: sólo código visible, tipo, URL del QR, encabezado y organización', LabelComposer::KEYS === ['public_code', 'type', 'qr_data', 'header', 'org']);
ok('🔒 la etiqueta no lleva campos prohibidos (IP, MAC, hostname, serial, VLAN…)', (function (): bool {
    foreach (LabelComposer::KEYS as $k) {
        foreach (AssetResolver::FORBIDDEN_FIELDS as $bad) {
            if (stripos($k, $bad) !== false) {
                return false;
            }
        }
    }
    return true;
})());
ok('🔒 CompanyQrApi::META_KEYS no incluye el token', !in_array('token', CompanyQrApi::META_KEYS, true)
    && CompanyQrApi::META_KEYS === ['code_id', 'public_code', 'status', 'itemtype', 'items_id', 'entities_id']);
ok('CompanyQrException tipado (acl/not_found/inactive/render/invalid)', (new CompanyQrException(CompanyQrException::ACL, 'x'))->kind === 'acl'
    && CompanyQrException::INACTIVE === 'inactive' && CompanyQrException::RENDER === 'render');
$api = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Api/CompanyQrApi.php');
$ctl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controller/LabelController.php');
ok('🔒 la API reutiliza getOrCreateForItem() y LabelRenderer::pdf() (sin otro sistema de QR ni renderer)', str_contains($api, '->getOrCreateForItem(')
    && str_contains($api, '->pdf(LabelComposer::spec(') && str_contains($ctl, '->pdf(LabelComposer::spec('));
ok('🔒 la URL del QR sólo se arma en ScanUrl (la API y el controlador no la concatenan)', !str_contains($api, '/plugins/companyqr/scan') && !str_contains($ctl, '/plugins/companyqr/scan')
    && !str_contains($api, "fields['token']") && !str_contains($api, '->rotate(') && !str_contains($api, '->revoke(') && !str_contains($api, 'setStatus('));

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
