<?php
/**
 * Hooks de ciclo de vida de Company QR (companyqr).
 *
 * install()/uninstall() usan MIGRACIONES REVERSIBLES con prefijo propio de tabla
 * (glpi_plugin_companyqr_*). NO se accede por SQL directo a tablas del core para
 * saltar reglas de negocio (ver CLAUDE.md, Prohibiciones). El único write sobre una
 * tabla core es otorgar el propio derecho del plugin al perfil Super-Admin en la
 * instalación (patrón estándar de plugins), vía la API ProfileRight cuando aplica.
 *
 * install() es idempotente y SEGURO EN UPGRADE (tablas IF-not-exists, derecho sólo si falta,
 * configuración sólo para claves ausentes). 0.3.0 no cambia el esquema: sólo agrega la API
 * pública `CompanyQrApi` (ADR-0022).
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\PluginConfig;

/**
 * Instalación: crea tablas propias, derechos y configuración por defecto.
 * @return boolean
 */
function plugin_companyqr_install() {
    /** @var DBmysql $DB */
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    if (!$DB->tableExists('glpi_plugin_companyqr_codes')) {
        $sql = "CREATE TABLE `glpi_plugin_companyqr_codes` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `itemtype` VARCHAR(100) NOT NULL,
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `is_recursive` TINYINT NOT NULL DEFAULT 0,
            `token` VARCHAR(64) NOT NULL,
            `public_code` VARCHAR(255) NOT NULL DEFAULT '',
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `revocation_reason` VARCHAR(255) DEFAULT NULL,
            `users_id_creation` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token` (`token`),
            UNIQUE KEY `public_code` (`public_code`),
            UNIQUE KEY `item` (`itemtype`,`items_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($sql);
    }

    if (!$DB->tableExists('glpi_plugin_companyqr_scans')) {
        $sql = "CREATE TABLE `glpi_plugin_companyqr_scans` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin_companyqr_codes_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(100) DEFAULT NULL,
            `items_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `public_code` VARCHAR(255) NOT NULL DEFAULT '',
            `date` TIMESTAMP NULL DEFAULT NULL,
            `result` VARCHAR(30) NOT NULL DEFAULT '',
            `channel` VARCHAR(30) NOT NULL DEFAULT 'qr',
            `is_anonymous` TINYINT NOT NULL DEFAULT 0,
            `actor_users_id` INT UNSIGNED DEFAULT NULL,
            `tickets_id` INT UNSIGNED DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `code` (`plugin_companyqr_codes_id`),
            KEY `result` (`result`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($sql);
    }

    // Derecho propio del plugin en todos los perfiles (valor 0 por defecto). install() es SEGURO EN UPGRADE (GLPI lo
    // vuelve a llamar al actualizar 0.2.0 → 0.3.0): el derecho se agrega sólo si FALTA (re-agregarlo viola el UNIQUE
    // (profiles_id, name) de glpi_profilerights y abortaría el upgrade) y, en ese primer alta, se otorgan todos los bits
    // al perfil Super-Admin (id 4 por defecto). En un upgrade los derechos ajustados por un administrador NO se tocan.
    if (class_exists('ProfileRight')
        && countElementsInTable(ProfileRight::getTable(), ['name' => Code::$rightname]) === 0) {
        ProfileRight::addProfileRights([Code::$rightname]);
        $full = READ | Code::RIGHT_GENERATE | Code::RIGHT_PRINT | Code::RIGHT_CONFIG;
        $DB->update(
            'glpi_profilerights',
            ['rights' => $full],
            ['profiles_id' => 4, 'name' => Code::$rightname]
        );
    }

    // Configuración por defecto (contexto plugin:companyqr). Sin secretos. Sólo se siembran las claves AUSENTES: un
    // upgrade no pisa lo que ajustó un administrador (medidas/colores de etiqueta, modo anónimo, prefijos…).
    $current = Config::getConfigurationValues(PluginConfig::CONTEXT);
    $missing = array_diff_key(PluginConfig::DEFAULTS, is_array($current) ? $current : []);
    if ($missing !== []) {
        Config::setConfigurationValues(PluginConfig::CONTEXT, $missing);
    }

    return true;
}

/**
 * Desinstalación: revierte de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companyqr_uninstall() {
    /** @var DBmysql $DB */
    global $DB;

    foreach (['glpi_plugin_companyqr_scans', 'glpi_plugin_companyqr_codes'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    if (class_exists('ProfileRight')) {
        ProfileRight::deleteProfileRights(['plugin_companyqr']);
    }

    Config::deleteConfigurationValues(PluginConfig::CONTEXT, array_keys(PluginConfig::DEFAULTS));

    return true;
}

// ---------------------------------------------------------------------------
//  Ciclo de vida del activo -> estado del código (guardas: sólo si hay código)
// ---------------------------------------------------------------------------

/** Botón "QR / etiqueta" en el formulario de un activo (hook post_item_form). */
function plugin_companyqr_post_item_form($params) {
    $item = is_array($params) ? ($params['item'] ?? null) : $params;
    if (!($item instanceof CommonDBTM) || $item->isNewItem()) {
        return;
    }
    // Sólo activos (con entities_id) y con permiso de imprimir.
    if (!isset($item->fields['entities_id'])
        || !Session::haveRight('plugin_companyqr', Code::RIGHT_PRINT)) {
        return;
    }

    $manager = new CodeManager();
    $code = $manager->findForItem($item);
    if ($code === null) {
        return; // aún no generado; la generación se hace desde la acción admin.
    }

    global $CFG_GLPI;
    $url = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/companyqr/label/' . (int) $code->getID();
    echo '<div class="companyqr-actions" style="margin:8px 0">'
        . '<a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="'
        . htmlspecialchars($url, ENT_QUOTES) . '">'
        . htmlspecialchars(__('Print QR label', 'companyqr'), ENT_QUOTES)
        . '</a> <span style="color:#6b7280">' . htmlspecialchars((string) $code->fields['public_code'], ENT_QUOTES) . '</span>'
        . '</div>';
}

function plugin_companyqr_item_update($item) {
    if (!($item instanceof CommonDBTM)) {
        return;
    }
    $manager = new CodeManager();
    $code = $manager->findForItem($item);
    if ($code !== null) {
        $manager->syncEntity($code, $item); // sigue a la nueva entidad si se movió.
    }
}

function plugin_companyqr_item_delete($item) {
    if (!($item instanceof CommonDBTM)) {
        return;
    }
    $manager = new CodeManager();
    $code = $manager->findForItem($item);
    if ($code !== null) {
        $manager->setStatus($code, Code::STATUS_SUSPENDED); // papelera -> suspendido
    }
}

function plugin_companyqr_item_restore($item) {
    if (!($item instanceof CommonDBTM)) {
        return;
    }
    $manager = new CodeManager();
    $code = $manager->findForItem($item);
    if ($code !== null && ($code->fields['status'] ?? '') === Code::STATUS_SUSPENDED) {
        $manager->setStatus($code, Code::STATUS_ACTIVE);
    }
}

function plugin_companyqr_item_purge($item) {
    if (!($item instanceof CommonDBTM)) {
        return;
    }
    $manager = new CodeManager();
    $code = $manager->findForItem($item);
    if ($code !== null) {
        // Activo eliminado definitivamente: código revocado; se conserva el historial.
        $manager->revoke($code, 'asset_purged');
    }
}
