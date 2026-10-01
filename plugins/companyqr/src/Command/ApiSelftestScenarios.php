<?php

/**
 * Escenarios de SELFTEST de la API pública `CompanyQrApi` (SI4-3, ADR-0022) sobre GLPI 11.0.8 REAL:
 *
 *   [API-ENSURE]   get-or-create idempotente (mismo código, outcome existing), public_code = número de inventario,
 *                  metadatos = whitelist exacta (sin token)
 *   [API-RACE]     carrera por el MISMO activo (UNIQUE item): createForItem() devuelve el código existente; nunca dos
 *   [API-ACL]      sin RIGHT_GENERATE / sin RIGHT_PRINT / activo de otra entidad ⇒ CompanyQrException(acl), sin crear
 *   [API-LABEL]    PDF real con el renderer existente: válido, con el public_code, sin serial/nombre/IP; la URL del QR es
 *                  la ruta autenticada /plugins/companyqr/scan/{token} armada por companyqr (ScanUrl)
 *   [API-NO-MUTATE] un código revocado se informa tal cual: ni se rota ni se reactiva; su etiqueta no se imprime
 *   [API-UPGRADE]  install() ×2 (0.2.0 → 0.3.0): códigos, configuración ajustada y derechos intactos
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Command;

use Computer;
use GlpiPlugin\Companyqr\Api\CompanyQrApi;
use GlpiPlugin\Companyqr\Api\CompanyQrException;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\LabelComposer;
use GlpiPlugin\Companyqr\Service\PluginConfig;
use GlpiPlugin\Companyqr\Service\ScanUrl;

trait ApiSelftestScenarios
{
    /** @var array<int,int> activos creados por estos escenarios */
    private array $apiComputers = [];

    private function runApiScenarios(int $entityA, int $entityB, string $suffix): void
    {
        try {
            $this->apiEnsureAndRace($entityA, $entityB, $suffix);
            $this->apiUpgrade();
        } catch (\Throwable $e) {
            $this->check('[API] sin excepciones: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
        } finally {
            $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT, Code::$rightname => ALLSTANDARDRIGHT | 14]);
            foreach ($this->apiComputers as $id) {
                $c = new Code();
                if ($c->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $id])) {
                    $c->delete(['id' => $c->getID()], true);
                }
                (new Computer())->delete(['id' => $id], true);
            }
        }
    }

    private function apiEnsureAndRace(int $entityA, int $entityB, string $suffix): void
    {
        $this->out->writeln('== [API] CompanyQrApi: get-or-create, ACL, etiqueta, sin token ==');
        $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);
        $serial = 'SER-API-' . $suffix;
        $name = 'QR-API-ASSET-' . $suffix;
        $inv = 'API-' . $suffix;
        $pcId = (int) (new Computer())->add(['name' => $name, 'entities_id' => $entityB, 'otherserial' => $inv, 'serial' => $serial]);
        $pc2 = (int) (new Computer())->add(['name' => $name . '-2', 'entities_id' => $entityB, 'otherserial' => $inv . '-2']);
        $this->apiComputers = [$pcId, $pc2];
        $count = static fn (int $id): int => countElementsInTable(Code::getTable(), ['itemtype' => 'Computer', 'items_id' => $id]);
        $api = new CompanyQrApi();
        $worker = ['computer' => READ, Code::$rightname => Code::RIGHT_GENERATE | Code::RIGHT_PRINT];

        // [API-ACL] antes de crear nada.
        $this->applySession(103, [$entityB], ['computer' => READ, Code::$rightname => Code::RIGHT_PRINT]);
        $this->check('[API-ACL] 🔒 sin RIGHT_GENERATE ⇒ acl y ningún código creado', $this->apiKind(fn () => $api->ensureForItem('Computer', $pcId)) === CompanyQrException::ACL
            && $count($pcId) === 0);
        $this->applySession(101, [$entityA], $worker);
        $this->check('[API-ACL] 🔒 activo de OTRA entidad (canViewItem nativo) ⇒ acl y ningún código creado', $this->apiKind(fn () => $api->ensureForItem('Computer', $pcId)) === CompanyQrException::ACL
            && $count($pcId) === 0);
        $this->check('[API-ACL] activo inexistente / itemtype inválido ⇒ not_found / invalid', $this->apiKind(fn () => $api->ensureForItem('Computer', 999999999)) === CompanyQrException::NOT_FOUND
            && $this->apiKind(fn () => $api->ensureForItem('NoExiste', 1)) === CompanyQrException::INVALID);

        // [API-ENSURE]
        $this->applySession(102, [$entityB], $worker);
        $m1 = $api->ensureForItem('Computer', $pcId);
        $m2 = $api->ensureForItem('Computer', $pcId);
        $this->check('[API-ENSURE] crea el código: ACTIVO, public_code = número de inventario, entidad del activo', $m1['outcome'] === CompanyQrApi::OUTCOME_CREATED
            && $m1['status'] === Code::STATUS_ACTIVE && $m1['public_code'] === $inv && $m1['entities_id'] === $entityB && $m1['itemtype'] === 'Computer' && $m1['items_id'] === $pcId);
        $this->check('[API-ENSURE] idempotente: misma llamada ⇒ MISMO código (outcome existing), uno solo', $m2['code_id'] === $m1['code_id']
            && $m2['outcome'] === CompanyQrApi::OUTCOME_EXISTING && $count($pcId) === 1);
        $code = new Code();
        $code->getFromDB($m1['code_id']);
        $token = (string) $code->fields['token'];
        $this->check('[API-ENSURE] 🔒 metadatos = whitelist exacta (sin token)', array_keys($m1) === array_merge(CompanyQrApi::META_KEYS, ['outcome'])
            && !str_contains((string) json_encode([$m1, $m2, $api->getCode($m1['code_id']), $api->findForItem('Computer', $pcId)]), $token) && $token !== '');
        $this->check('[API-ENSURE] getCode / findForItem: mismos metadatos; null si no hay código', $api->getCode($m1['code_id']) === array_diff_key($m1, ['outcome' => 1])
            && $api->findForItem('Computer', $pc2) === null && $api->getCode(999999999) === null);

        // [API-RACE] otro proceso ya creó el código del activo entre "buscar" y "crear".
        $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);
        $pcItem = new Computer();
        $pcItem->getFromDB($pcId);
        $raced = (new CodeManager())->createForItem($pcItem);
        $this->check('[API-RACE] 🔒 crear con el código ya existente (UNIQUE item) ⇒ devuelve ESE código; nunca dos', (int) $raced->getID() === $m1['code_id']
            && $count($pcId) === 1 && (string) $raced->fields['token'] === $token);

        // [API-LABEL]
        $this->applySession(102, [$entityB], ['computer' => READ, Code::$rightname => Code::RIGHT_GENERATE]);
        $this->check('[API-LABEL] 🔒 sin RIGHT_PRINT ⇒ acl', $this->apiKind(fn () => $api->renderLabelPdf($m1['code_id'])) === CompanyQrException::ACL);
        $this->applySession(101, [$entityA], $worker);
        $this->check('[API-LABEL] 🔒 activo de otra entidad ⇒ acl (no se imprime)', $this->apiKind(fn () => $api->renderLabelPdf($m1['code_id'])) === CompanyQrException::ACL);
        $this->applySession(102, [$entityB], $worker);
        $pdf = $api->renderLabelPdf($m1['code_id']);
        $text = $this->apiPdfText($pdf);
        $this->check('[API-LABEL] PDF real (renderer existente): no vacío, %PDF- … %%EOF (' . strlen($pdf) . ' bytes)', strlen($pdf) > 1000
            && str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -1024), '%%EOF'));
        $this->check('[API-LABEL] la etiqueta muestra el public_code', str_contains($text, $inv));
        $this->check('[API-LABEL] 🔒 sin serial, nombre, token ni datos técnicos en la etiqueta', !str_contains($text, $serial) && !str_contains($text, $name)
            && !str_contains($text, $token) && !str_contains($pdf, $serial) && !str_contains($pdf, $token));
        $spec = LabelComposer::spec($code, $pcItem);
        $this->check('[API-LABEL] 🔒 el QR codifica la ruta AUTENTICADA de companyqr con el token opaco (ScanUrl)', array_keys($spec) === LabelComposer::KEYS
            && $spec['qr_data'] === ScanUrl::forToken($token) && str_ends_with($spec['qr_data'], '/plugins/companyqr/scan/' . $token) && $spec['public_code'] === $inv);
        $this->check('[API-LABEL] 🔒 nunca sólo el código visible en el QR (no es autorización)', $spec['qr_data'] !== $inv && !str_ends_with($spec['qr_data'], '/' . $inv));

        // [API-NO-MUTATE]
        $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);
        (new CodeManager())->revoke($code, 'selftest api');
        $this->applySession(102, [$entityB], $worker);
        $m3 = $api->ensureForItem('Computer', $pcId);
        $after = new Code();
        $after->getFromDB($m1['code_id']);
        $this->check('[API-NO-MUTATE] 🔒 código revocado: ensureForItem lo informa (revoked) y NO lo rota ni reactiva', $m3['code_id'] === $m1['code_id']
            && $m3['status'] === Code::STATUS_REVOKED && (string) $after->fields['status'] === Code::STATUS_REVOKED && (string) $after->fields['token'] === $token
            && $count($pcId) === 1);
        $this->check('[API-NO-MUTATE] 🔒 código revocado ⇒ su etiqueta no se imprime (inactive)', $this->apiKind(fn () => $api->renderLabelPdf($m1['code_id'])) === CompanyQrException::INACTIVE);
    }

    private function apiUpgrade(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [API-UPGRADE] install() ×2 (0.2.0 → 0.3.0) ==');
        $this->applySession(2, [0], ['config' => ALLSTANDARDRIGHT]);
        if (!function_exists('plugin_companyqr_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $saved = \Config::getConfigurationValues(PluginConfig::CONTEXT);
        try {
            \Config::setConfigurationValues(PluginConfig::CONTEXT, ['label_header' => 'KEEP • HEADER', 'label_size' => '60x20']);
            \Config::deleteConfigurationValues(PluginConfig::CONTEXT, ['scan_retention_months']);
            $fp = static function (string $table, array $where = []) use ($DB): string {
                $acc = '';
                foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => 'id ASC']) as $r) {
                    $acc .= json_encode($r);
                }
                return hash('sha256', $acc);
            };
            $codes = $fp(Code::getTable());
            $rights = $fp('glpi_profilerights', ['name' => Code::$rightname]);
            $ok = true;
            try {
                plugin_companyqr_install();
                plugin_companyqr_install();
            } catch (\Throwable $e) {
                $ok = false;
                $this->out->writeln('    ' . $e->getMessage());
            }
            $conf = \Config::getConfigurationValues(PluginConfig::CONTEXT);
            $this->check('[API-UPGRADE] install() ×2 sobre una instalación existente no falla', $ok);
            $this->check('[API-UPGRADE] 🔒 códigos existentes intactos (huella de la tabla)', $codes === $fp(Code::getTable()));
            $this->check('[API-UPGRADE] 🔒 derechos existentes intactos (sin filas nuevas ni bits cambiados)', $rights === $fp('glpi_profilerights', ['name' => Code::$rightname]));
            $this->check('[API-UPGRADE] configuración ajustada preservada; sólo se siembran claves ausentes', ($conf['label_header'] ?? '') === 'KEEP • HEADER'
                && ($conf['label_size'] ?? '') === '60x20' && ($conf['scan_retention_months'] ?? '') === PluginConfig::DEFAULTS['scan_retention_months']);
        } finally {
            \Config::setConfigurationValues(PluginConfig::CONTEXT, is_array($saved) ? $saved : []);
        }
    }

    /** Tipo de CompanyQrException lanzada por `$fn` ('' si no lanzó). */
    private function apiKind(callable $fn): string
    {
        try {
            $fn();
        } catch (CompanyQrException $e) {
            return $e->kind;
        }
        return '';
    }

    /** Texto de los flujos del PDF (descomprimidos si vienen con FlateDecode). */
    private function apiPdfText(string $pdf): string
    {
        $out = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
            foreach ($m[1] as $raw) {
                $dec = @gzuncompress($raw);
                $out .= ($dec !== false ? $dec : $raw) . "\n";
            }
        }
        return $out;
    }
}
