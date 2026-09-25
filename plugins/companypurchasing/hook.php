<?php
/**
 * Hooks de ciclo de vida de Company Purchasing (companypurchasing) — P2D-1 (núcleo) … P2D-3 (recepción).
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
 * Tablas propias P2D-2:
 *   - glpi_plugin_companypurchasing_quotes         : cotizaciones (Supplier nativo; sin `is_selected`).
 *   - glpi_plugin_companypurchasing_quote_items    : precio final por línea (FK items.id, no line_no).
 *   - glpi_plugin_companypurchasing_doc_versions   : ledger de versiones documentales de dominio.
 *   - glpi_plugin_companypurchasing_docseq         : contador monotónico de document_version por solicitud.
 *   - glpi_plugin_companypurchasing_policies       : versiones INMUTABLES de la política de aprobación.
 *   - glpi_plugin_companypurchasing_integrity      : marcas durables de integridad (scope sucio).
 * y columnas nuevas en `requests` (`quotes_id_selected`, `workflow_lock_version`, `workflow_synced_at`,
 * `policies_id`, `integrity_state`),
 * añadidas también en UPGRADE (ALTER idempotente) sobre una instalación P2D-1.
 * Tablas propias P2D-3 (recepción física + handoff a SI-4; gate §5–§7):
 *   - glpi_plugin_companypurchasing_receipt_batches  : lote/evento de recepción (idempotency_key UNIQUE).
 *   - glpi_plugin_companypurchasing_receipt_units    : UNA fila por unidad física (receipt_unit_uuid UNIQUE).
 *   - glpi_plugin_companypurchasing_inventory_outbox : handoff por unidad inventariable (payload inmutable).
 *   - glpi_plugin_companypurchasing_cost_policies    : versiones INMUTABLES de la política de costo.
 * y columnas nuevas en `items` (`ordered_qty`, `received_qty`, `purchase_unit_price`, `line_cost_total`) y en
 * `requests` (`purchase_started_at`, `purchase_quotes_id`, `purchase_suppliers_id`, `cost_policies_id`,
 * `receiving_seq`, `receiving_synced_seq`), añadidas también en UPGRADE (ALTER idempotente) sobre P2D-2.
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
        `quotes_id_selected` INT UNSIGNED NOT NULL DEFAULT 0,
        `workflow_lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `workflow_synced_at` TIMESTAMP NULL DEFAULT NULL,
        `policies_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `integrity_state` VARCHAR(20) NOT NULL DEFAULT 'clean',
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
        `idempotency_key` VARCHAR(190) DEFAULT NULL,
        `detail` LONGTEXT DEFAULT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idempotency_key` (`idempotency_key`),
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

    // --- P2D-2: UPGRADE idempotente de una instalación P2D-1 (columnas nuevas en `requests`). ---
    foreach ([
        'quotes_id_selected'    => "INT UNSIGNED NOT NULL DEFAULT 0",
        'workflow_lock_version' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'workflow_synced_at'    => "TIMESTAMP NULL DEFAULT NULL",
        'policies_id'           => "INT UNSIGNED NOT NULL DEFAULT 0",
        'integrity_state'       => "VARCHAR(20) NOT NULL DEFAULT 'clean'",
    ] as $col => $ddl) {
        plugin_companypurchasing_add_column_if_missing('glpi_plugin_companypurchasing_requests', $col, $ddl);
    }

    // Cotizaciones: proveedor = Supplier NATIVO; adjuntos = Document/Document_Item NATIVOS. SIN `is_selected`
    // (la única fuente de verdad de la selección es `requests.quotes_id_selected`). Totales DERIVADOS.
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_quotes` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `is_recursive` TINYINT NOT NULL DEFAULT 0,
        `suppliers_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `reference` VARCHAR(190) NOT NULL DEFAULT '',
        `currency_code` CHAR(3) NOT NULL DEFAULT 'PYG',
        `discounts` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `taxes` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `freight` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `valid_until` DATE NULL DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `users_id_creator` INT UNSIGNED NOT NULL DEFAULT 0,
        `lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `requests_id` (`requests_id`),
        KEY `suppliers_id` (`suppliers_id`),
        KEY `entities_id` (`entities_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Precio final por línea: identidad = items_id (FK ..._items.id), nunca line_no.
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_quote_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `quotes_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `final_unit_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `quote_item` (`quotes_id`,`items_id`),
        KEY `items_id` (`items_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Ledger de versiones documentales de DOMINIO (Compras numera; Firma valida/inmoviliza).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_doc_versions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `scope_key` VARCHAR(60) NOT NULL DEFAULT '',
        `document_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `payload_sha256` CHAR(64) NOT NULL DEFAULT '',
        `document_versions_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `content_sha256` VARCHAR(64) NOT NULL DEFAULT '',
        `pdf_status` VARCHAR(20) NOT NULL DEFAULT '',
        `correlation_id` VARCHAR(64) NOT NULL DEFAULT '',
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `req_version` (`requests_id`,`document_version`),
        KEY `req_scope` (`requests_id`,`scope_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Contador monotónico por solicitud (incremento atómico; nunca se recicla).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_docseq` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `next_version` INT UNSIGNED NOT NULL DEFAULT 1,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `requests_id` (`requests_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Versiones INMUTABLES de la política de aprobación (pinneadas por solicitud en `requests.policies_id`).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_policies` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `policy_hash` CHAR(64) NOT NULL DEFAULT '',
        `policy_json` LONGTEXT NOT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `policy_hash` (`policy_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Marcas DURABLES de integridad de aprobación (escritas en la misma transacción que la mutación).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_integrity` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `scope_key` VARCHAR(60) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'dirty',
        `cause` VARCHAR(60) NOT NULL DEFAULT '',
        `actor_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `workflow_state` VARCHAR(60) NOT NULL DEFAULT '',
        `workflow_lock_version` INT UNSIGNED NOT NULL DEFAULT 0,
        `idempotency_key` VARCHAR(190) NOT NULL DEFAULT '',
        `resolution` VARCHAR(190) NOT NULL DEFAULT '',
        `resolved_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_resolved` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idempotency_key` (`idempotency_key`),
        KEY `req_status` (`requests_id`,`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // --- P2D-3: recepción física, costo atribuible y handoff (outbox) a SI-4. ---
    // Contadores por línea: `ordered_qty` (cantidad aprobada CONGELADA al iniciar la compra) y `received_qty`
    // (hecho físico). `pending = ordered_qty − received_qty` se DERIVA (no se almacena). `purchase_unit_price` y
    // `line_cost_total` son el snapshot del precio final y del costo atribuible de la línea (política pinneada).
    foreach ([
        'ordered_qty'         => "INT UNSIGNED NOT NULL DEFAULT 0",
        'received_qty'        => "INT UNSIGNED NOT NULL DEFAULT 0",
        'purchase_unit_price' => "DECIMAL(20,6) NOT NULL DEFAULT 0",
        'line_cost_total'     => "DECIMAL(20,6) NOT NULL DEFAULT 0",
    ] as $col => $ddl) {
        plugin_companypurchasing_add_column_if_missing('glpi_plugin_companypurchasing_items', $col, $ddl);
    }
    // Cabecera: inicio de compra (congelamiento), proveedor/cotización de la compra, política de costo pinneada
    // y marcador DURABLE de sincronización con el motor (`receiving_seq` > `receiving_synced_seq` ⇒ pendiente).
    foreach ([
        'purchase_started_at'   => "TIMESTAMP NULL DEFAULT NULL",
        'purchase_quotes_id'    => "INT UNSIGNED NOT NULL DEFAULT 0",
        'purchase_suppliers_id' => "INT UNSIGNED NOT NULL DEFAULT 0",
        'cost_policies_id'      => "INT UNSIGNED NOT NULL DEFAULT 0",
        'receiving_seq'         => "INT UNSIGNED NOT NULL DEFAULT 0",
        'receiving_synced_seq'  => "INT UNSIGNED NOT NULL DEFAULT 0",
    ] as $col => $ddl) {
        plugin_companypurchasing_add_column_if_missing('glpi_plugin_companypurchasing_requests', $col, $ddl);
    }

    // Lote/evento de recepción (APPEND-ONLY). `idempotency_key` de la OPERACIÓN (UNIQUE): reintentar la misma
    // recepción devuelve el mismo lote. `input_sha256` detecta la reutilización de una clave con otra entrada.
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_receipt_batches` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `idempotency_key` VARCHAR(190) NOT NULL,
        `input_sha256` CHAR(64) NOT NULL DEFAULT '',
        `actor_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `received_at` TIMESTAMP NULL DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `documents_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `cost_policies_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `units_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `correlation_id` VARCHAR(64) NOT NULL DEFAULT '',
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idempotency_key` (`idempotency_key`),
        KEY `requests_id` (`requests_id`),
        KEY `entities_id` (`entities_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // UNA fila por unidad física. IDENTIDAD CANÓNICA = `receipt_unit_uuid` (UUID v4 CSPRNG, inmutable, UNIQUE);
    // FK lógica = `items_id` (la línea real; NUNCA line_no). `unit_index`/`correlation_key` sólo display.
    // `unit_cost` = snapshot INMUTABLE (exacto, DECIMAL). `serial` NULL = sin serial; UNIQUE por línea.
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_receipt_units` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `receipt_batches_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `receipt_unit_uuid` CHAR(36) NOT NULL,
        `serial` VARCHAR(190) DEFAULT NULL,
        `currency_code` CHAR(3) NOT NULL DEFAULT 'PYG',
        `unit_cost` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `is_inventoriable` TINYINT NOT NULL DEFAULT 0,
        `physical_state` VARCHAR(30) NOT NULL DEFAULT 'RECEIVED',
        `unit_index` INT UNSIGNED NOT NULL DEFAULT 0,
        `correlation_key` VARCHAR(190) NOT NULL DEFAULT '',
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `receipt_unit_uuid` (`receipt_unit_uuid`),
        UNIQUE KEY `item_serial` (`items_id`,`serial`),
        KEY `receipt_batches_id` (`receipt_batches_id`),
        KEY `items_id` (`items_id`),
        KEY `requests_id` (`requests_id`),
        KEY `entities_id` (`entities_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Handoff a SI-4 (gate §7): una fila por unidad INVENTARIABLE, creada en la MISMA transacción que la unidad.
    // Payload INMUTABLE y versionado (`payload_version`, `payload_json`, `payload_sha256`); sólo mutan los campos
    // de ENTREGA (status, attempts, lease_*, next_retry_at, last_error, processed_at, date_mod).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_inventory_outbox` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `receipt_unit_uuid` CHAR(36) NOT NULL,
        `receipt_units_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `requests_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `payload_version` INT UNSIGNED NOT NULL DEFAULT 1,
        `payload_json` LONGTEXT NOT NULL,
        `payload_sha256` CHAR(64) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `lease_token` CHAR(64) DEFAULT NULL,
        `leased_by` VARCHAR(190) DEFAULT NULL,
        `leased_until` TIMESTAMP NULL DEFAULT NULL,
        `next_retry_at` TIMESTAMP NULL DEFAULT NULL,
        `last_error` VARCHAR(255) DEFAULT NULL,
        `processed_at` TIMESTAMP NULL DEFAULT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        `date_mod` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `receipt_unit_uuid` (`receipt_unit_uuid`),
        KEY `status_retry` (`status`,`next_retry_at`),
        KEY `leased_until` (`leased_until`),
        KEY `requests_id` (`requests_id`),
        KEY `entities_id` (`entities_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Versiones INMUTABLES de la política de costo (pinneadas por solicitud en `requests.cost_policies_id`).
    $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_companypurchasing_cost_policies` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `policy_hash` CHAR(64) NOT NULL DEFAULT '',
        `policy_json` LONGTEXT NOT NULL,
        `date_creation` TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `policy_hash` (`policy_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");

    // Derecho propio del plugin en todos los perfiles (valor 0 por defecto). IDEMPOTENTE: GLPI vuelve a
    // llamar install() al ACTUALIZAR el plugin (p. ej. 0.2.0 → 0.3.0); re-agregarlo violaría el UNIQUE
    // (profiles_id, name) de glpi_profilerights y abortaría el upgrade.
    if (class_exists('ProfileRight')
        && countElementsInTable(ProfileRight::getTable(), ['name' => 'plugin_companypurchasing']) === 0) {
        ProfileRight::addProfileRights(['plugin_companypurchasing']);
    }

    // Otorgar todos los bits al perfil Super-Admin (id 4 por defecto en GLPI).
    $full = READ
        | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
        | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG
        | Request::RIGHT_MANAGE_PURCHASING | Request::RIGHT_RECEIVE | Request::RIGHT_DELIVER
        | Request::RIGHT_VIEW_METRICS | Request::RIGHT_INTEGRATION;
    $DB->update(
        'glpi_profilerights',
        ['rights' => $full],
        ['profiles_id' => 4, 'name' => 'plugin_companypurchasing']
    );

    // Configuración por defecto (contexto plugin:companypurchasing). Sin secretos. Sólo se siembran las
    // claves AUSENTES: un reinstall/upgrade NO pisa la configuración que un administrador ya ajustó
    // (grupos aprobadores, quórum, mapas de scopes…).
    $current = Config::getConfigurationValues(PluginConfig::CONTEXT);
    $missing = array_diff_key(PluginConfig::DEFAULTS, is_array($current) ? $current : []);
    if ($missing !== []) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, $missing);
    }

    // Sembrar la versión 1 del catálogo de scopes de aprobación (idempotente).
    ScopeCatalog::seedVersion1();

    // CronTask NATIVA (Acción automática): reconciliación de la proyección `domain_state` con el motor, por
    // lotes con cursor (sin starvation), y detección de solicitudes enviadas sin instancia. NUNCA aprueba,
    // rechaza ni invalida. Idempotente (`register` no duplica).
    if (class_exists('CronTask')) {
        CronTask::register(
            \GlpiPlugin\Companypurchasing\Model\ProjectionTask::class,
            'reconcileprojection',
            defined('MINUTE_TIMESTAMP') ? 15 * MINUTE_TIMESTAMP : 900,
            ['mode' => 2, 'comment' => 'companypurchasing: reconciliación de la proyección domain_state con companyworkflow']
        );
    }

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companypurchasing_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    if (class_exists('CronTask')) {
        CronTask::unregister('companypurchasing');
    }

    foreach ([
        'glpi_plugin_companypurchasing_inventory_outbox',
        'glpi_plugin_companypurchasing_receipt_units',
        'glpi_plugin_companypurchasing_receipt_batches',
        'glpi_plugin_companypurchasing_cost_policies',
        'glpi_plugin_companypurchasing_integrity',
        'glpi_plugin_companypurchasing_policies',
        'glpi_plugin_companypurchasing_quote_items',
        'glpi_plugin_companypurchasing_quotes',
        'glpi_plugin_companypurchasing_doc_versions',
        'glpi_plugin_companypurchasing_docseq',
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

/**
 * Añade una columna a una tabla PROPIA si no existe (upgrade idempotente). Comprobación EN VIVO
 * (`SHOW COLUMNS`), independiente de la caché de esquema de GLPI. Sólo tablas `glpi_plugin_companypurchasing_*`.
 */
