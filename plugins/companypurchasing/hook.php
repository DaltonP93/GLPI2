<?php
/**
 * Hooks de ciclo de vida de Company Purchasing (companypurchasing).
 *
 * install()/uninstall() deben usar MIGRACIONES REVERSIBLES con prefijo
 * propio de tabla (glpi_plugin_companypurchasing_*). NO se accede por SQL directo a
 * tablas del core (ver CLAUDE.md, sección Prohibiciones).
 *
 * @license GPL-3.0-or-later
 */

/**
 * Instalación: crear tablas propias, perfiles/permisos, tareas cron, etc.
 * @return boolean
 */
function plugin_companypurchasing_install() {
    // global $DB;
    // $migration = new Migration(PLUGIN_COMPANYPURCHASING_VERSION);
    // TODO(fase-modulo): crear esquema propio con prefijo glpi_plugin_companypurchasing_.
    // $migration->executeMigration();
    return true;
}

/**
 * Desinstalación: revertir de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companypurchasing_uninstall() {
    // global $DB;
    // TODO(fase-modulo): DROP de tablas glpi_plugin_companypurchasing_* y limpieza.
    return true;
}
