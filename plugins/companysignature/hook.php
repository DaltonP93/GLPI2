<?php
/**
 * Hooks de ciclo de vida de Company Signature (companysignature).
 *
 * install()/uninstall() usan MIGRACIONES REVERSIBLES con prefijo propio de tabla
 * (glpi_plugin_companysignature_*). NO se accede por SQL directo a tablas del core para saltar
 * reglas de negocio (ver CLAUDE.md, Prohibiciones). El único write sobre una tabla core es otorgar
 * el propio derecho del plugin al perfil Super-Admin (patrón estándar de plugins), vía ProfileRight.
 *
 * Tablas propias (ambas APPEND-ONLY en su parte probatoria):
 *   - glpi_plugin_companysignature_document_versions : versión inmutable (canonical + content_sha256).
 *   - glpi_plugin_companysignature_evidences         : evidencia de aprobación/invalidación.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Service\PluginConfig;
use GlpiPlugin\Companysignature\Service\WorkflowEventListener;

/**
 * Instalación: crea tablas propias, derechos y configuración por defecto.
 * @return boolean
 */
function plugin_companysignature_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    if (!$DB->tableExists('glpi_plugin_companysignature_document_versions')) {
        $sql = "CREATE TABLE `glpi_plugin_companysignature_document_versions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `subject_itemtype` VARCHAR(100) NOT NULL,
            `subject_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `schema_id` VARCHAR(190) NOT NULL DEFAULT '',
            `canonical_snapshot` LONGTEXT NOT NULL,
            `content_sha256` CHAR(64) NOT NULL DEFAULT '',
            `pdf_sha256` CHAR(64) DEFAULT NULL,
            `documents_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `pdf_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `is_substantive` TINYINT NOT NULL DEFAULT 1,
            `users_id_author` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `subject_version` (`subject_itemtype`,`subject_items_id`,`version`),
            KEY `entities_id` (`entities_id`),
            KEY `content_sha256` (`content_sha256`),
            KEY `documents_id` (`documents_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($sql);
    }

    if (!$DB->tableExists('glpi_plugin_companysignature_evidences')) {
        $sql = "CREATE TABLE `glpi_plugin_companysignature_evidences` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `verification_token` VARCHAR(64) NOT NULL,
            `idempotency_key` CHAR(64) NOT NULL,
            `workflow_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `workflow_history_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `workflow_event_ref` VARCHAR(190) NOT NULL DEFAULT '',
            `subject_itemtype` VARCHAR(100) NOT NULL,
            `subject_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `document_versions_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `document_version` INT UNSIGNED NOT NULL DEFAULT 0,
            `content_sha256` CHAR(64) NOT NULL DEFAULT '',
            `actor_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `actor_role` VARCHAR(190) NOT NULL DEFAULT '',
            `actor_context` LONGTEXT DEFAULT NULL,
            `decision` VARCHAR(20) NOT NULL DEFAULT '',
            `event_type` VARCHAR(30) NOT NULL DEFAULT '',
            `comment` TEXT DEFAULT NULL,
            `references_evidences_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `event_date` TIMESTAMP NULL DEFAULT NULL,
            `materialized_at` TIMESTAMP NULL DEFAULT NULL,
            `presentation_timezone` VARCHAR(64) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `verification_token` (`verification_token`),
            UNIQUE KEY `idempotency_key` (`idempotency_key`),
            KEY `entities_id` (`entities_id`),
            KEY `subject` (`subject_itemtype`,`subject_items_id`),
            KEY `workflow_instances_id` (`workflow_instances_id`),
            KEY `workflow_history_id` (`workflow_history_id`),
            KEY `references_evidences_id` (`references_evidences_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($sql);
    }

    // Cola DURABLE de reconciliación (§3): estado propio por evento de ledger; el harvest encola y
    // el worker procesa con reintentos. UNIQUE(workflow_history_id) ⇒ idempotente.
    if (!$DB->tableExists('glpi_plugin_companysignature_reconcile_queue')) {
        $sql = "CREATE TABLE `glpi_plugin_companysignature_reconcile_queue` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflow_history_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `event` VARCHAR(60) NOT NULL DEFAULT '',
            `instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(255) DEFAULT NULL,
            `next_retry_at` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `workflow_history_id` (`workflow_history_id`),
            KEY `status` (`status`),
            KEY `next_retry_at` (`next_retry_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($sql);
    }

    // Derecho propio del plugin en todos los perfiles (valor 0 por defecto).
    if (class_exists('ProfileRight')) {
        ProfileRight::addProfileRights(['plugin_companysignature']);
    }

    // Otorgar todos los bits al perfil Super-Admin (id 4 por defecto en GLPI).
    $full = READ | ApprovalEvidence::RIGHT_RECORD | ApprovalEvidence::RIGHT_VERIFY | ApprovalEvidence::RIGHT_CONFIG;
    $DB->update(
        'glpi_profilerights',
        ['rights' => $full],
        ['profiles_id' => 4, 'name' => 'plugin_companysignature']
    );

    // Configuración por defecto (contexto plugin:companysignature). Sin secretos.
    Config::setConfigurationValues(PluginConfig::CONTEXT, PluginConfig::DEFAULTS);

    // CronTask NATIVA: reconciliación durable automática (harvest + worker). NUNCA aprueba/rechaza;
    // sólo materializa evidencia YA comprometida en el ledger. Frecuencia configurable desde GLPI.
    if (class_exists('CronTask')) {
        CronTask::register(
            \GlpiPlugin\Companysignature\Model\ReconcileTask::class,
            'reconcile',
            defined('MINUTE_TIMESTAMP') ? 5 * MINUTE_TIMESTAMP : 300,
            ['mode' => 2, 'comment' => 'companysignature: reconciliación durable de evidencia (harvest + worker)']
        );
    }

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companysignature_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    if (class_exists('CronTask')) {
        CronTask::unregister('companysignature');
    }

    foreach ([
        'glpi_plugin_companysignature_reconcile_queue',
        'glpi_plugin_companysignature_evidences',
        'glpi_plugin_companysignature_document_versions',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    if (class_exists('ProfileRight')) {
        ProfileRight::deleteProfileRights(['plugin_companysignature']);
    }

    Config::deleteConfigurationValues(PluginConfig::CONTEXT, array_keys(PluginConfig::DEFAULTS));

    return true;
}

// ---------------------------------------------------------------------------
//  Listeners de eventos de companyworkflow (hooks soportados; nunca editan core)
// ---------------------------------------------------------------------------

/** `companyworkflow:transitioned` → registrar evidencia de la decisión (idempotente). */
function plugin_companysignature_on_transitioned($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    try {
        (new WorkflowEventListener())->onTransitioned($payload);
    } catch (\Throwable) {
        // best-effort: registrar evidencia no debe tumbar la transición ya confirmada.
    }
    return $payload;
}

/** `companyworkflow:decision_recorded` → registrar evidencia de la DECISIÓN por aprobador (idempotente). */
function plugin_companysignature_on_decision_recorded($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    try {
        (new WorkflowEventListener())->onDecisionRecorded($payload);
    } catch (\Throwable) {
        // best-effort: recuperable por reconciliación (plugins:companysignature:reconcile).
    }
    return $payload;
}

/** `companyworkflow:approval_invalidated` → registrar evidencia de invalidación (append-only). */
function plugin_companysignature_on_approval_invalidated($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    try {
        (new WorkflowEventListener())->onApprovalInvalidated($payload);
    } catch (\Throwable) {
        // best-effort.
    }
    return $payload;
}
