<?php
/**
 * Hooks de ciclo de vida de Company Portal (companyportal).
 *
 * install()/uninstall() deben usar MIGRACIONES REVERSIBLES con prefijo
 * propio de tabla (glpi_plugin_companyportal_*). NO se accede por SQL directo a
 * tablas del core (ver CLAUDE.md, sección Prohibiciones).
 *
 * @license GPL-3.0-or-later
 */

/**
 * Instalación: crear tablas propias, perfiles/permisos, tareas cron, etc.
 * @return boolean
 */
function plugin_companyportal_install() {
    // global $DB;
    // $migration = new Migration(PLUGIN_COMPANYPORTAL_VERSION);
    // TODO(fase-modulo): crear esquema propio con prefijo glpi_plugin_companyportal_.
    // $migration->executeMigration();
    return true;
}

/**
 * Desinstalación: revertir de forma reversible lo creado en install().
 * @return boolean
 */
function plugin_companyportal_uninstall() {
    // global $DB;
    // TODO(fase-modulo): DROP de tablas glpi_plugin_companyportal_* y limpieza.
    return true;
}
