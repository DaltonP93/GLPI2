<?php
/**
 * Hooks de ciclo de vida de Company Integrations (companyintegrations).
 *
 * install()/uninstall() usan MIGRACIONES REVERSIBLES con prefijo propio de tabla
 * (glpi_plugin_companyintegrations_*). NO se accede por SQL directo a tablas del core para
 * saltar reglas de negocio. El único write sobre core es el ProfileRight del propio derecho.
 *
 * Esquema SI-1 (ver docs/architecture/asset-bridge-model.md):
 *   asset_bridge · asset_tag_aliases · map_companies · map_users · recon
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Service\PluginConfig;

/**
 * Instalación: crea tablas propias (reversibles), derechos y configuración por defecto.
 * @return boolean
 */
function plugin_companyintegrations_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $p         = 'glpi_plugin_companyintegrations_';

    // --- Puente 1:1 estable Snipe ↔ GLPI (mapeo por ID, nunca por nombre) ---
    if (!$DB->tableExists("{$p}asset_bridge")) {
        $DB->doQuery("CREATE TABLE `{$p}asset_bridge` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `snipe_asset_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `snipe_asset_tag` VARCHAR(255) NOT NULL DEFAULT '',
            `glpi_itemtype` VARCHAR(100) NOT NULL DEFAULT '',
            `glpi_items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `glpi_entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `serial` VARCHAR(255) DEFAULT NULL,
            `sync_status` VARCHAR(30) NOT NULL DEFAULT 'pending',
            `source_version` VARCHAR(100) DEFAULT NULL,
            `correlation_id` VARCHAR(64) DEFAULT NULL,
            `last_sync_at` DATETIME DEFAULT NULL,
            `last_reconciled_at` DATETIME DEFAULT NULL,
            `last_error` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `snipe_asset_id` (`snipe_asset_id`),
            UNIQUE KEY `snipe_asset_tag` (`snipe_asset_tag`),
            UNIQUE KEY `glpi_item` (`glpi_itemtype`,`glpi_items_id`),
            KEY `sync_status` (`sync_status`),
            KEY `glpi_entity_id` (`glpi_entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Alias histórico de asset tags (una etiqueta impresa nunca se rompe por un rename) ---
    if (!$DB->tableExists("{$p}asset_tag_aliases")) {
        $DB->doQuery("CREATE TABLE `{$p}asset_tag_aliases` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `asset_bridge_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `asset_tag` VARCHAR(255) NOT NULL DEFAULT '',
            `is_current` TINYINT NOT NULL DEFAULT 1,
            `valid_from` DATETIME DEFAULT NULL,
            `valid_to` DATETIME DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `asset_tag` (`asset_tag`),
            KEY `bridge` (`asset_bridge_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Mapeo compañía Snipe ↔ entidad GLPI (obligatorio multi-entidad; aprobado explícito) ---
    if (!$DB->tableExists("{$p}map_companies")) {
        $DB->doQuery("CREATE TABLE `{$p}map_companies` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `snipe_company_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `snipe_name` VARCHAR(255) NOT NULL DEFAULT '',
            `glpi_entity_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_approved` TINYINT NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `snipe_company_id` (`snipe_company_id`),
            KEY `glpi_entity_id` (`glpi_entity_id`),
            KEY `is_approved` (`is_approved`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Mapeo usuario Snipe ↔ usuario GLPI (identidad = GLPI/IdP; aprobado explícito) ---
    if (!$DB->tableExists("{$p}map_users")) {
        $DB->doQuery("CREATE TABLE `{$p}map_users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `snipe_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `snipe_name` VARCHAR(255) NOT NULL DEFAULT '',
            `glpi_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_approved` TINYINT NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `snipe_user_id` (`snipe_user_id`),
            KEY `glpi_users_id` (`glpi_users_id`),
            KEY `is_approved` (`is_approved`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- Resultados de reconciliación (append-only, auditoría; sin auto-corregir) ---
    if (!$DB->tableExists("{$p}recon")) {
        $DB->doQuery("CREATE TABLE `{$p}recon` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `run_id` VARCHAR(64) NOT NULL DEFAULT '',
            `correlation_id` VARCHAR(64) DEFAULT NULL,
            `snipe_asset_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `snipe_asset_tag` VARCHAR(255) NOT NULL DEFAULT '',
            `classification` VARCHAR(30) NOT NULL DEFAULT '',
            `glpi_itemtype` VARCHAR(100) DEFAULT NULL,
            `glpi_items_id` INT UNSIGNED DEFAULT NULL,
            `glpi_entity_id` INT UNSIGNED DEFAULT NULL,
            `detail` VARCHAR(255) DEFAULT NULL,
            `date` TIMESTAMP NULL DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `run` (`run_id`),
            KEY `classification` (`classification`),
            KEY `snipe_asset` (`snipe_asset_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- ACL ---
    if (class_exists('ProfileRight')) {
        ProfileRight::addProfileRights([AssetBridge::$rightname]);
    }
    $full = READ | AssetBridge::RIGHT_RECONCILE | AssetBridge::RIGHT_MAP | AssetBridge::RIGHT_CONFIG;
    $DB->update(
        'glpi_profilerights',
        ['rights' => $full],
        ['profiles_id' => 4, 'name' => AssetBridge::$rightname]
    );

    // --- Config por defecto (SIN token; el token vive en secret/env, nunca en Git/BD) ---
    if (class_exists('Config')) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, PluginConfig::DEFAULTS);
    }

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companyintegrations_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    $p = 'glpi_plugin_companyintegrations_';
    foreach ([
        "{$p}recon", "{$p}asset_tag_aliases", "{$p}asset_bridge",
        "{$p}map_users", "{$p}map_companies",
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    if (class_exists('ProfileRight')) {
        ProfileRight::deleteProfileRights([AssetBridge::$rightname]);
    }
    if (class_exists('Config')) {
        Config::deleteConfigurationValues(PluginConfig::CONTEXT, array_keys(PluginConfig::DEFAULTS));
    }

    return true;
}
