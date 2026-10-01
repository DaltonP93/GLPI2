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
 * Esquema SI4-1 (ADR-0020): si4_sagas · si4_saga_log · map_models
 * Esquema SI4-2 (ADR-0021): map_glpi_assettypes + columnas GLPI/Infocom/puente/pin del mapeo en si4_sagas (UNIQUE glpi_item) +
 *                           asset_bridge.receipt_unit_uuid (UNIQUE, NULL para puentes SI-1)
 * Esquema SI4-3 (ADR-0022): si4_sagas.qr_code_id (UNIQUE) / qr_public_code / qr_outcome / label_ready_at / completed_at +
 *                           si4_runtime (estado operativo: cursor durable del finalizador)
 *
 * install() es SEGURO EN UPGRADE (GLPI lo vuelve a llamar al actualizar 0.2.0 → 0.3.0 → 0.4.0 → 0.5.0): tablas con IF-not-exists,
 * columnas/índices nuevos sólo si faltan,
 * el derecho se agrega sólo si falta (re-agregarlo viola el UNIQUE de glpi_profilerights), los bits nuevos se SUMAN
 * al Super-Admin sin quitar nada y la configuración sólo siembra claves AUSENTES (no pisa lo ajustado).
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

    // --- SI4-1: saga durable por unidad (una por receipt_unit_uuid; un activo remoto nunca en dos unidades) ---
    if (!$DB->tableExists("{$p}si4_sagas")) {
        $DB->doQuery("CREATE TABLE `{$p}si4_sagas` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `receipt_unit_uuid` CHAR(36) NOT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `payload_sha256` CHAR(64) NOT NULL DEFAULT '',
            `correlation_id` VARCHAR(64) DEFAULT NULL,
            `state` VARCHAR(30) NOT NULL DEFAULT 'PENDING',
            `snipe_asset_id` INT UNSIGNED DEFAULT NULL,
            `snipe_asset_tag` VARCHAR(255) DEFAULT NULL,
            `snipe_outcome` VARCHAR(20) DEFAULT NULL,
            `snipe_company_id` INT UNSIGNED DEFAULT NULL,
            `snipe_model_id` INT UNSIGNED DEFAULT NULL,
            `snipe_status_id` INT UNSIGNED DEFAULT NULL,
            `lease_token_sha256` CHAR(64) DEFAULT NULL,
            `lease_until` TIMESTAMP NULL DEFAULT NULL,
            `lease_epoch` INT UNSIGNED NOT NULL DEFAULT 0,
            `worker_id` VARCHAR(190) DEFAULT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `remote_create_calls` INT UNSIGNED NOT NULL DEFAULT 0,
            `row_version` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(255) DEFAULT NULL,
            `last_error_class` VARCHAR(30) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `receipt_unit_uuid` (`receipt_unit_uuid`),
            UNIQUE KEY `snipe_asset_id` (`snipe_asset_id`),
            KEY `state` (`state`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- SI4-1: bitácora append-only de la saga (auditoría de integración) ---
    if (!$DB->tableExists("{$p}si4_saga_log")) {
        $DB->doQuery("CREATE TABLE `{$p}si4_saga_log` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `receipt_unit_uuid` CHAR(36) NOT NULL,
            `event` VARCHAR(40) NOT NULL DEFAULT '',
            `from_state` VARCHAR(30) DEFAULT NULL,
            `to_state` VARCHAR(30) NOT NULL DEFAULT '',
            `detail` VARCHAR(255) DEFAULT NULL,
            `worker_id` VARCHAR(190) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `receipt_unit_uuid` (`receipt_unit_uuid`),
            KEY `event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- SI4-1: mapeo categoría de línea ↔ modelo Snipe (aprobado explícito; nunca por nombre) ---
    if (!$DB->tableExists("{$p}map_models")) {
        $DB->doQuery("CREATE TABLE `{$p}map_models` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_key` VARCHAR(190) NOT NULL DEFAULT '',
            `snipe_model_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `snipe_name` VARCHAR(255) NOT NULL DEFAULT '',
            `is_approved` TINYINT NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `category_key` (`category_key`),
            KEY `is_approved` (`is_approved`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- SI4-2: mapeo categoría de línea ↔ tipo de activo GLPI (+ modelo GLPI opcional; aprobado explícito) ---
    if (!$DB->tableExists("{$p}map_glpi_assettypes")) {
        $DB->doQuery("CREATE TABLE `{$p}map_glpi_assettypes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_key` VARCHAR(190) NOT NULL DEFAULT '',
            `glpi_itemtype` VARCHAR(100) NOT NULL DEFAULT '',
            `glpi_model_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_approved` TINYINT NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `category_key` (`category_key`),
            KEY `is_approved` (`is_approved`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // --- SI4-2: columnas nuevas (sólo si faltan: el MISMO camino para instalación nueva y upgrade 0.3.0 → 0.4.0) ---
    plugin_companyintegrations_add_missing_columns("{$p}si4_sagas", [
        'glpi_itemtype'     => "VARCHAR(100) DEFAULT NULL",
        'glpi_items_id'     => "INT UNSIGNED DEFAULT NULL",
        'glpi_entity_id'    => "INT UNSIGNED DEFAULT NULL",
        'glpi_outcome'      => "VARCHAR(20) DEFAULT NULL",
        'glpi_create_calls' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'glpi_infocom_id'   => "INT UNSIGNED DEFAULT NULL",
        'infocom_outcome'   => "VARCHAR(20) DEFAULT NULL",
        'asset_bridge_id'   => "INT UNSIGNED DEFAULT NULL",
        'resume_state'      => "VARCHAR(30) DEFAULT NULL",
        // Destino GLPI PINNEADO al primer uso (ADR-0021 §2): mapeo + modelo + huella; glpi_itemtype completa el pin.
        'glpi_mapping_id'   => "INT UNSIGNED DEFAULT NULL",
        'glpi_model_id'     => "INT UNSIGNED DEFAULT NULL",
        'glpi_mapping_hash' => "CHAR(64) DEFAULT NULL",
    ]);
    // Un activo GLPI nunca queda ligado a dos unidades.
    if (!isIndex("{$p}si4_sagas", 'glpi_item')) {
        $DB->doQuery("ALTER TABLE `{$p}si4_sagas` ADD UNIQUE KEY `glpi_item` (`glpi_itemtype`, `glpi_items_id`)");
    }
    // --- SI4-3 (ADR-0022): metadatos NO sensibles del código companyqr + hitos (sólo si faltan: 0.4.0 → 0.5.0). Nunca el
    // token del QR ni el PDF de la etiqueta (no hay columna para ellos). ---
    plugin_companyintegrations_add_missing_columns("{$p}si4_sagas", [
        'qr_code_id'     => "INT UNSIGNED DEFAULT NULL",
        'qr_public_code' => "VARCHAR(255) DEFAULT NULL",
        'qr_outcome'     => "VARCHAR(20) DEFAULT NULL",
        'label_ready_at' => "TIMESTAMP NULL DEFAULT NULL",
        'completed_at'   => "TIMESTAMP NULL DEFAULT NULL",
    ]);
    // Un código companyqr nunca queda ligado a dos unidades.
    if (!isIndex("{$p}si4_sagas", 'qr_code_id')) {
        $DB->doQuery("ALTER TABLE `{$p}si4_sagas` ADD UNIQUE KEY `qr_code_id` (`qr_code_id`)");
    }
    // --- SI4-3: estado operativo durable del worker (p. ej. cursor round-robin del finalizador; NO configuración) ---
    if (!$DB->tableExists("{$p}si4_runtime")) {
        $DB->doQuery("CREATE TABLE `{$p}si4_runtime` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(64) NOT NULL DEFAULT '',
            `int_value` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }
    if (countElementsInTable("{$p}si4_runtime", ['name' => 'si4_finalizer_cursor']) === 0) {
        $DB->insert("{$p}si4_runtime", ['name' => 'si4_finalizer_cursor', 'int_value' => 0]);
    }
    // asset_bridge 1:1 por unidad: NULL para los puentes SI-1 (reconciliación), único cuando existe.
    plugin_companyintegrations_add_missing_columns("{$p}asset_bridge", [
        'receipt_unit_uuid' => "CHAR(36) DEFAULT NULL",
    ]);
    if (!isIndex("{$p}asset_bridge", 'receipt_unit_uuid')) {
        $DB->doQuery("ALTER TABLE `{$p}asset_bridge` ADD UNIQUE KEY `receipt_unit_uuid` (`receipt_unit_uuid`)");
    }

    // --- ACL (IDEMPOTENTE: re-agregar el derecho violaría el UNIQUE (profiles_id, name) y abortaría el upgrade) ---
    if (class_exists('ProfileRight')
        && countElementsInTable(ProfileRight::getTable(), ['name' => AssetBridge::$rightname]) === 0) {
        ProfileRight::addProfileRights([AssetBridge::$rightname]);
    }
    // Super-Admin (id 4): se SUMAN los bits del plugin sin quitar ninguno que un administrador haya dado.
    $full = READ | AssetBridge::RIGHT_RECONCILE | AssetBridge::RIGHT_MAP | AssetBridge::RIGHT_CONFIG | AssetBridge::RIGHT_SI4;
    $current = 0;
    foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights',
        'WHERE' => ['profiles_id' => 4, 'name' => AssetBridge::$rightname]]) as $row) {
        $current = (int) $row['rights'];
    }
    $DB->update(
        'glpi_profilerights',
        ['rights' => $current | $full],
        ['profiles_id' => 4, 'name' => AssetBridge::$rightname]
    );

    // --- Config por defecto (SIN token; el token vive en secret/env, nunca en Git/BD). Sólo claves AUSENTES. ---
    if (class_exists('Config')) {
        $existing = Config::getConfigurationValues(PluginConfig::CONTEXT);
        $missing = array_diff_key(PluginConfig::DEFAULTS, is_array($existing) ? $existing : []);
        if ($missing !== []) {
            Config::setConfigurationValues(PluginConfig::CONTEXT, $missing);
        }
    }

    return true;
}

/**
 * Agrega a una tabla PROPIA las columnas que falten (upgrade seguro: nunca toca las existentes ni sus datos).
 *
 * @param array<string,string> $columns nombre => definición SQL
 */
function plugin_companyintegrations_add_missing_columns(string $table, array $columns): void {
    /** @var DBmysql $DB */
    global $DB;

    foreach ($columns as $name => $definition) {
        if (!$DB->fieldExists($table, $name, false)) {
            $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        }
    }
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
        "{$p}si4_saga_log", "{$p}si4_sagas", "{$p}map_models", "{$p}map_glpi_assettypes", "{$p}si4_runtime",
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