function plugin_companypurchasing_add_column_if_missing(string $table, string $column, string $ddl): void {
    /** @var DBmysql $DB */
    global $DB;
    if (preg_match('/^glpi_plugin_companypurchasing_[a-z_]+$/', $table) !== 1 || preg_match('/^[a-z_]+$/', $column) !== 1) {
        throw new \InvalidArgumentException('tabla/columna inválida para migración');
    }
    $res = $DB->doQuery("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
    if ($res !== false && $DB->numrows($res) > 0) {
        return;
    }
    $DB->doQuery("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$ddl}");
}

/**
 * Listener best-effort de `companyworkflow:transitioned` / `companyworkflow:approval_invalidated`:
 * PROYECTA el estado confirmado del motor en `requests.domain_state` (cache). Nunca lanza: la
 * transición ya está confirmada. Si este listener se pierde, la proyección converge en la siguiente
 * ejecución de la Acción automática NATIVA `reconcileprojection` (lotes con cursor y wrap-around: toda
 * solicitud se revisa en un número acotado de ejecuciones) o del comando `plugins:companypurchasing:reconcile`.
 *
 * @param mixed $payload
 * @return mixed
 */
function plugin_companypurchasing_on_workflow_event($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    try {
        if (\GlpiPlugin\Companypurchasing\Service\PluginConfig::syncOnWorkflowEvents()) {
            (new \GlpiPlugin\Companypurchasing\Service\StateProjection())->syncInstance((int) ($payload['instances_id'] ?? 0));
        }
    } catch (\Throwable) {
        // best-effort: la proyección nunca compromete la transición ya confirmada.
    }
    return $payload;
}
