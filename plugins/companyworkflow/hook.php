<?php
/**
 * Hooks de ciclo de vida de Company Workflow (companyworkflow).
 *
 * install()/uninstall() usan MIGRACIONES REVERSIBLES con prefijo propio de tabla
 * (glpi_plugin_companyworkflow_*). NO se accede por SQL directo a tablas del core para
 * saltar reglas de negocio (ver CLAUDE.md, Prohibiciones). El único write sobre una tabla
 * core es otorgar el propio derecho del plugin al perfil Super-Admin (patrón estándar).
 *
 * Esquema (ver docs/architecture/companyworkflow-technical-design.md §Modelo de datos):
 *   defs · statedefs · transitions · steps · instances · assignments · delegations · history
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use GlpiPlugin\Companyworkflow\Service\PluginConfig;

/**
 * Instalación: crea tablas propias (reversibles), derechos y configuración por defecto.
 * @return boolean
 */
function plugin_companyworkflow_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $p         = 'glpi_plugin_companyworkflow_';

    // --- Definiciones de workflow (VERSIONADAS: una def+version = fila inmutable) ---
    if (!$DB->tableExists("{$p}defs")) {
        $DB->doQuery("CREATE TABLE `{$p}defs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(100) NOT NULL,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `itemtype_target` VARCHAR(100) NOT NULL DEFAULT '',
            `version` INT UNSIGNED NOT NULL DEFAULT 1,
            `is_active` TINYINT NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code_version` (`code`,`version`),
            KEY `is_active` (`is_active`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Estados de una definición ---
    if (!$DB->tableExists("{$p}statedefs")) {
        $DB->doQuery("CREATE TABLE `{$p}statedefs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflowdefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `code` VARCHAR(100) NOT NULL,
            `label` VARCHAR(255) NOT NULL DEFAULT '',
            `kind` VARCHAR(20) NOT NULL DEFAULT 'intermediate',
            `is_editable` TINYINT NOT NULL DEFAULT 0,
            `sla_hours` INT UNSIGNED DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `def_code` (`workflowdefs_id`,`code`),
            KEY `kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Transiciones permitidas ---
    if (!$DB->tableExists("{$p}transitions")) {
        $DB->doQuery("CREATE TABLE `{$p}transitions` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflowdefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `from_statedefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `to_statedefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `action` VARCHAR(60) NOT NULL DEFAULT '',
            `requires_comment` TINYINT NOT NULL DEFAULT 0,
            `is_auto` TINYINT NOT NULL DEFAULT 0,
            `condition_json` TEXT DEFAULT NULL,
            `required_right` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `def_from` (`workflowdefs_id`,`from_statedefs_id`),
            KEY `action` (`action`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Etapas/quórum de una transición de aprobación ---
    if (!$DB->tableExists("{$p}steps")) {
        $DB->doQuery("CREATE TABLE `{$p}steps` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `transitions_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `level` INT UNSIGNED NOT NULL DEFAULT 1,
            `quorum_type` VARCHAR(20) NOT NULL DEFAULT 'count',
            `quorum_value` INT UNSIGNED NOT NULL DEFAULT 1,
            `approver_kind` VARCHAR(30) NOT NULL DEFAULT 'group',
            `approver_ref` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `transition` (`transitions_id`),
            KEY `level` (`level`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Instancia viva sobre un objeto de dominio (lock_version = control optimista) ---
    if (!$DB->tableExists("{$p}instances")) {
        $DB->doQuery("CREATE TABLE `{$p}instances` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `workflowdefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `def_version` INT UNSIGNED NOT NULL DEFAULT 1,
            `itemtype` VARCHAR(100) NOT NULL DEFAULT '',
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `current_statedefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'open',
            `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `domain_item` (`itemtype`,`items_id`),
            KEY `def` (`workflowdefs_id`),
            KEY `entities_id` (`entities_id`),
            KEY `current_state` (`current_statedefs_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Votos de aprobación (UNIQUE evita doble aprobación por concurrencia/replay) ---
    if (!$DB->tableExists("{$p}assignments")) {
        $DB->doQuery("CREATE TABLE `{$p}assignments` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `statedefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `steps_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `level` INT UNSIGNED NOT NULL DEFAULT 1,
            `users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `group_ref` INT UNSIGNED NOT NULL DEFAULT 0,
            `decision` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `comment` TEXT DEFAULT NULL,
            `delegated_from` INT UNSIGNED DEFAULT NULL,
            `date` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `ballot` (`instances_id`,`statedefs_id`,`users_id`),
            KEY `instance` (`instances_id`),
            KEY `decision` (`decision`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Delegaciones temporales de aprobación ---
    if (!$DB->tableExists("{$p}delegations")) {
        $DB->doQuery("CREATE TABLE `{$p}delegations` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `users_id_from` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_to` INT UNSIGNED NOT NULL DEFAULT 0,
            `workflowdefs_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_start` TIMESTAMP NULL DEFAULT NULL,
            `date_end` TIMESTAMP NULL DEFAULT NULL,
            `reason` VARCHAR(255) DEFAULT NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `from` (`users_id_from`),
            KEY `to` (`users_id_to`),
            KEY `active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Auditoría append-only (una fila por evento; nunca se borra salvo uninstall) ---
    if (!$DB->tableExists("{$p}history")) {
        $DB->doQuery("CREATE TABLE `{$p}history` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `event` VARCHAR(60) NOT NULL DEFAULT '',
            `from_code` VARCHAR(100) NOT NULL DEFAULT '',
            `to_code` VARCHAR(100) NOT NULL DEFAULT '',
            `actor_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_system` TINYINT NOT NULL DEFAULT 0,
            `comment` TEXT DEFAULT NULL,
            `meta_json` TEXT DEFAULT NULL,
            `date` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `instance` (`instances_id`),
            KEY `event` (`event`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- ACL: derecho propio del plugin en todos los perfiles (0 por defecto) ---
    if (class_exists('ProfileRight')) {
        ProfileRight::addProfileRights([WorkflowDef::$rightname]);
    }
    // Otorgar todos los bits al perfil Super-Admin (id 4 por defecto en GLPI).
    $full = READ | WorkflowDef::RIGHT_ACT | WorkflowDef::RIGHT_ADMIN
        | WorkflowDef::RIGHT_DELEGATE | WorkflowDef::RIGHT_CONFIG;
    $DB->update(
        'glpi_profilerights',
        ['rights' => $full],
        ['profiles_id' => 4, 'name' => WorkflowDef::$rightname]
    );

    // --- Configuración por defecto (sin secretos) ---
    if (class_exists('Config')) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, PluginConfig::DEFAULTS);
    }

    // --- CronTask nativo para SLA/escalamiento (nunca aprueba solo) ---
    if (class_exists('CronTask')) {
        CronTask::register(
            Instance::class,
            'escalation',
            defined('HOUR_TIMESTAMP') ? HOUR_TIMESTAMP : 3600,
            ['mode' => 2, 'comment' => 'companyworkflow: detección de SLA vencido + escalamiento']
        );
    }

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companyworkflow_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    if (class_exists('CronTask')) {
        CronTask::unregister('companyworkflow');
    }

    $p = 'glpi_plugin_companyworkflow_';
    foreach ([
        "{$p}history", "{$p}delegations", "{$p}assignments", "{$p}instances",
        "{$p}steps", "{$p}transitions", "{$p}statedefs", "{$p}defs",
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    if (class_exists('ProfileRight')) {
        ProfileRight::deleteProfileRights([WorkflowDef::$rightname]);
    }
    if (class_exists('Config')) {
        Config::deleteConfigurationValues(PluginConfig::CONTEXT, array_keys(PluginConfig::DEFAULTS));
    }

    return true;
}
