<?php

/**
 * Escenario [UPGRADE] (0.5.1) del selftest OBLIGATORIO `plugins:companypurchasing:selftest`: un upgrade NO re-otorga
 * derechos que un administrador quitó.
 *
 * TRAIT del mismo `SelftestCommand` (no un selftest paralelo que pueda omitirse). Corre al final del circuito P2D-2/3/4,
 * con los datos de negocio de los escenarios anteriores todavía presentes.
 *
 * Contrato (igual que companyworkflow 0.6.1, companysignature 0.5.1 y companyqr):
 *   - PRIMERA instalación: se crea el derecho y Super-Admin (perfil 4) recibe todos los bits.
 *   - UPGRADE / install() posterior: NO se modifica ningún derecho existente (tampoco se suman bits nuevos).
 *
 * Cobertura:
 *   [UPGRADE]  instalación inicial ⇒ Super-Admin con todos los bits; el administrador recorta Super-Admin
 *              (READ | VIEW_OWN | RECEIVE) y otro perfil; install() ×2 ⇒ ambos derechos EXACTAMENTE como los dejó el
 *              administrador, una fila del derecho por perfil, configuración ajustada preservada, defaults sólo si
 *              faltan, Acción automática preservada y única, tablas/datos de Compras + motor + Firma intactos (huella).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use GlpiPlugin\Companypurchasing\Model\ProjectionTask;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;

trait UpgradeRightsSelftestScenarios
{
    /** Tablas propias cuyo contenido un upgrade NO debe tocar. */
    private const UPGRADE_DATA_TABLES = [
        'requests', 'items', 'numbering', 'events', 'scope_defs', 'quotes', 'quote_items', 'doc_versions', 'docseq', 'policies',
        'integrity', 'receipt_batches', 'receipt_units', 'inventory_outbox', 'cost_policies', 'delivery_batches',
    ];

    // ================================================================ [UPGRADE]

    private function scenarioUpgradeRights(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [UPGRADE] install() ×2 sobre una instalación existente: los derechos ajustados NO se re-otorgan ==');
        $this->asAdmin();
        if (!function_exists('plugin_companypurchasing_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $right = Request::$rightname;
        $full = READ
            | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
            | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG
            | Request::RIGHT_MANAGE_PURCHASING | Request::RIGHT_RECEIVE | Request::RIGHT_DELIVER
            | Request::RIGHT_VIEW_METRICS | Request::RIGHT_INTEGRATION;

        // (1) La instalación inicial otorgó todos los bits a Super-Admin; el administrador luego se los RECORTA.
        $superOrig = $this->profileRightValue(4);
        $this->check('[UPGRADE] precondición: Super-Admin recibió todos los bits en la instalación inicial', $superOrig === $full);
        $superCustom = READ | Request::RIGHT_VIEW_OWN | Request::RIGHT_RECEIVE;

        // Un perfil que no es Super-Admin, también ajustado por el administrador.
        $otherProfile = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => \Profile::getTable(), 'WHERE' => ['NOT' => ['id' => 4]], 'ORDER' => 'id ASC', 'LIMIT' => 1]) as $row) {
            $otherProfile = (int) $row['id'];
        }
        $otherOrig = $this->profileRightValue($otherProfile);
        $otherCustom = READ | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN;

        // Configuración: una clave ajustada por el administrador y una clave por defecto AUSENTE.
        $configOrig = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
        $customKey = 'quorum_finance';
        $customValue = '3';
        $missingKey = 'cost_include_freight';

        // Acción automática con frecuencia ajustada por el administrador.
        $cron = new \CronTask();
        $cronId = $cron->getFromDBbyName(ProjectionTask::class, ProjectionTask::CRON_NAME) ? (int) $cron->getID() : 0;
        $cronFreqOrig = (int) ($cron->fields['frequency'] ?? 900);

        try {
            $DB->update('glpi_profilerights', ['rights' => $superCustom], ['profiles_id' => 4, 'name' => $right]);
            $DB->update('glpi_profilerights', ['rights' => $otherCustom], ['profiles_id' => $otherProfile, 'name' => $right]);
            \Config::setConfigurationValues(PluginConfig::CONTEXT, [$customKey => $customValue]);
            \Config::deleteConfigurationValues(PluginConfig::CONTEXT, [$missingKey]);
            if ($cronId > 0) {
                (new \CronTask())->update(['id' => $cronId, 'frequency' => 7200]);
            }
            $this->check('[UPGRADE] administrador recortó Super-Admin (READ | VIEW_OWN | RECEIVE) y otro perfil',
                $this->profileRightValue(4) === $superCustom && $otherProfile > 0 && $this->profileRightValue($otherProfile) === $otherCustom);
            $configBefore = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
            $fpBefore = $this->upgradeDataFingerprint();
            $notifications = countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class]);
            $profiles = countElementsInTable(\Profile::getTable());

            // (2) Upgrade: GLPI vuelve a llamar install(); un reintento lo llama otra vez.
            $threw = $this->throws(function (): void {
                plugin_companypurchasing_install();
                plugin_companypurchasing_install();
            });
            $this->check('[UPGRADE] install() ×2 sin excepción (sin Duplicate entry del derecho)', !$threw);

            // (3) Derechos EXACTAMENTE como los dejó el administrador.
            $superAfter = $this->profileRightValue(4);
            $this->check(sprintf('[UPGRADE] derecho de Super-Admin recortado PRESERVADO EXACTO (esperado %d, quedó %d; no se re-otorgan bits)', $superCustom, $superAfter),
                $superAfter === $superCustom);
            $this->check('[UPGRADE] derecho personalizado de un perfil no Super-Admin PRESERVADO EXACTO', $this->profileRightValue($otherProfile) === $otherCustom);
            $perProfile = [];
            foreach ($DB->request(['SELECT' => ['profiles_id'], 'COUNT' => 'n', 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => $right], 'GROUPBY' => 'profiles_id']) as $row) {
                $perProfile[(int) $row['profiles_id']] = (int) $row['n'];
            }
            $this->check(sprintf('[UPGRADE] el derecho sigue EXACTAMENTE una vez por perfil (%d perfiles, %d filas)', $profiles, array_sum($perProfile)),
                $perProfile !== [] && count($perProfile) === $profiles && max($perProfile) === 1);

            // (4) Configuración: lo ajustado se conserva; sólo se siembran los defaults ausentes.
            $configAfter = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
            $preserved = true;
            foreach ($configBefore as $k => $v) {
                $preserved = $preserved && array_key_exists($k, $configAfter) && (string) $configAfter[$k] === (string) $v;
            }
            $this->check('[UPGRADE] configuración existente PRESERVADA (' . count($configBefore) . ' claves, incluida la ajustada)',
                $preserved && (string) ($configAfter[$customKey] ?? '') === $customValue);
            $this->check('[UPGRADE] defaults nuevos sólo si faltan (la clave ausente vuelve con su default)',
                array_key_exists($missingKey, $configAfter) && (string) $configAfter[$missingKey] === PluginConfig::DEFAULTS[$missingKey]);

            // (5) Acción automática preservada y no duplicada; notificaciones nativas sin duplicar.
            $cron2 = new \CronTask();
            $this->check('[UPGRADE] Acción automática única, mismo id y frecuencia preservada',
                countElementsInTable(\CronTask::getTable(), ['itemtype' => ProjectionTask::class]) === 1
                && $cron2->getFromDBbyName(ProjectionTask::class, ProjectionTask::CRON_NAME)
                && (int) $cron2->getID() === $cronId && (int) $cron2->fields['frequency'] === 7200);
            $this->check('[UPGRADE] notificaciones NATIVAS sin duplicar', countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class]) === $notifications);

            // (6) Datos de negocio intactos.
            $this->check('[UPGRADE] tablas/datos de Compras + motor + Firma INTACTOS (' . count(self::UPGRADE_DATA_TABLES) . ' tablas propias: conteo + sha256)',
                $this->upgradeDataFingerprint() === $fpBefore);
        } finally {
            // Restaurar lo que la prueba cambió.
            if ($superOrig >= 0) {
                $DB->update('glpi_profilerights', ['rights' => $superOrig], ['profiles_id' => 4, 'name' => $right]);
            }
            if ($otherProfile > 0 && $otherOrig >= 0) {
                $DB->update('glpi_profilerights', ['rights' => $otherOrig], ['profiles_id' => $otherProfile, 'name' => $right]);
            }
            \Config::setConfigurationValues(PluginConfig::CONTEXT, [
                $customKey  => (string) ($configOrig[$customKey] ?? PluginConfig::DEFAULTS[$customKey]),
                $missingKey => (string) ($configOrig[$missingKey] ?? PluginConfig::DEFAULTS[$missingKey]),
            ]);
            if ($cronId > 0) {
                (new \CronTask())->update(['id' => $cronId, 'frequency' => $cronFreqOrig]);
            }
        }
    }

    /** Huella (conteo + sha256) de TODAS las tablas propias + motor + Firma de las solicitudes. @return array<string,string> */
    private function upgradeDataFingerprint(): array
    {
        $out = [];
        foreach (self::UPGRADE_DATA_TABLES as $t) {
            $out[$t] = $this->tableHash('glpi_plugin_companypurchasing_' . $t, [], []);
        }
        $out['wf_instances'] = $this->tableHash(Instance::getTable(), ['itemtype' => Request::class], []);
        $out['wf_history'] = $this->tableHash(HistoryEvent::getTable(), [], []);
        $out['sig_versions'] = $this->tableHash(DocumentVersion::getTable(), ['subject_itemtype' => Request::class], []);
        $out['sig_evidences'] = $this->tableHash(ApprovalEvidence::getTable(), ['subject_itemtype' => Request::class], []);
        return $out;
    }
}
