<?php

/**
 * Escenario [UPGRADE] (0.6.1) del selftest OBLIGATORIO `plugins:companyintegrations:selftest`: un upgrade NO re-otorga
 * derechos que un administrador quitó.
 *
 * TRAIT del mismo `SelftestCommand` (no un selftest paralelo que pueda omitirse).
 *
 * Contrato (igual que companyworkflow 0.6.1, companysignature 0.5.1 y companyqr):
 *   - PRIMERA instalación: se crea el derecho y Super-Admin (perfil 4) recibe todos los bits.
 *   - UPGRADE / install() posterior: NO se modifica ningún derecho existente (ya no `actual | todos`).
 *
 * Cobertura:
 *   [UPGRADE]  instalación inicial ⇒ Super-Admin con todos los bits; el administrador recorta Super-Admin
 *              (READ | RIGHT_RECONCILE, SIN RIGHT_SI4) y otro perfil; install() ×2 ⇒ ambos derechos EXACTAMENTE como los
 *              dejó el administrador (Super-Admin sigue SIN RIGHT_SI4), una fila del derecho por perfil, configuración
 *              ajustada preservada, defaults sólo si faltan, sagas SI-4 / mappings / asset_bridge intactos (huella).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\MapCompany;
use GlpiPlugin\Companyintegrations\Service\PluginConfig as IntegrationsConfig;
use GlpiPlugin\Companyintegrations\Si4\DbSagaStore;

trait UpgradeRightsSelftestScenarios
{
    /** Tablas propias cuyo contenido un upgrade NO debe tocar (puentes, mappings, sagas SI-4 y estado operativo). */
    private const UPGRADE_DATA_TABLES = [
        'asset_bridge', 'asset_tag_aliases', 'map_companies', 'map_users', 'recon',
        'si4_sagas', 'si4_saga_log', 'map_models', 'map_glpi_assettypes', 'si4_runtime',
    ];

    // ------------------------------------------------------------------ [UPGRADE]

    private function scenarioUpgradeRights(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [UPGRADE] install() ×2 sobre una instalación existente: los derechos ajustados NO se re-otorgan ==');
        $this->applySession(2, [0], ['config' => ALLSTANDARDRIGHT], 1);
        if (!function_exists('plugin_companyintegrations_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $right = AssetBridge::$rightname;
        $full = READ | AssetBridge::RIGHT_RECONCILE | AssetBridge::RIGHT_MAP | AssetBridge::RIGHT_CONFIG | AssetBridge::RIGHT_SI4;

        // (1) La instalación inicial otorgó todos los bits a Super-Admin; el administrador luego le quita RIGHT_SI4 y más.
        $superOrig = $this->upgradeRightValue(4);
        $this->check('[UPGRADE] precondición: Super-Admin recibió todos los bits en la instalación inicial', $superOrig === $full);
        $superCustom = READ | AssetBridge::RIGHT_RECONCILE;

        // Un perfil que no es Super-Admin, también ajustado por el administrador.
        $otherProfile = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => \Profile::getTable(), 'WHERE' => ['NOT' => ['id' => 4]], 'ORDER' => 'id ASC', 'LIMIT' => 1]) as $row) {
            $otherProfile = (int) $row['id'];
        }
        $otherOrig = $this->upgradeRightValue($otherProfile);
        $otherCustom = READ | AssetBridge::RIGHT_MAP;

        // Configuración: una clave ajustada por el administrador y una clave por defecto AUSENTE.
        $configOrig = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
        $customKey = 'timeout_ms';
        $customValue = '7777';
        $missingKey = 'reconcile_page_size';

        try {
            $DB->update('glpi_profilerights', ['rights' => $superCustom], ['profiles_id' => 4, 'name' => $right]);
            $DB->update('glpi_profilerights', ['rights' => $otherCustom], ['profiles_id' => $otherProfile, 'name' => $right]);
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, [$customKey => $customValue]);
            \Config::deleteConfigurationValues(IntegrationsConfig::CONTEXT, [$missingKey]);
            $this->check('[UPGRADE] administrador recortó Super-Admin (READ | RIGHT_RECONCILE, sin RIGHT_SI4) y otro perfil',
                $this->upgradeRightValue(4) === $superCustom && $otherProfile > 0 && $this->upgradeRightValue($otherProfile) === $otherCustom);

            // Datos que el upgrade debe dejar intactos: un puente, un mapping de compañía y una saga SI-4.
            $seq = 9_100_000 + random_int(0, 99_999) * 10;
            $bridge = (int) (new AssetBridge())->add(['snipe_asset_id' => $seq, 'snipe_asset_tag' => 'UPGR-' . $seq, 'glpi_itemtype' => 'Computer',
                'glpi_items_id' => $seq, 'glpi_entity_id' => 0, 'sync_status' => AssetBridge::STATUS_MATCHED]);
            $company = (int) (new MapCompany())->add(['snipe_company_id' => $seq, 'snipe_name' => 'UPGR', 'glpi_entity_id' => 0, 'is_approved' => 1]);
            $meta = ['entities_id' => 0, 'requests_id' => 1, 'items_id' => 1, 'payload_sha256' => str_repeat('b', 64), 'correlation_id' => 'upg'];
            $saga = (new DbSagaStore())->acquire($this->si4Uuid(), $meta, hash('sha256', 'upg-' . $seq), $this->si4DbTime(60), 1, 'upg');
            $this->check('[UPGRADE] fixture: puente + mapping + saga SI-4 existentes antes del upgrade', $bridge > 0 && $company > 0 && $saga !== null);
            $configBefore = (array) \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
            $fpBefore = $this->si4Fingerprint(self::UPGRADE_DATA_TABLES);
            $profiles = countElementsInTable(\Profile::getTable());

            // (2) Upgrade: GLPI vuelve a llamar install(); un reintento lo llama otra vez.
            $ok = true;
            try {
                plugin_companyintegrations_install();
                plugin_companyintegrations_install();
            } catch (\Throwable $e) {
                $ok = false;
                $this->out->writeln('    ' . $e->getMessage());
            }
            $this->check('[UPGRADE] install() ×2 sin excepción (sin Duplicate entry del derecho)', $ok);

            // (3) Derechos EXACTAMENTE como los dejó el administrador.
            $superAfter = $this->upgradeRightValue(4);
            $this->check(sprintf('[UPGRADE] derecho de Super-Admin recortado PRESERVADO EXACTO (esperado %d, quedó %d; no se re-otorgan bits)', $superCustom, $superAfter),
                $superAfter === $superCustom);
            $this->check('[UPGRADE] 🔒 Super-Admin sin RIGHT_SI4 sigue SIN RIGHT_SI4 tras el upgrade', ($superAfter & AssetBridge::RIGHT_SI4) === 0);
            $this->check('[UPGRADE] derecho personalizado de un perfil no Super-Admin PRESERVADO EXACTO', $this->upgradeRightValue($otherProfile) === $otherCustom);
            $perProfile = [];
            foreach ($DB->request(['SELECT' => ['profiles_id'], 'COUNT' => 'n', 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right], 'GROUPBY' => 'profiles_id']) as $row) {
                $perProfile[(int) $row['profiles_id']] = (int) $row['n'];
            }
            $this->check(sprintf('[UPGRADE] el derecho sigue EXACTAMENTE una vez por perfil (%d perfiles, %d filas)', $profiles, array_sum($perProfile)),
                $perProfile !== [] && count($perProfile) === $profiles && max($perProfile) === 1);

            // (4) Configuración: lo ajustado se conserva; sólo se siembran los defaults ausentes.
            $configAfter = (array) \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
            $preserved = true;
            foreach ($configBefore as $k => $v) {
                $preserved = $preserved && array_key_exists($k, $configAfter) && (string) $configAfter[$k] === (string) $v;
            }
            $this->check('[UPGRADE] configuración existente PRESERVADA (' . count($configBefore) . ' claves, incluida la ajustada)',
                $preserved && (string) ($configAfter[$customKey] ?? '') === $customValue);
            $this->check('[UPGRADE] defaults nuevos sólo si faltan (la clave ausente vuelve con su default)',
                array_key_exists($missingKey, $configAfter) && (string) $configAfter[$missingKey] === IntegrationsConfig::DEFAULTS[$missingKey]);

            // (5) Sagas SI-4, mappings y asset_bridge intactos.
            $this->check('[UPGRADE] sagas SI-4 / mappings / asset_bridge INTACTOS (' . count(self::UPGRADE_DATA_TABLES) . ' tablas propias: sha256)',
                $this->si4Fingerprint(self::UPGRADE_DATA_TABLES) === $fpBefore);
        } finally {
            // Restaurar lo que la prueba cambió (los datos de fixture los borra cleanup()).
            if ($superOrig >= 0) {
                $DB->update('glpi_profilerights', ['rights' => $superOrig], ['profiles_id' => 4, 'name' => $right]);
            }
            if ($otherProfile > 0 && $otherOrig >= 0) {
                $DB->update('glpi_profilerights', ['rights' => $otherOrig], ['profiles_id' => $otherProfile, 'name' => $right]);
            }
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, is_array($configOrig) ? $configOrig : []);
        }
    }

    private function upgradeRightValue(int $profileId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $profileId, 'name' => AssetBridge::$rightname]]) as $row) {
            return (int) $row['rights'];
        }
        return -1;
    }
}
