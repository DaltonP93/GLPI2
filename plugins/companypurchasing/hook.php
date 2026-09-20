<?php
/**
 * Hooks de ciclo de vida de Company Purchasing (companypurchasing) — P2D-1 (núcleo).
 *
 * install()/uninstall() usan MIGRACIONES REVERSIBLES con prefijo propio de tabla
 * (glpi_plugin_companypurchasing_*). NO se accede por SQL directo a tablas del core para saltar reglas
 * de negocio (ver CLAUDE.md, Prohibiciones). El único write sobre una tabla core es otorgar el derecho
 * propio del plugin al perfil Super-Admin (patrón estándar de plugins), vía ProfileRight.
 *
 * Idempotencia a nivel SQL (`CREATE TABLE IF NOT EXISTS` / `DROP TABLE IF EXISTS`): un reinstall dentro
 * del MISMO proceso es correcto sin depender de la caché de esquema de GLPI.
 *
 * Tablas propias P2D-1:
 *   - glpi_plugin_companypurchasing_requests    : cabecera de solicitud (borrador + numeración).
 *   - glpi_plugin_companypurchasing_items       : líneas (identidad = id; line_no sólo orden).
 *   - glpi_plugin_companypurchasing_numbering    : secuencias UNIQUE(entities_id, scope, year).
 *   - glpi_plugin_companypurchasing_events       : auditoría de negocio APPEND-ONLY.
 *   - glpi_plugin_companypurchasing_scope_defs    : scopes de aprobación VERSIONADOS/configurables.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\ScopeCatalog;

/**
 * Instalación: crea tablas propias, derechos y configuración por defecto (reversible).
 * @return boolean
 */
function plugin_companypurchasing_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_requests` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `number` VARCHAR(60) DEFAULT NULL,
        `number_seq` INT UNSIGNED DEFAULT NULL,
        `number_scope` VARCHAR(60) NOT NULL DEFAULT '',
        `number_year` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `is_recursive` TINYINT NOT NULL DEFAULT 0,
        `users_id_requester` INT UNSIGNED NOT NULL DEFAULT 0,
        `groups_id_department` INT UNSIGNED NOT NULL DEFAULT 0,
        `category` VARCHAR(190) NOT NULL DEFAULT '',
        `destination` VARCHAR(255) NOT NULL DEFAULT '',
        `reason` TEXT DEFAULT NULL,
        `observations` TEXT DEFAULT NULL,
        `suppliers_id_suggested` INT UNSIGNED NOT NULL DEFAULT 0,
        `budgets_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `currency_code` CHAR(3) NOT NULL DEFAULT 'PYG',
        `amount_estimated` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `domain_state` VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
        `scopes_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `workflow_instances_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `correlation_id` VARCHAR(64) NOT NULL DEFAULT '',
        `users_id_creator` INT UNSIGNED NOT NULL DEFAULT 0,
        `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        -- Numeración por ENTIDAD (secuencias independientes): el texto visible \"REQUEST-<año>-<seq>\"
        -- puede repetirse ENTRE entidades pero jamás dentro de la misma entidad. `number`/`number_seq`
        -- son NULL en borrador (MySQL admite múltiples NULL en UNIQUE), así que varios borradores conviven.
        UNIQUE KEY `ent_number` (`entities_id`,`number`),
        UNIQUE KEY `ent_seq` (`entities_id`,`number_scope`,`number_year`,`number_seq`),
        KEY `entities_id` (`entities_id`),
        KEY `users_id_requester` (`users_id_requester`),
        KEY `domain_state` (`domain_state`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `line_no` INT UNSIGNED NOT NULL DEFAULT 0,
        `description` VARCHAR(255) NOT NULL DEFAULT '',
        `category` VARCHAR(190) NOT NULL DEFAULT '',
        `quantity` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `unit` VARCHAR(30) NOT NULL DEFAULT '',
        `is_inventoriable` TINYINT NOT NULL DEFAULT 0,
        `currency_code` CHAR(3) NOT NULL DEFAULT 'PYG',
        `estimated_unit_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `estimated_line_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `notes` TEXT DEFAULT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `req_line` (`requests_id`,`line_no`),
        KEY `requests_id` (`requests_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_numbering` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `scope` VARCHAR(60) NOT NULL DEFAULT '',
        `year` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ent_scope_year` (`entities_id`,`scope`,`year`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_events` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `event` VARCHAR(60) NOT NULL DEFAULT '',
        `actor_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `correlation_id` VARCHAR(64) NOT NULL DEFAULT '',
        `detail` LONGTEXT DEFAULT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `requests_id` (`requests_id`),
        KEY `event` (`event`),
        KEY `entities_id` (`entities_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_scope_defs` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `scopes_version` INT UNSIGNED NOT NULL DEFAULT 1,
        `scope_key` VARCHAR(60) NOT NULL DEFAULT '',
        `fields_json` LONGTEXT NOT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `ver_scope` (`scopes_version`,`scope_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Derecho propio del plugin en todos los perfiles (valor 0 por defecto).
    if (class_exists('ProfileRight')) {
        ProfileRight::addProfileRights(['plugin_companypurchasing']);
    }

    // Otorgar todos los bits al perfil Super-Admin (id 4 por defecto en GLPI).
    $full = READ
        | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
        | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG
        | Request::RIGHT_MANAGE_PURCHASING | Request::RIGHT_RECEIVE | Request::RIGHT_DELIVER
        | Request::RIGHT_VIEW_METRICS;
    $DB->update(
        'glpi_profilerights',
        ['rights' => $full],
        ['profiles_id' => 4, 'name' => 'plugin_companypurchasing']
    );

    // Configuración por defecto (contexto plugin:companypurchasing). Sin secretos.
    Config::setConfigurationValues(PluginConfig::CONTEXT, PluginConfig::DEFAULTS);

    // Sembrar la versión 1 del catálogo de scopes de aprobación (idempotente).
    ScopeCatalog::seedVersion1();

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companypurchasing_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    foreach ([
        'glpi_plugin_companypurchasing_scope_defs',
        'glpi_plugin_companypurchasing_events',
        'glpi_plugin_companypurchasing_numbering',
        'glpi_plugin_companypurchasing_items',
        'glpi_plugin_companypurchasing_requests',
    ] as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `{$table}`");
    }

    if (class_exists('ProfileRight')) {
        ProfileRight::deleteProfileRights(['plugin_companypurchasing']);
    }

    Config::deleteConfigurationValues(PluginConfig::CONTEXT, array_keys(PluginConfig::DEFAULTS));

    return true;
}
